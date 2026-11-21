(() => {
    const storageKey = 'fitcontrol-theme';
    const validThemes = ['light', 'dark'];

    function applyTheme(theme) {
        const selected = validThemes.includes(theme) ? theme : 'light';
        document.documentElement.dataset.theme = selected;
        document.documentElement.style.colorScheme = selected;

        document.querySelectorAll('[data-theme-choice]').forEach((button) => {
            const active = button.dataset.themeChoice === selected;
            button.setAttribute('aria-pressed', String(active));
        });
    }

    let savedTheme = 'light';
    try {
        const stored = localStorage.getItem(storageKey);
        if (validThemes.includes(stored)) savedTheme = stored;
    } catch (error) {
        // O tema claro continua disponível se o navegador bloquear o armazenamento.
    }

    applyTheme(savedTheme);

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-theme-choice]').forEach((button) => {
            button.addEventListener('click', () => {
                const theme = button.dataset.themeChoice;
                applyTheme(theme);

                try {
                    localStorage.setItem(storageKey, theme);
                } catch (error) {
                    // A preferência vale durante a página atual mesmo sem armazenamento.
                }

                const feedback = document.querySelector('#theme-feedback');
                if (feedback) {
                    feedback.textContent = theme === 'dark'
                        ? 'Tema escuro ativado.'
                        : 'Tema claro ativado.';
                }
            });
        });
    });
})();
