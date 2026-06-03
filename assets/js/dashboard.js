let categories = [];
const sessionModal = document.querySelector('#sessionModal');
const sessionMount = document.querySelector('#sessionMount');
const sessionTitle = document.querySelector('#sessionTitle');
const sessionSubtitle = document.querySelector('#sessionSubtitle');
const closeSessionBtn = document.querySelector('#closeSession');
const assessmentSessionTemplate = document.querySelector('#assessmentSessionTemplate');
let activeSession = null;

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
    showLoader(mode === 'demo' ? 'Starting demo...' : 'Starting assessment...');
    try {
        const attempt = await api('attempts/start', { method: 'POST', body: JSON.stringify(payload) });
        openSession(attempt.attempt_id, mode);
    } catch (error) {
        hideLoader();
        throw error;
    }
}

bindForm('#demoForm', async (_, form) => startAttempt(form, 'demo'));
bindForm('#assessmentForm', async (_, form) => startAttempt(form, 'assessment'));

function openSession(attemptId, mode) {
    if (!sessionModal || !sessionMount || !assessmentSessionTemplate || !window.AssessmentApp) return;
    sessionTitle.textContent = mode === 'demo' ? 'Demo Session' : 'Assessment Session';
    sessionSubtitle.textContent = mode === 'demo'
        ? 'Sample questions with immediate feedback.'
        : 'Formal assessment in progress. Use mouse clicks only.';
    document.body.classList.add('session-open');
    sessionMount.innerHTML = '';
    sessionMount.appendChild(assessmentSessionTemplate.content.cloneNode(true));
    sessionModal.classList.remove('hidden');
    sessionModal.setAttribute('aria-hidden', 'false');
    sessionModal.setAttribute('tabindex', '-1');
    sessionModal.focus();
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
    sessionModal.classList.add('hidden');
    sessionModal.setAttribute('aria-hidden', 'true');
    sessionModal.removeAttribute('tabindex');
    document.body.classList.remove('session-open');
    closeSessionBtn?.focus();
}

window.addEventListener('keydown', (event) => {
    if (!sessionModal || sessionModal.classList.contains('hidden')) return;
    event.preventDefault();
    event.stopPropagation();
    showMessage('Keyboard input is disabled here. Please use mouse clicks only.', 'error', '#assessmentMessage');
}, true);

window.addEventListener('message', (event) => {
    if (!event.data || typeof event.data !== 'object') return;
    if (event.data.type === 'session-close') {
        closeSession();
    }
}, false);
