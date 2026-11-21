import { chromium, firefox, webkit } from "playwright";

const baseUrl = process.env.BASE_URL ?? "http://127.0.0.1:8080";
const testEmail = "provision-ci@example.test";
const testPassword = process.env.FITCONTROL_TEST_PASSWORD;

if (!testPassword) {
  throw new Error("FITCONTROL_TEST_PASSWORD não foi disponibilizada para os testes.");
}

const engines = [
  { name: "Chromium", type: chromium },
  { name: "Firefox", type: firefox },
  { name: "WebKit", type: webkit },
];

function ensure(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

async function authenticate(page, engineName) {
  const response = await page.goto(baseUrl + "/login.php", { waitUntil: "domcontentloaded" });
  ensure(response?.ok(), engineName + ": a tela de login não abriu.");

  await page.getByLabel("E-mail").fill(testEmail);
  await page.getByLabel("Senha").fill(testPassword);
  await page.getByRole("button", { name: "Entrar no painel" }).click();
  await page.getByRole("heading", { name: "Visão geral" }).waitFor({ timeout: 15000 });
}

async function assertNoDocumentOverflow(page, engineName, viewportLabel) {
  const dimensions = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    document: document.documentElement.scrollWidth,
  }));

  ensure(
    dimensions.document <= dimensions.viewport,
    engineName + ": há rolagem horizontal global em " + viewportLabel +
      " (" + dimensions.document + "px > " + dimensions.viewport + "px).",
  );
}

for (const engine of engines) {
  const browser = await engine.type.launch({ headless: true });
  const pageErrors = [];

  try {
    const page = await browser.newPage({
      viewport: { width: 1440, height: 1000 },
      deviceScaleFactor: 1,
    });

    page.on("pageerror", (error) => pageErrors.push(error.message));

    await authenticate(page, engine.name);

    const dashboardResponse = await page.goto(baseUrl + "/index.php", {
      waitUntil: "domcontentloaded",
    });
    ensure(dashboardResponse?.ok(), engine.name + ": o dashboard não abriu.");

    await page.getByRole("heading", { name: "Visão geral" }).waitFor();
    const rowCount = await page.locator(".students-table tbody tr").count();
    ensure(rowCount >= 8, engine.name + ": os oito registros fictícios não foram exibidos.");

    await assertNoDocumentOverflow(page, engine.name, "desktop");

    await page.setViewportSize({ width: 390, height: 844 });
    await assertNoDocumentOverflow(page, engine.name, "mobile de 390px");

    const tableLayout = await page.locator(".table-scroll").evaluate((element) => ({
      viewport: element.clientWidth,
      content: element.scrollWidth,
    }));
    ensure(
      tableLayout.content > tableLayout.viewport,
      engine.name + ": a tabela não disponibilizou rolagem horizontal interna no mobile.",
    );

    const nav = page.locator(".main-nav");
    const navLayout = await nav.evaluate((element) => ({
      viewport: element.clientWidth,
      content: element.scrollWidth,
    }));
    ensure(
      navLayout.content > navLayout.viewport,
      engine.name + ": a navegação não apresenta rolagem horizontal em 390px.",
    );

    const navLinks = nav.locator(".nav-link");
    const navLinkCount = await navLinks.count();
    await nav.focus();

    for (let index = 0; index < navLinkCount; index += 1) {
      await page.keyboard.press("Tab");
    }

    const lastLinkFocused = await navLinks.last().evaluate(
      (element) => document.activeElement === element,
    );
    await page.waitForFunction(() => {
      const element = document.querySelector(".main-nav");
      return Boolean(element && element.scrollLeft > 0);
    }, { timeout: 3000 }).catch(() => null);

    const navScrollAfterKeyboard = await nav.evaluate((element) => element.scrollLeft);
    ensure(
      lastLinkFocused && navScrollAfterKeyboard > 0,
      engine.name + ": a navegação por teclado não revelou o último atalho no mobile.",
    );

    await page.setViewportSize({ width: 320, height: 780 });
    await assertNoDocumentOverflow(page, engine.name, "mobile estreito de 320px");

    await page.setViewportSize({ width: 1280, height: 900 });
    const formResponse = await page.goto(baseUrl + "/cadastro.php", {
      waitUntil: "domcontentloaded",
    });
    ensure(formResponse?.ok(), engine.name + ": o formulário de cadastro não abriu.");

    await page.locator("#form-aluno").waitFor();

    const requiredInputs = await page.locator(
      "#form-aluno input[required], #form-aluno select[required]",
    ).evaluateAll((elements) => elements.map((element) => ({
      id: element.id,
      required: element.required,
      valid: element.checkValidity(),
      validationMessage: element.validationMessage,
    })));

    const requiredTextFields = ["nome", "telefone", "email"];
    for (const fieldId of requiredTextFields) {
      const field = requiredInputs.find((item) => item.id === fieldId);
      ensure(
        field && field.required && !field.valid && field.validationMessage.length > 0,
        engine.name + ": o campo obrigatório vazio #" + fieldId +
          " não foi bloqueado pela validação nativa do navegador.",
      );
    }

    await page.keyboard.press("Tab");
    await page.keyboard.press("Tab");

    const focusState = await page.evaluate(() => {
      const element = document.activeElement;
      if (!element) return null;
      const style = window.getComputedStyle(element);
      return {
        id: element.id,
        visible: element.matches(":focus-visible"),
        outlineStyle: style.outlineStyle,
        outlineWidth: style.outlineWidth,
      };
    });

    ensure(
      focusState &&
        focusState.id === "nome" &&
        focusState.visible &&
        focusState.outlineStyle !== "none" &&
        parseFloat(focusState.outlineWidth) >= 2,
      engine.name + ": o primeiro campo não apresenta foco visível ao navegar com Tab.",
    );

    const settingsResponse = await page.goto(baseUrl + "/configuracoes.php", {
      waitUntil: "domcontentloaded",
    });
    ensure(settingsResponse?.ok(), engine.name + ": a tela de configurações não abriu.");

    await page.getByRole("button", { name: /Escuro/ }).click();
    const darkTheme = await page.locator("html").getAttribute("data-theme");
    ensure(darkTheme === "dark", engine.name + ": o tema escuro não foi aplicado.");

    await page.goto(baseUrl + "/index.php", { waitUntil: "domcontentloaded" });
    const persistedTheme = await page.locator("html").getAttribute("data-theme");
    ensure(persistedTheme === "dark", engine.name + ": a preferência de tema não persistiu.");

    ensure(
      pageErrors.length === 0,
      engine.name + ": erros JavaScript: " + pageErrors.join(" | "),
    );

    console.log(
      engine.name +
        ": OK — login, dashboard, larguras desktop/mobile, rolagem interna, teclado, " +
        "foco visível, campos obrigatórios e tema escuro.",
    );
  } finally {
    await browser.close();
  }
}

console.log("Compatibilidade de navegadores: OK (Chromium, Firefox e WebKit).");
