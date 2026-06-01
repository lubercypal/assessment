let categories = [];

document.addEventListener('DOMContentLoaded', async () => {
    const session = await requireAuth();
    if (!session) return;
    document.querySelector('#studentName').textContent = session.user.full_name;

    categories = (await api('categories')).categories;
    const selects = document.querySelectorAll('[name="category_id"]');
    for (const select of selects) {
        select.innerHTML = '<option value="">Select subject</option>' + categories.map((item) => `<option value="${item.id}">${item.name}</option>`).join('');
    }

    document.querySelector('#logout').addEventListener('click', async () => {
        await api('auth/logout', { method: 'POST', body: '{}' }).catch(() => {});
        clearToken();
        window.location.href = 'login';
    });
});

document.querySelectorAll('[name="category_id"]').forEach((select) => {
    select.addEventListener('change', async () => {
        const form = select.closest('form');
        const topic = form.querySelector('[name="topic_id"]');
        topic.innerHTML = '<option value="">Any topic</option>';
        if (!select.value) return;
        const data = await api(`topics?category_id=${encodeURIComponent(select.value)}`);
        topic.innerHTML += data.topics.map((item) => `<option value="${item.id}">${item.name}</option>`).join('');
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
    const attempt = await api('attempts/start', { method: 'POST', body: JSON.stringify(payload) });
    window.location.href = `assessment?attempt=${attempt.attempt_id}&mode=${mode}`;
}

bindForm('#demoForm', async (_, form) => startAttempt(form, 'demo'));
bindForm('#assessmentForm', async (_, form) => startAttempt(form, 'assessment'));
