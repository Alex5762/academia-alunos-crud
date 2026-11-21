import { mkdir } from "node:fs/promises";
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

  await mobile.screenshot({
    path: `${outputDir}/dashboard-mobile.png`,
    fullPage: true,
    animations: "disabled",
  });

  await mobile.locator(".students-panel").screenshot({
    path: `${outputDir}/alunos-mobile.png`,
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

  const sessionWarningPage = await browser.newPage({
    viewport: { width: 1280, height: 900 },
    deviceScaleFactor: 1,
  });
  sessionWarningPage.on("pageerror", (error) => pageErrors.push(error.message));
  await autenticar(sessionWarningPage);
  await sessionWarningPage.clock.install();
  await sessionWarningPage.goto(baseUrl + "/index.php", { waitUntil: "networkidle" });

  const sessionWarning = sessionWarningPage.locator("#fitcontrol-session-warning");
  await sessionWarning.waitFor();
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
  console.log("Validação visual automatizada: OK");
} finally {
  await browser.close();
}
