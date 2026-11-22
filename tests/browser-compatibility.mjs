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
    const attendanceResponse = await page.goto(baseUrl + "/checkins.php", {
      waitUntil: "domcontentloaded",
    });
    ensure(attendanceResponse?.ok(), engine.name + ": a página de frequência não abriu.");
    await page.getByRole("heading", { name: "Frequência" }).waitFor();
    await page.getByRole("heading", { name: "Registrar entrada" }).waitFor();
    await page.getByRole("heading", { name: "Histórico de presenças" }).waitFor();
    await page.locator("#checkin-de").waitFor();
    await page.locator("#checkin-ate").waitFor();
    await assertNoDocumentOverflow(page, engine.name, "frequência desktop");

    await page.setViewportSize({ width: 390, height: 844 });
    await assertNoDocumentOverflow(page, engine.name, "frequência mobile de 390px");
    await page.setViewportSize({ width: 320, height: 780 });
    await assertNoDocumentOverflow(page, engine.name, "frequência mobile estreito de 320px");

    await page.setViewportSize({ width: 1280, height: 900 });
    const financeResponse = await page.goto(baseUrl + "/financeiro.php", {
      waitUntil: "domcontentloaded",
    });
    ensure(financeResponse?.ok(), engine.name + ": a página financeira não abriu.");
    await page.getByRole("heading", { name: "Financeiro" }).waitFor();
    await page.getByRole("heading", { name: "Cobranças" }).waitFor();
    await page.getByText("Nenhuma cobrança registrada").waitFor();
    await assertNoDocumentOverflow(page, engine.name, "financeiro desktop");
    await page.setViewportSize({ width: 390, height: 844 });
    await assertNoDocumentOverflow(page, engine.name, "financeiro mobile de 390px");
    await page.setViewportSize({ width: 320, height: 780 });
    await assertNoDocumentOverflow(page, engine.name, "financeiro mobile estreito de 320px");

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

    let nomeFocused = false;
    for (let attempt = 0; attempt < 12; attempt += 1) {
      await page.keyboard.press("Tab");
      nomeFocused = await page.locator("#nome").evaluate(
        (element) => document.activeElement === element,
      );
      if (nomeFocused) break;
    }

    const focusState = await page.locator("#nome").evaluate((element) => {
      const style = window.getComputedStyle(element);
      return {
        focused: document.activeElement === element,
        outlineStyle: style.outlineStyle,
        outlineWidth: style.outlineWidth,
      };
    });

    ensure(
      focusState &&
        focusState.focused &&
        focusState.outlineStyle !== "none" &&
        parseFloat(focusState.outlineWidth) >= 2,
      engine.name + ": o primeiro campo não apresenta contorno de foco visível ao navegar com Tab. " +
        JSON.stringify(focusState),
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

    // Estados vazios da listagem: filtro sem inativos e pesquisa sem correspondência.
    await page.goto(baseUrl + "/index.php?status=inativos", { waitUntil: "domcontentloaded" });
    await page.getByRole("heading", { name: "Nenhum aluno inativo" }).waitFor();
    await page.getByRole("link", { name: "Ver todos os alunos" }).waitFor();

    await page.goto(
      baseUrl + "/index.php?status=ativos&busca=fitcontrol-sem-correspondencia-987654",
      { waitUntil: "domcontentloaded" },
    );
    await page.getByRole("heading", { name: "Nenhum aluno encontrado" }).waitFor();
    await page.getByRole("link", { name: "Limpar pesquisa" }).waitFor();

    await page.goto(baseUrl + "/index.php", { waitUntil: "domcontentloaded" });

    // Perfil sem eventos: valida o estado vazio e a semântica da página em todos os motores.
    await page.getByRole("link", { name: "Ver detalhes de Ana Beatriz Costa" }).click();
    await page.getByRole("heading", { name: "Informações cadastrais" }).waitFor();
    await page.getByRole("heading", { name: "Histórico de matrículas" }).waitFor();
    await page.getByRole("heading", { name: "Histórico de atividade" }).waitFor();
    const profileHeadings = page.locator("main.student-detail-page");
    ensure(
      await profileHeadings.locator("h1").count() === 1 &&
        await profileHeadings.locator("h2").count() === 3,
      engine.name + ": hierarquia de títulos dentro do perfil está inesperada.",
    );
    ensure(
      await page.locator('a[href^="mailto:"]').count() === 1 &&
        await page.locator('a[href^="tel:"]').count() === 1,
      engine.name + ": os contatos do aluno não estão expostos como links apropriados.",
    );
    ensure(
      await page.getByText("Nenhum evento de auditoria disponível para este cadastro.").count() === 1,
      engine.name + ": o estado vazio do histórico não foi apresentado.",
    );
    ensure(
      await page.getByRole("button", { name: "Inativar aluno" }).count() === 1,
      engine.name + ": a ação de inativação não tem nome acessível esperado.",
    );

    await page.keyboard.press("Tab");
    const backLinkFocus = await page.locator(".settings-back").evaluate((element) => {
      const style = window.getComputedStyle(element);
      return {
        focused: document.activeElement === element,
        outlineStyle: style.outlineStyle,
        outlineWidth: style.outlineWidth,
      };
    });
    ensure(
      backLinkFocus.focused &&
        backLinkFocus.outlineStyle !== "none" &&
        parseFloat(backLinkFocus.outlineWidth) >= 2,
      engine.name + ": o link de retorno não apresenta foco visível por teclado. " +
        JSON.stringify(backLinkFocus),
    );

    await page.setViewportSize({ width: 390, height: 844 });
    await assertNoDocumentOverflow(page, engine.name, "perfil mobile de 390px");
    await page.setViewportSize({ width: 320, height: 780 });
    await assertNoDocumentOverflow(page, engine.name, "perfil mobile estreito de 320px");

    // Perfil com linha do tempo: confere os quatro tipos de evento exibidos ao usuário.
    await page.goto(baseUrl + "/index.php", { waitUntil: "domcontentloaded" });
    await page.getByRole("link", { name: "Ver detalhes de Henrique Rocha" }).click();
    await page.getByRole("heading", { name: "Histórico de atividade" }).waitFor();
    const historyEvents = await page.locator(".student-timeline > li").count();
    ensure(
      historyEvents === 4,
      engine.name + ": o perfil com auditoria deveria exibir quatro eventos; encontrou " + historyEvents + ".",
    );
    for (const label of [
      "Cadastro criado",
      "Dados cadastrais atualizados",
      "Aluno inativado",
      "Aluno reativado",
    ]) {
      ensure(
        await page.getByText(label, { exact: true }).count() === 1,
        engine.name + ": o histórico não exibiu o evento " + label + ".",
      );
    }
    const populatedTheme = await page.locator("html").getAttribute("data-theme");
    ensure(populatedTheme === "dark", engine.name + ": o tema escuro não persistiu ao abrir o perfil com histórico.");

    ensure(
      pageErrors.length === 0,
      engine.name + ": erros JavaScript: " + pageErrors.join(" | "),
    );

    console.log(
      engine.name +
        ": OK — login, dashboard, larguras desktop/mobile, rolagem interna, teclado, " +
        "foco visível, campos obrigatórios, tema escuro, estados vazios da listagem, perfil vazio/preenchido, histórico e overflow mobile.",
    );
  } finally {
    await browser.close();
  }
}

console.log("Compatibilidade de navegadores: OK (Chromium, Firefox e WebKit).");
