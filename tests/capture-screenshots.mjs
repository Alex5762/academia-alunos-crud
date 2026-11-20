import { mkdir } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import { chromium } from "playwright";

const baseUrl = process.env.BASE_URL ?? "http://127.0.0.1:8080";
const outputDir = fileURLToPath(new URL("../docs/screenshots/", import.meta.url));
const pageErrors = [];

await mkdir(outputDir, { recursive: true });

const browser = await chromium.launch({ headless: true });

try {
  const desktop = await browser.newPage({
    viewport: { width: 1440, height: 1100 },
    deviceScaleFactor: 1,
  });

  desktop.on("pageerror", (error) => pageErrors.push(error.message));

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

  if (pageErrors.length > 0) {
    throw new Error(`Erros JavaScript encontrados: ${pageErrors.join(" | ")}`);
  }

  console.log("Screenshots gerados:");
  console.log("- docs/screenshots/dashboard-desktop.png");
  console.log("- docs/screenshots/dashboard-mobile.png");
  console.log("- docs/screenshots/alunos-mobile.png");
  console.log("- docs/screenshots/cadastro-desktop.png");
  console.log("Validação visual automatizada: OK");
} finally {
  await browser.close();
}
