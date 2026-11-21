(() => {
    const csrfMeta = document.querySelector('meta[name="fitcontrol-csrf-token"]');
    const absoluteMeta = document.querySelector('meta[name="fitcontrol-absolute-remaining"]');
    if (!csrfMeta || !absoluteMeta || !csrfMeta.content) return;

    const csrfToken = csrfMeta.content;
    const absoluteSeconds = Number(absoluteMeta.content);
    if (!Number.isFinite(absoluteSeconds)) return;

    const INACTIVITY_LIMIT = 30 * 60 * 1000;
    const WARNING_BEFORE_IDLE = 5 * 60 * 1000;
    const WARNING_BEFORE_ABSOLUTE = 5 * 60 * 1000;
    const SERVER_SYNC_INTERVAL = 60 * 1000;

    let lastActivity = Date.now();
    let lastServerSync = Date.now();
    const absoluteDeadline = Date.now() + Math.max(0, absoluteSeconds) * 1000;
    let currentWarning = null;
    let absoluteWarningDismissed = false;
    let requestInProgress = false;
    let previousFocus = null;

    const overlay = document.createElement('div');
    overlay.id = 'fitcontrol-session-warning';
    overlay.className = 'session-warning-overlay';
    overlay.hidden = true;
    overlay.innerHTML = '<section class="session-warning-card" role="alertdialog" aria-modal="true" aria-labelledby="session-warning-title" aria-describedby="session-warning-message"><span class="session-warning-icon" aria-hidden="true">!</span><h2 id="session-warning-title"></h2><p id="session-warning-message" class="session-warning-message"></p><p class="session-warning-countdown">Tempo restante: <strong id="session-warning-countdown" aria-live="polite"></strong></p><p id="session-warning-status" class="session-warning-status" role="status" aria-live="polite"></p><button type="button" class="button button-primary" id="session-warning-action"></button></section>';
    document.body.appendChild(overlay);

    const title = overlay.querySelector('#session-warning-title');
    const message = overlay.querySelector('#session-warning-message');
    const countdown = overlay.querySelector('#session-warning-countdown');
    const status = overlay.querySelector('#session-warning-status');
    const action = overlay.querySelector('#session-warning-action');

    function formatTime(milliseconds) {
        const seconds = Math.max(0, Math.ceil(milliseconds / 1000));
        const minutes = Math.floor(seconds / 60);
        const remainder = seconds % 60;
        return String(minutes).padStart(2, '0') + ':' + String(remainder).padStart(2, '0');
    }

    function showWarning(type) {
        if (currentWarning === type) return;
        previousFocus = document.activeElement;
        currentWarning = type;
        status.textContent = '';

        if (type === 'absolute') {
            title.textContent = 'Seu tempo máximo de acesso está terminando';
            message.textContent = 'O limite de oito horas será atingido em breve. Salve suas alterações; será necessário entrar novamente.';
            action.textContent = 'Entendi';
        } else {
            title.textContent = 'Sua sessão está prestes a expirar';
            message.textContent = 'Por segurança, sua sessão será encerrada em cinco minutos. Confirme abaixo para continuar usando o FitControl.';
            action.textContent = 'Continuar conectado';
        }

        overlay.hidden = false;
        action.focus();
    }

    function hideWarning() {
        overlay.hidden = true;
        currentWarning = null;
        status.textContent = '';
        if (previousFocus && typeof previousFocus.focus === 'function') {
            previousFocus.focus();
        }
    }

    function redirectToLogin() {
        window.location.replace('login.php');
    }

    async function renewSession(manualConfirmation) {
        if (requestInProgress) return;
        requestInProgress = true;

        if (manualConfirmation) {
            action.disabled = true;
            status.textContent = 'Verificando sua sessão...';
        }

        try {
            const response = await fetch('renovar-sessao.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body: new URLSearchParams({ token: csrfToken })
            });

            if (response.status === 401) {
                redirectToLogin();
                return;
            }
            if (!response.ok) throw new Error('Não foi possível confirmar a sessão.');

            const result = await response.json();
            if (result.ok !== true) throw new Error('Resposta inválida ao renovar a sessão.');

            lastServerSync = Date.now();
            if (manualConfirmation) {
                lastActivity = Date.now();
                hideWarning();
            }
        } catch (error) {
            lastServerSync = 0;
            if (manualConfirmation) {
                status.textContent = 'Não foi possível confirmar sua sessão. Verifique a conexão e tente novamente.';
            }
        } finally {
            requestInProgress = false;
            if (manualConfirmation) action.disabled = false;
        }
    }

    function registerActivity(event) {
        if (currentWarning !== null || document.hidden) return;
        if (event && event.isTrusted === false) return;

        lastActivity = Date.now();
        if (Date.now() - lastServerSync >= SERVER_SYNC_INTERVAL) {
            void renewSession(false);
        }
    }

    function updateSessionStatus() {
        const now = Date.now();
        const idleRemaining = INACTIVITY_LIMIT - (now - lastActivity);
        const absoluteRemaining = absoluteDeadline - now;

        if (idleRemaining <= 0 || absoluteRemaining <= 0) {
            redirectToLogin();
            return;
        }

        if (absoluteRemaining <= WARNING_BEFORE_ABSOLUTE && !absoluteWarningDismissed) {
            showWarning('absolute');
        } else if (idleRemaining <= WARNING_BEFORE_IDLE) {
            showWarning('idle');
        } else if (currentWarning === 'idle') {
            hideWarning();
        }

        if (currentWarning === 'absolute') {
            countdown.textContent = formatTime(absoluteRemaining);
        } else if (currentWarning === 'idle') {
            countdown.textContent = formatTime(idleRemaining);
        }
    }

    action.addEventListener('click', () => {
        if (currentWarning === 'absolute') {
            absoluteWarningDismissed = true;
            hideWarning();
        } else if (currentWarning === 'idle') {
            void renewSession(true);
        }
    });

    overlay.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') event.preventDefault();
        if (event.key === 'Tab') {
            event.preventDefault();
            action.focus();
        }
    });

    ['pointerdown', 'keydown', 'touchstart', 'scroll', 'input', 'change'].forEach((eventName) => {
        document.addEventListener(eventName, registerActivity, { capture: true, passive: true });
    });

    document.addEventListener('visibilitychange', updateSessionStatus);
    updateSessionStatus();
    window.setInterval(updateSessionStatus, 1000);
})();