<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Preferências de aparência do FitControl.">
    <title>Configurações | FitControl</title>
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
</head>
<body class="settings-body">
<main class="settings-page">
    <a class="settings-back" href="index.php">← Voltar ao painel</a>
    <span class="eyebrow">PREFERÊNCIAS DO SISTEMA</span>
    <h1>Configurações</h1>
    <p class="settings-intro">Personalize a aparência do FitControl. Sua escolha fica salva neste navegador.</p>

    <section class="settings-card" aria-labelledby="appearance-title">
        <div class="settings-card-heading">
            <span class="settings-icon" aria-hidden="true">◐</span>
            <div>
                <h2 id="appearance-title">Aparência</h2>
                <p>Escolha como o painel será exibido.</p>
            </div>
        </div>

        <div class="theme-options" role="group" aria-label="Tema da interface">
            <button class="theme-option" type="button" data-theme-choice="light" aria-pressed="false">
                <span class="theme-preview theme-preview-light" aria-hidden="true">
                    <span></span><span></span><span></span>
                </span>
                <strong>Claro</strong>
                <small>Visual tradicional</small>
            </button>
            <button class="theme-option" type="button" data-theme-choice="dark" aria-pressed="false">
                <span class="theme-preview theme-preview-dark" aria-hidden="true">
                    <span></span><span></span><span></span>
                </span>
                <strong>Escuro</strong>
                <small>Confortável em ambientes com pouca luz</small>
            </button>
        </div>
        <p class="settings-feedback" id="theme-feedback" role="status" aria-live="polite">A preferência é aplicada imediatamente.</p>
    </section>

    <footer class="settings-footer">FitControl · Configurações locais</footer>
</main>
</body>
</html>
