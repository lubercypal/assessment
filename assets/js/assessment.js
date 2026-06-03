const params = new URLSearchParams(window.location.search);
const attemptId = params.get('attempt');
let mode = params.get('mode') || 'assessment';
const kioskMode = params.get('kiosk') === '1';
let currentIndex = 0;
let totalQuestions = 0;
let currentQuestion = null;
let currentAttempt = null;
let tickHandle = null;
let remainingSeconds = 0;
const statuses = new Map();

document.addEventListener('DOMContentLoaded', async () => {
    showLoader('Loading assessment...');
    try {
        await requireAuth();
        if (!attemptId) {
            window.location.href = 'dashboard';
            return;
        }

        if (kioskMode) {
            enableKioskMode();
            trapHistory();
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
        remainingSeconds = Number(data.attempt.remaining_seconds || 0);

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
    const display = Math.max(0, remainingSeconds);
    document.querySelector('#timer').textContent = `${String(Math.floor(display / 60)).padStart(2, '0')}:${String(display % 60).padStart(2, '0')}`;
    if (remainingSeconds <= 0) {
        clearInterval(tickHandle);
        submitAttempt();
        return;
    }
    remainingSeconds -= 1;
}

async function submitAttempt() {
    showLoader('Submitting assessment...');
    try {
        await save('answered').catch(() => {});
        const result = await api(`attempts/${attemptId}/submit`, { method: 'POST', body: '{}' });
        sessionStorage.setItem(`result_${attemptId}`, JSON.stringify(result));
        window.location.replace(`result?attempt=${attemptId}&kiosk=1`);
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

function enableKioskMode() {
    const warning = document.createElement('div');
    warning.id = 'keyboardWarning';
    warning.className = 'keyboard-warning hidden';
    warning.textContent = 'Keyboard input is disabled here. Please use mouse clicks only.';
    document.body.appendChild(warning);

    let warningTimer = null;
    const showWarning = () => {
        warning.classList.remove('hidden');
        clearTimeout(warningTimer);
        warningTimer = setTimeout(() => warning.classList.add('hidden'), 2200);
    };

    document.addEventListener('keydown', (event) => {
        event.preventDefault();
        event.stopPropagation();
        document.activeElement?.blur?.();
        showWarning();
    }, true);

    document.addEventListener('keypress', (event) => {
        event.preventDefault();
        event.stopPropagation();
        document.activeElement?.blur?.();
    }, true);

    document.addEventListener('contextmenu', (event) => {
        event.preventDefault();
        showWarning();
    }, true);

    window.focus();
    document.documentElement.setAttribute('tabindex', '-1');
    document.documentElement.focus();
}

function trapHistory() {
    try {
        history.replaceState({ kiosk: true }, '', window.location.href);
        history.pushState({ kiosk: true }, '', window.location.href);
        window.addEventListener('popstate', () => {
            history.pushState({ kiosk: true }, '', window.location.href);
        });
    } catch {
        // no-op
    }
}
