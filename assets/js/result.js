const resultParams = new URLSearchParams(window.location.search);
const resultAttemptId = resultParams.get('attempt');
const resultKioskMode = resultParams.get('kiosk') === '1';

document.addEventListener('DOMContentLoaded', async () => {
    showLoader('Loading result...');
    try {
        await requireAuth();
        if (resultKioskMode) {
            enableKioskMode();
            trapHistory();
            const backLink = document.querySelector('#resultBackLink');
            if (backLink) {
                backLink.classList.add('hidden');
            }
        }
        const data = await api(`attempts/${resultAttemptId}/result`);
        renderResult(data);
    } finally {
        hideLoader();
    }
});

function renderResult(data) {
    const summary = data.summary;
    document.querySelector('#summary').innerHTML = `
        <div class="grid">
            <p><strong>Total Questions</strong><br>${summary.total_questions}</p>
            <p><strong>Attempted</strong><br>${summary.attempted}</p>
            <p><strong>Not Attempted</strong><br>${summary.not_attempted}</p>
            <p><strong>Marked for Review</strong><br>${summary.marked_for_review}</p>
            <p><strong>Time Used</strong><br>${Math.floor(summary.time_used_seconds / 60)} min ${summary.time_used_seconds % 60} sec</p>
            <p><strong>Final Score</strong><br>${summary.score} / ${summary.max_score}</p>
        </div>
    `;

    document.querySelector('#responses').innerHTML = data.responses.map((item, index) => {
        const correct = new Set(item.feedback.correct_option_ids.map(Number));
        const selected = new Set((item.response?.selected_option_ids || []).map(Number));
        const options = item.question.options.map((option) => {
            const tags = [
                correct.has(Number(option.id)) ? 'Correct' : '',
                selected.has(Number(option.id)) ? 'Selected' : '',
            ].filter(Boolean).join(' / ');
            return `
                <li>
                    ${option.option_text ? escapeHtml(option.option_text) : ''}
                    ${mediaMarkup(option.option_image, option.option_text || `Option ${option.option_key || ''}`)}
                    ${tags ? `<strong>(${tags})</strong>` : ''}
                </li>
            `;
        }).join('');

        return `
            <article class="result-item">
                <h2>Question ${index + 1}</h2>
                ${item.question.passage_text || item.question.passage_image ? `
                    <section class="question-passage">
                        <div class="question-passage__label">Reference passage</div>
                        ${item.question.passage_text ? `<p>${escapeHtml(item.question.passage_text)}</p>` : ''}
                        ${mediaMarkup(item.question.passage_image, 'Reference material')}
                    </section>
                ` : ''}
                <p>${escapeHtml(item.question.question_text)}</p>
                ${mediaMarkup(item.question.question_image, 'Question illustration')}
                <ul>${options}</ul>
                <p><strong>Explanation:</strong> ${escapeHtml(item.question.explanation || '')}</p>
            </article>
        `;
    }).join('');
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

function safeMediaPath(path) {
    const value = String(path || '');
    return /^assets\/question-media\/[A-Za-z0-9._/-]+\.webp$/i.test(value) ? value : '';
}

function mediaMarkup(path, alt) {
    const safePath = safeMediaPath(path);
    if (!safePath) return '';
    return `<img class="question-media" src="${escapeHtml(safePath)}" alt="${escapeHtml(alt)}">`;
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
