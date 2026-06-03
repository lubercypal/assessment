let categories = [];
const sessionModal = document.querySelector('#sessionModal');
const sessionFrame = document.querySelector('#sessionFrame');
const sessionTitle = document.querySelector('#sessionTitle');
const sessionSubtitle = document.querySelector('#sessionSubtitle');
const closeSessionBtn = document.querySelector('#closeSession');

document.addEventListener('DOMContentLoaded', async () => {
    showLoader('Loading dashboard...');
    try {
        const session = await requireAuth();
        if (!session) return;
        document.querySelector('#studentName').textContent = session.user.full_name;

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
    if (!sessionModal || !sessionFrame) return;
    sessionTitle.textContent = mode === 'demo' ? 'Demo Session' : 'Assessment Session';
    sessionSubtitle.textContent = mode === 'demo'
        ? 'Sample questions with immediate feedback.'
        : 'Formal assessment in progress. Use mouse clicks only.';
    sessionFrame.src = `assessment?attempt=${attemptId}&mode=${mode}&kiosk=1`;
    sessionModal.classList.remove('hidden');
    sessionModal.setAttribute('aria-hidden', 'false');
}

function closeSession() {
    if (!sessionModal || !sessionFrame) return;
    sessionFrame.src = 'about:blank';
    sessionModal.classList.add('hidden');
    sessionModal.setAttribute('aria-hidden', 'true');
}

window.addEventListener('keydown', (event) => {
    if (!sessionModal || sessionModal.classList.contains('hidden')) return;
    if (event.key === 'Escape') return;
    event.preventDefault();
    showMessage('Keyboard input is disabled here. Please use mouse clicks only.', 'error', '#assessmentMessage');
}, true);
