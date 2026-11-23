import { mkdir, readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import { chromium } from "playwright";

const baseUrl = process.env.BASE_URL ?? "http://127.0.0.1:8080";
const outputDir = fileURLToPath(new URL("../docs/screenshots/", import.meta.url));
const pageErrors = [];
const testEmail = "provision-ci@example.test";
let testPassword = process.env.FITCONTROL_TEST_PASSWORD;

async function autenticar(page) {
  const response = await page.goto(`${baseUrl}/login.php`, { waitUntil: "networkidle" });
  if (!response?.ok()) throw new Error("Não foi possível abrir a tela de login.");
  await page.getByLabel("E-mail").fill(testEmail);
  await page.getByLabel("Senha").fill(testPassword);
  await page.getByRole("button", { name: "Entrar no painel" }).click();
  await page.getByRole("heading", { name: "Visão geral" }).waitFor();
}

await mkdir(outputDir, { recursive: true });

const browser = await chromium.launch({ headless: true });

try {
  const desktop = await browser.newPage({
    viewport: { width: 1440, height: 1100 },
    deviceScaleFactor: 1,
  });

  desktop.on("pageerror", (error) => pageErrors.push(error.message));

  await autenticar(desktop);
  const dashboardResponse = await desktop.goto(`${baseUrl}/index.php`, {
    waitUntil: "networkidle",
  });

  if (!dashboardResponse?.ok()) {
    throw new Error(`Dashboard respondeu com HTTP ${dashboardResponse?.status() ?? "sem resposta"}.`);
  }

  await desktop.getByRole("heading", { name: "Visão geral" }).waitFor();
  const expectedDashboardMetrics = [
    "Alunos ativos",
    "Matrículas vigentes",
    "Matrículas terminando em até 7 dias",
    "Check-ins no período",
    "Alunos com check-in",
    "Recebido no período",
  ];
  for (const label of expectedDashboardMetrics) {
    await desktop.locator(".stat-label", { hasText: label }).first().waitFor();
  }
  const periodStart = await desktop.locator("#dashboard-de").inputValue();
  const periodEnd = await desktop.locator("#dashboard-ate").inputValue();
  if (!periodStart || !periodEnd || periodStart > periodEnd) {
    throw new Error("O filtro de período do dashboard não apresentou um intervalo válido.");
  }
  const reportLinks = await desktop.locator('.dashboard-metrics a[href*="relatorios.php"]').count();
  if (reportLinks < 6) {
    throw new Error(`Esperava links para os relatórios de origem dos cartões; encontrei ${reportLinks}.`);
  }
  const desktopRows = await desktop.locator(".students-table tbody tr").count();

  if (desktopRows < 8) {
    throw new Error(`Esperava 8 alunos de demonstração no dashboard; encontrei ${desktopRows}.`);
  }

  const desktopHasHorizontalOverflow = await desktop.evaluate(
    () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
  );

  if (desktopHasHorizontalOverflow) {
    throw new Error("O dashboard apresentou rolagem horizontal inesperada no desktop.");
  }

  await desktop.screenshot({
    path: `${outputDir}/dashboard-desktop.png`,
    fullPage: true,
    animations: "disabled",
  });

  // Os quatro relatórios devem exportar no mesmo período validado e sem PII.
  await desktop.goto(`${baseUrl}/relatorios.php?de=${periodStart}&ate=${periodEnd}`, {
    waitUntil: "networkidle",
  });
  await desktop.getByRole("heading", { name: "Relatórios" }).waitFor();
  const exportLinks = desktop.locator('.reports-export-links a[href*="exportar-relatorio.php"]');
  const expectedExports = ["operacional", "matriculas", "frequencia", "financeiro"];
  if (await exportLinks.count() !== expectedExports.length) {
    throw new Error("O perfil Administrador não recebeu os quatro links de exportação esperados.");
  }
  for (const reportType of expectedExports) {
    const link = desktop.locator(
      `.reports-export-links a[href*="tipo=${reportType}"]`,
    );
    if (await link.count() !== 1) {
      throw new Error(`Link CSV ausente ou duplicado para o relatório ${reportType}.`);
    }
    const href = await link.getAttribute("href");
    const target = new URL(href, baseUrl);
    if (target.searchParams.get("de") !== periodStart || target.searchParams.get("ate") !== periodEnd) {
      throw new Error(`O CSV ${reportType} não preservou o período selecionado.`);
    }

    const downloadPromise = desktop.waitForEvent("download");
    await link.click();
    const download = await downloadPromise;
    if (await download.failure()) {
      throw new Error(`Falha ao baixar o CSV ${reportType}.`);
    }
    const downloadedPath = await download.path();
    if (!downloadedPath) {
      throw new Error(`O navegador não disponibilizou o arquivo CSV ${reportType}.`);
    }
    const csvBytes = await readFile(downloadedPath);
    const csvText = csvBytes.toString("utf8");
    if (csvBytes.length < 3 || csvBytes[0] !== 0xef || csvBytes[1] !== 0xbb || csvBytes[2] !== 0xbf) {
      throw new Error(`O CSV ${reportType} não contém BOM UTF-8 esperado para planilhas.`);
    }
    if (/@example\\.test|\\b(?:telefone|email|e-mail)\\b/i.test(csvText)) {
      throw new Error(`O CSV ${reportType} aparenta conter dados pessoais.`);
    }
    if (!download.suggestedFilename().includes(reportType)) {
      throw new Error(`O nome do arquivo CSV não identifica o relatório ${reportType}.`);
    }
  }
  await desktop.goto(`${baseUrl}/index.php`, { waitUntil: "networkidle" });
  await desktop.getByRole("heading", { name: "Visão geral" }).waitFor();

  await desktop.getByRole("link", { name: /Ver detalhes de/ }).first().click();
  await desktop.getByRole("heading", { level: 1 }).waitFor();
  if (!desktop.url().includes("detalhes-aluno.php")) {
    throw new Error("O atalho de detalhes não abriu o perfil individual do aluno.");
  }
  await desktop.getByRole("heading", { name: "Informações cadastrais" }).waitFor();
  await desktop.getByRole("heading", { name: "Histórico de matrículas" }).waitFor();
  await desktop.getByRole("heading", { name: "Histórico de atividade" }).waitFor();
  await desktop.getByText("Cadastro criado").waitFor();
  await desktop.getByText("Dados cadastrais atualizados").waitFor();
  await desktop.getByText("Aluno inativado").waitFor();
  await desktop.getByText("Aluno reativado").waitFor();
  await desktop.screenshot({
    path: `${outputDir}/aluno-detalhes-desktop.png`,
    fullPage: true,
    animations: "disabled",
  });

  const mobile = await browser.newPage({
    viewport: { width: 390, height: 960 },
    deviceScaleFactor: 1,
    isMobile: true,
    hasTouch: true,
  });

  mobile.on("pageerror", (error) => pageErrors.push(error.message));

  await autenticar(mobile);
  const mobileResponse = await mobile.goto(`${baseUrl}/index.php`, {
    waitUntil: "networkidle",
  });

  if (!mobileResponse?.ok()) {
    throw new Error(`Dashboard mobile respondeu com HTTP ${mobileResponse?.status() ?? "sem resposta"}.`);
  }

  await mobile.getByRole("heading", { name: "Visão geral" }).waitFor();

  const mobileLayout = await mobile.locator(".table-scroll").evaluate((element) => ({
    viewportWidth: document.documentElement.clientWidth,
    documentWidth: document.documentElement.scrollWidth,
    tableViewportWidth: element.clientWidth,
    tableContentWidth: element.scrollWidth,
  }));

  if (mobileLayout.documentWidth > mobileLayout.viewportWidth) {
    throw new Error(
      `O dashboard mobile excedeu a largura da tela (${mobileLayout.documentWidth}px > ${mobileLayout.viewportWidth}px).`,
    );
  }

  if (mobileLayout.tableContentWidth <= mobileLayout.tableViewportWidth) {
    throw new Error("A tabela não apresentou a área de rolagem horizontal esperada no mobile.");
  }

  const mobileNav = mobile.locator(".main-nav");
  const navDimensions = await mobileNav.evaluate((element) => ({
    clientWidth: element.clientWidth, scrollWidth: element.scrollWidth,
  }));
  if (navDimensions.scrollWidth <= navDimensions.clientWidth) {
    throw new Error("A navegação mobile deveria indicar opções adicionais por rolagem horizontal.");
  }
  const navLinks = mobileNav.locator(".nav-link");
  const navLinkCount = await navLinks.count();
  await mobileNav.focus();
  for (let index = 0; index < navLinkCount; index += 1) {
    await mobile.keyboard.press("Tab");
  }
  const lastNavLinkFocused = await navLinks.last().evaluate((element) => document.activeElement === element);
  const navScrollAfterKeyboard = await mobileNav.evaluate((element) => element.scrollLeft);
  if (!lastNavLinkFocused || navScrollAfterKeyboard <= 0) {
    throw new Error("A navegação mobile não revelou o último atalho durante a navegação por teclado.");
  }
  await mobileNav.evaluate((element) => { element.scrollLeft = 0; });

  await mobile.screenshot({
    path: `${outputDir}/dashboard-mobile.png`,
    fullPage: true,
    animations: "disabled",
  });

  await mobile.locator(".students-panel").screenshot({
    path: `${outputDir}/alunos-mobile.png`,
    animations: "disabled",
  });

  await mobile.getByRole("link", { name: /Ver detalhes de/ }).first().click();
  await mobile.getByRole("heading", { name: "Informações cadastrais" }).waitFor();
  await mobile.getByText("Cadastro criado").waitFor();
  await mobile.getByText("Aluno reativado").waitFor();
  const detailMobileWidth = await mobile.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    document: document.documentElement.scrollWidth,
  }));
  if (detailMobileWidth.document > detailMobileWidth.viewport) {
    throw new Error("O perfil do aluno apresentou rolagem horizontal inesperada no mobile.");
  }
  await mobile.screenshot({
    path: `${outputDir}/aluno-detalhes-mobile.png`,
    fullPage: true,
    animations: "disabled",
  });

  await mobile.evaluate(() => localStorage.setItem("fitcontrol-theme", "dark"));
  await mobile.reload({ waitUntil: "networkidle" });
  const mobileDetailTheme = await mobile.locator("html").getAttribute("data-theme");
  if (mobileDetailTheme !== "dark") {
    throw new Error("O tema escuro não foi aplicado ao perfil do aluno no mobile.");
  }
  await mobile.getByText("Cadastro criado").waitFor();
  await mobile.screenshot({
    path: `${outputDir}/aluno-detalhes-mobile-escuro.png`,
    fullPage: true,
    animations: "disabled",
  });

  const form = await browser.newPage({
    viewport: { width: 1280, height: 900 },
    deviceScaleFactor: 1,
  });

  form.on("pageerror", (error) => pageErrors.push(error.message));

  await autenticar(form);
  const formResponse = await form.goto(`${baseUrl}/cadastro.php`, {
    waitUntil: "networkidle",
  });

  if (!formResponse?.ok()) {
    throw new Error(`Formulário respondeu com HTTP ${formResponse?.status() ?? "sem resposta"}.`);
  }

  await form.locator("#form-aluno").waitFor();
  await form.getByLabel("Nome completo:").waitFor();

  await form.screenshot({
    path: `${outputDir}/cadastro-desktop.png`,
    fullPage: true,
    animations: "disabled",
  });


  const settings = await browser.newPage({
    viewport: { width: 1280, height: 900 },
    deviceScaleFactor: 1,
  });

  settings.on("pageerror", (error) => pageErrors.push(error.message));

  await autenticar(settings);
  const settingsResponse = await settings.goto(`${baseUrl}/configuracoes.php`, {
    waitUntil: "networkidle",
  });

  if (!settingsResponse?.ok()) {
    throw new Error(`Configurações responderam com HTTP ${settingsResponse?.status() ?? "sem resposta"}.`);
  }

  await settings.getByRole("heading", { name: "Configurações" }).waitFor();
  await settings.getByRole("button", { name: /Escuro/ }).click();

  const selectedTheme = await settings.locator("html").getAttribute("data-theme");
  if (selectedTheme !== "dark") {
    throw new Error("A seleção do tema escuro não foi aplicada.");
  }

  await settings.screenshot({
    path: `${outputDir}/configuracoes-escuro.png`,
    fullPage: true,
    animations: "disabled",
  });

  await settings.goto(`${baseUrl}/index.php`, { waitUntil: "networkidle" });
  const dashboardTheme = await settings.locator("html").getAttribute("data-theme");
  if (dashboardTheme !== "dark") {
    throw new Error("A preferência de tema não persistiu ao navegar para o dashboard.");
  }

  await settings.screenshot({
    path: `${outputDir}/dashboard-escuro.png`,
    fullPage: true,
    animations: "disabled",
  });

  await settings.getByRole("link", { name: /Ver detalhes de/ }).first().click();
  await settings.getByRole("heading", { name: "Informações cadastrais" }).waitFor();
  const profileTheme = await settings.locator("html").getAttribute("data-theme");
  if (profileTheme !== "dark") {
    throw new Error("O tema escuro não persistiu ao abrir o perfil do aluno.");
  }
  await settings.getByText("Cadastro criado").waitFor();
  await settings.screenshot({
    path: `${outputDir}/aluno-detalhes-desktop-escuro.png`,
    fullPage: true,
    animations: "disabled",
  });

  const sessionWarningPage = await browser.newPage({
    viewport: { width: 1280, height: 900 },
    deviceScaleFactor: 1,
  });
  sessionWarningPage.on("pageerror", (error) => pageErrors.push(error.message));
  await autenticar(sessionWarningPage);
  await sessionWarningPage.clock.install();
  await sessionWarningPage.goto(baseUrl + "/index.php", { waitUntil: "networkidle" });

  const sessionWarning = sessionWarningPage.locator("#fitcontrol-session-warning");
  await sessionWarning.waitFor({ state: "attached" });
  if (!(await sessionWarning.isHidden())) {
    throw new Error("O aviso de sessão não deveria aparecer logo após o login.");
  }

  await sessionWarningPage.clock.fastForward(25 * 60 * 1000);
  await sessionWarningPage.getByRole("alertdialog").waitFor({ state: "visible" });
  await sessionWarningPage.getByRole("heading", { name: "Sua sessão está prestes a expirar" }).waitFor();
  await sessionWarningPage.getByRole("button", { name: "Continuar conectado" }).click();
  await sessionWarning.waitFor({ state: "hidden" });

  const absoluteWarningPage = await browser.newPage({
    viewport: { width: 1280, height: 900 },
    deviceScaleFactor: 1,
  });
  absoluteWarningPage.on("pageerror", (error) => pageErrors.push(error.message));
  await absoluteWarningPage.route("**/index.php", async (route) => {
    const response = await route.fetch();
    const html = await response.text();
    const adjustedHtml = html.replace(
      /name="fitcontrol-absolute-remaining" content="\d+"/,
      'name="fitcontrol-absolute-remaining" content="240"',
    );
    if (adjustedHtml === html) {
      throw new Error("Não foi possível preparar o cenário próximo do limite absoluto.");
    }
    await route.fulfill({ response, body: adjustedHtml });
  });
  await autenticar(absoluteWarningPage);
  await absoluteWarningPage.clock.install();
  await absoluteWarningPage.goto(baseUrl + "/index.php", { waitUntil: "networkidle" });
  await absoluteWarningPage.getByRole("heading", { name: "Seu tempo máximo de acesso está terminando" }).waitFor();
  await absoluteWarningPage.getByRole("button", { name: "Entendi" }).click();

  const passwordPage = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  passwordPage.on("pageerror", (error) => pageErrors.push(error.message));
  await autenticar(passwordPage);
  await passwordPage.goto(baseUrl + "/configuracoes.php", { waitUntil: "networkidle" });
  await passwordPage.getByRole("heading", { name: "Segurança da conta" }).waitFor();
  await passwordPage.getByRole("link", { name: "Alterar senha" }).click();
  await passwordPage.getByRole("heading", { name: "Alterar senha" }).waitFor();
  await passwordPage.getByLabel("Senha atual").waitFor();
  await passwordPage.screenshot({ path: outputDir + "/alterar-senha-desktop.png", fullPage: true, animations: "disabled" });

  const employeesPage = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
  employeesPage.on("pageerror", (error) => pageErrors.push(error.message));
  await autenticar(employeesPage);
  await employeesPage.goto(baseUrl + "/usuarios.php", { waitUntil: "networkidle" });
  await employeesPage.getByRole("heading", { name: "Funcionários" }).waitFor();
  await employeesPage.screenshot({ path: outputDir + "/funcionarios-desktop.png", fullPage: true, animations: "disabled" });

  if (pageErrors.length > 0) {
    throw new Error(`Erros JavaScript encontrados: ${pageErrors.join(" | ")}`);
  }

  console.log("Screenshots gerados:");
  console.log("- docs/screenshots/dashboard-desktop.png");
  console.log("- docs/screenshots/dashboard-mobile.png");
  console.log("- docs/screenshots/alunos-mobile.png");
  console.log("- docs/screenshots/cadastro-desktop.png");
  console.log("- docs/screenshots/configuracoes-escuro.png");
  console.log("- docs/screenshots/dashboard-escuro.png");
  console.log("- docs/screenshots/alterar-senha-desktop.png");
  console.log("- docs/screenshots/funcionarios-desktop.png");
  console.log("Validação visual automatizada: OK");
} finally {
  await browser.close();
}
