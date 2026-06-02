const params = new URLSearchParams(window.location.search);
const attemptId = params.get('attempt');
let mode = params.get('mode') || 'assessment';
let currentIndex = 0;
let totalQuestions = 0;
let currentQuestion = null;
let currentAttempt = null;
let tickHandle = null;
const statuses = new Map();

document.addEventListener('DOMContentLoaded', async () => {
    showLoader('Loading assessment...');
    try {
        await requireAuth();
        if (!attemptId) {
            window.location.href = 'dashboard';
            return;
        }

        document.querySelector('#submitTest').addEventListener('click', submitAttempt);
        document.querySelector('#next').addEventListener('click', () => saveAndMove(currentIndex + 1, 'answered'));
        document.querySelector('#previous').addEventListener('click', () => saveAndMove(currentIndex - 1, 'answered'));
        document.querySelector('#skip').addEventListener('click', () => saveAndMove(currentIndex + 1, 'skipped'));
        document.querySelector('#review').addEventListener('click', () => saveAndMove(currentIndex + 1, 'review'));

        await loadQuestion(0);
    } finally {
        hideLoader();
    }
});

async function loadQuestion(index) {
    showLoader('Loading question...');
    try {
        const data = await api(`attempts/${attemptId}/question?index=${index}`);
        currentIndex = data.index;
        currentQuestion = data.question;
        currentAttempt = data.attempt;
        totalQuestions = data.attempt.total_questions;
        mode = data.attempt.mode;

        renderQuestion(data);
        renderNavigator();
        startTimer();
    } finally {
        hideLoader();
    }
}

function renderQuestion(data) {
    const q = data.question;
    document.querySelector('#modeLabel').textContent = mode === 'demo' ? 'Demo Mode' : 'Assessment Mode';
    document.querySelector('#questionNumber').textContent = `Question ${currentIndex + 1} of ${totalQuestions}`;
    document.querySelector('#questionText').textContent = q.question_text;
    document.querySelector('#feedback').classList.add('hidden');
    document.querySelector('#feedback').innerHTML = '';

    const selected = new Set((data.response?.selected_option_ids || []).map(Number));
    statuses.set(currentIndex, data.response?.status || statuses.get(currentIndex) || 'skipped');

    const type = q.question_type === 'multi' ? 'checkbox' : 'radio';
    document.querySelector('#options').innerHTML = q.options.map((option) => `
        <label class="option">
            <input type="${type}" name="option" value="${option.id}" ${selected.has(Number(option.id)) ? 'checked' : ''}>
            <span>${escapeHtml(option.option_text)}</span>
        </label>
    `).join('');

    document.querySelector('#previous').disabled = currentIndex === 0;
    document.querySelector('#next').disabled = currentIndex >= totalQuestions - 1;
}

function selectedOptionIds() {
    return [...document.querySelectorAll('[name="option"]:checked')].map((item) => Number(item.value));
}

async function save(status = 'answered') {
    if (!currentQuestion) return null;
    const selected = selectedOptionIds();
    const finalStatus = status === 'answered' && selected.length === 0 ? 'skipped' : status;
    showLoader('Saving answer...');
    let result;
    try {
        result = await api(`attempts/${attemptId}/answer`, {
            method: 'POST',
            body: JSON.stringify({
                question_id: currentQuestion.id,
                selected_option_ids: selected,
                status: finalStatus,
            }),
        });
    } finally {
        hideLoader();
    }
    statuses.set(currentIndex, finalStatus);
    renderNavigator();
    if (result.feedback) {
        renderFeedback(result.feedback);
    }
    return result;
}

async function saveAndMove(nextIndex, status) {
    await save(status);
    if (nextIndex >= 0 && nextIndex < totalQuestions) {
        await loadQuestion(nextIndex);
    }
}

function renderNavigator() {
    const nav = document.querySelector('#questionNav');
    nav.innerHTML = '';
    for (let i = 0; i < totalQuestions; i++) {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = String(i + 1);
        button.className = [i === currentIndex ? 'active' : '', statuses.get(i) || ''].join(' ');
        button.addEventListener('click', async () => {
            await save('answered');
            await loadQuestion(i);
        });
        nav.appendChild(button);
    }
}

function renderFeedback(feedback) {
    const node = document.querySelector('#feedback');
    const selected = feedback.selected_answers.map((item) => escapeHtml(item.option_text)).join(', ') || 'No answer selected';
    const correct = feedback.correct_answers.map((item) => escapeHtml(item.option_text)).join(', ');
    node.classList.remove('hidden');
    node.innerHTML = `
        <strong>${feedback.is_correct ? 'Correct' : 'Needs review'}</strong>
        <p>Selected answer: ${selected}</p>
        <p>Correct answer: ${correct}</p>
        <p>${escapeHtml(feedback.explanation || '')}</p>
    `;
}

function startTimer() {
    clearInterval(tickHandle);
    updateTimer();
    tickHandle = setInterval(updateTimer, 1000);
}

function updateTimer() {
    const expires = new Date(`${currentAttempt.expires_at.replace(' ', 'T')}+05:30`).getTime();
    const display = Math.max(0, Math.floor((expires - Date.now()) / 1000));
    document.querySelector('#timer').textContent = `${String(Math.floor(display / 60)).padStart(2, '0')}:${String(display % 60).padStart(2, '0')}`;
    if (display <= 0) {
        clearInterval(tickHandle);
        submitAttempt();
    }
}

async function submitAttempt() {
    showLoader('Submitting assessment...');
    try {
        await save('answered').catch(() => {});
        const result = await api(`attempts/${attemptId}/submit`, { method: 'POST', body: '{}' });
        sessionStorage.setItem(`result_${attemptId}`, JSON.stringify(result));
        window.location.href = `result?attempt=${attemptId}`;
    } finally {
        hideLoader();
    }
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    })[char]);
}
