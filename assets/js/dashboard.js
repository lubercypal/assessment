let categories = [];
const sessionModal = document.querySelector('#sessionModal');
const sessionMount = document.querySelector('#sessionMount');
const sessionTitle = document.querySelector('#sessionTitle');
const sessionSubtitle = document.querySelector('#sessionSubtitle');
const closeSessionBtn = document.querySelector('#closeSession');
const assessmentSessionTemplate = document.querySelector('#assessmentSessionTemplate');
const dashboardShell = document.querySelector('#dashboardShell');
const sessionKeyboardWarning = document.querySelector('#sessionKeyboardWarning');
const fullscreenExitGuard = document.querySelector('#fullscreenExitGuard');
const returnFullscreenBtn = document.querySelector('#returnFullscreen');
const endSessionFromFullscreenBtn = document.querySelector('#endSessionFromFullscreen');
const fullscreenEndConfirm = document.querySelector('#fullscreenEndConfirm');
let activeSession = null;
let sessionWarningTimer = null;
let sessionIsOpen = false;
let intentionalFullscreenExit = false;

document.addEventListener('DOMContentLoaded', async () => {
    showLoader('Loading dashboard...');
    try {
        startSessionTimeout();

        categories = (await api('categories')).categories;
        const selects = document.querySelectorAll('[name="category_id"]');
        for (const select of selects) {
            select.innerHTML = '<option value="">Select subject</option>' + categories.map((item) => `<option value="${item.id}">${item.name}</option>`).join('');
        }

        document.querySelector('#logout').addEventListener('click', async () => {
            showLoader('Logging out...');
            await api('auth/logout', { method: 'POST', body: '{}' }).catch(() => {});
            clearToken();
            window.location.href = 'login';
        });

        closeSessionBtn?.addEventListener('click', closeSession);
        returnFullscreenBtn?.addEventListener('click', returnToFullscreen);
        fullscreenEndConfirm?.addEventListener('change', () => {
            if (endSessionFromFullscreenBtn) {
                endSessionFromFullscreenBtn.disabled = !fullscreenEndConfirm.checked;
            }
        });
        endSessionFromFullscreenBtn?.addEventListener('click', submitAndEndAfterFullscreenExit);
    } finally {
        hideLoader();
    }
});

document.querySelectorAll('[name="category_id"]').forEach((select) => {
    select.addEventListener('change', async () => {
        const form = select.closest('form');
        const topic = form.querySelector('[name="topic_id"]');
        topic.innerHTML = '<option value="">Any topic</option>';
        if (!select.value) return;
        showLoader('Loading topics...');
        try {
            const data = await api(`topics?category_id=${encodeURIComponent(select.value)}`);
            topic.innerHTML += data.topics.map((item) => `<option value="${item.id}">${item.name}</option>`).join('');
        } finally {
            hideLoader();
        }
    });
});

async function startAttempt(form, mode) {
    const formData = Object.fromEntries(new FormData(form).entries());
    if (mode === 'assessment' && !form.querySelector('[name="confirm_rules"]').checked) {
        showMessage('Please confirm the assessment rules.', 'error', '#assessmentMessage');
        return;
    }

    const payload = {
        category_id: formData.category_id,
        topic_id: formData.topic_id || null,
        mode,
    };

    openSessionShell(mode);
    try {
        const attempt = await api('attempts/start', { method: 'POST', body: JSON.stringify(payload) });
        mountAssessmentSession(attempt.attempt_id, mode);
    } catch (error) {
        closeSession();
        throw error;
    }
}

bindForm('#demoForm', async (_, form) => startAttempt(form, 'demo'));
bindForm('#assessmentForm', async (_, form) => startAttempt(form, 'assessment'));

function openSessionShell(mode) {
    if (!sessionModal || !sessionMount || !assessmentSessionTemplate) return;
    sessionTitle.textContent = mode === 'demo' ? 'Demo Session' : 'Assessment Session';
    sessionSubtitle.textContent = mode === 'demo'
        ? 'Sample questions with immediate feedback.'
        : 'Formal assessment in progress. Use mouse clicks only.';
    sessionIsOpen = true;
    intentionalFullscreenExit = false;
    hideFullscreenExitGuard();
    document.body.classList.add('session-open');
    dashboardShell?.setAttribute('inert', '');
    dashboardShell?.setAttribute('aria-hidden', 'true');
    sessionMount.innerHTML = '';
    sessionMount.appendChild(assessmentSessionTemplate.content.cloneNode(true));
    sessionMount.querySelector('#modeLabel').textContent = mode === 'demo' ? 'Demo Mode' : 'Assessment Mode';
    sessionMount.querySelector('#timer').textContent = '--:--';
    sessionMount.querySelector('#questionNumber').textContent = mode === 'demo' ? 'Preparing demo' : 'Preparing assessment';
    sessionMount.querySelector('#questionText').textContent = 'Preparing your session...';
    sessionMount.querySelector('#options').innerHTML = '<div class="session-inline-loader">Loading questions and timer...</div>';
    sessionMount.querySelector('#questionNav').innerHTML = '';
    sessionMount.querySelectorAll('button').forEach((button) => {
        button.disabled = true;
    });
    sessionModal.classList.remove('hidden');
    sessionModal.setAttribute('aria-hidden', 'false');
    sessionModal.setAttribute('tabindex', '-1');
    sessionModal.focus();

    requestSessionFullscreen();
}

function mountAssessmentSession(attemptId, mode) {
    if (!sessionModal || !sessionMount || !assessmentSessionTemplate || !window.AssessmentApp) {
        showSessionKeyboardWarning('Assessment session could not start. Please refresh and try again.');
        return;
    }

    activeSession = window.AssessmentApp.mount(sessionMount, {
        attemptId,
        mode,
        kioskMode: true,
        inlineResult: true,
        onExit: closeSession,
    });
}

function closeSession() {
    if (!sessionModal || !sessionMount) return;
    if (activeSession && typeof activeSession.destroy === 'function') {
        activeSession.destroy();
    }
    activeSession = null;
    sessionMount.innerHTML = '';
    sessionIsOpen = false;
    intentionalFullscreenExit = true;
    hideFullscreenExitGuard();
    sessionModal.classList.add('hidden');
    sessionModal.setAttribute('aria-hidden', 'true');
    sessionModal.removeAttribute('tabindex');
    dashboardShell?.removeAttribute('inert');
    dashboardShell?.removeAttribute('aria-hidden');
    document.body.classList.remove('session-open');
    if (document.fullscreenElement === sessionModal && document.exitFullscreen) {
        document.exitFullscreen().catch(() => {});
    }
    document.querySelector('#demoForm button[type="submit"]')?.focus();
}

window.addEventListener('keydown', (event) => {
    if (!sessionModal || sessionModal.classList.contains('hidden')) return;
    event.preventDefault();
    event.stopPropagation();
    showSessionKeyboardWarning(event.key === 'Escape'
        ? 'Escape can exit fullscreen. Use the on-screen buttons to continue safely.'
        : 'Keyboard input is disabled here. Please use mouse clicks only.');
}, true);

function showSessionKeyboardWarning(text = 'Keyboard input is disabled here. Please use mouse clicks only.') {
    if (!sessionKeyboardWarning) return;
    sessionKeyboardWarning.textContent = text;
    sessionKeyboardWarning.classList.remove('hidden');
    clearTimeout(sessionWarningTimer);
    sessionWarningTimer = setTimeout(() => {
        sessionKeyboardWarning.classList.add('hidden');
    }, 2200);
}

window.addEventListener('beforeunload', (event) => {
    if (!sessionIsOpen) return;
    event.preventDefault();
    event.returnValue = '';
});

document.addEventListener('fullscreenchange', () => {
    if (!sessionIsOpen || intentionalFullscreenExit) return;
    if (document.fullscreenElement) {
        hideFullscreenExitGuard();
        return;
    }
    showFullscreenExitGuard();
});

function requestSessionFullscreen() {
    if (!sessionModal?.requestFullscreen || document.fullscreenElement) {
        return Promise.resolve(Boolean(document.fullscreenElement));
    }

    return sessionModal.requestFullscreen()
        .then(() => true)
        .catch(() => {
            showSessionKeyboardWarning('Fullscreen could not start. Please use the Return to Fullscreen button.');
            return false;
        });
}

async function returnToFullscreen() {
    const restored = await requestSessionFullscreen();
    if (restored) {
        hideFullscreenExitGuard();
        sessionModal?.focus();
    }
}

function showFullscreenExitGuard() {
    if (!fullscreenExitGuard) return;
    showSessionKeyboardWarning('Fullscreen was exited. Please confirm how to continue.');
    fullscreenExitGuard.classList.remove('hidden');
    fullscreenExitGuard.setAttribute('aria-hidden', 'false');
    if (fullscreenEndConfirm) {
        fullscreenEndConfirm.checked = false;
    }
    if (endSessionFromFullscreenBtn) {
        endSessionFromFullscreenBtn.disabled = true;
        endSessionFromFullscreenBtn.textContent = 'Submit and End Test';
    }
    returnFullscreenBtn?.focus();
}

function hideFullscreenExitGuard() {
    if (!fullscreenExitGuard) return;
    fullscreenExitGuard.classList.add('hidden');
    fullscreenExitGuard.setAttribute('aria-hidden', 'true');
}

async function submitAndEndAfterFullscreenExit() {
    if (!fullscreenEndConfirm?.checked || !endSessionFromFullscreenBtn) return;
    endSessionFromFullscreenBtn.disabled = true;
    returnFullscreenBtn && (returnFullscreenBtn.disabled = true);
    endSessionFromFullscreenBtn.textContent = 'Submitting...';

    try {
        if (activeSession && typeof activeSession.submitAndEnd === 'function') {
            await activeSession.submitAndEnd();
            hideFullscreenExitGuard();
            showSessionKeyboardWarning('Attempt submitted. Review your result, then use Exit.');
        } else {
            closeSession();
        }
    } catch {
        showSessionKeyboardWarning('Unable to submit right now. Please return to fullscreen and try again.');
        endSessionFromFullscreenBtn.textContent = 'Submit and End Test';
        endSessionFromFullscreenBtn.disabled = false;
    } finally {
        returnFullscreenBtn && (returnFullscreenBtn.disabled = false);
    }
}
