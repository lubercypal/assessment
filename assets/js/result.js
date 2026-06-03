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
            <p><strong>Final Score</strong><br>${summary.score}</p>
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
            return `<li>${escapeHtml(option.option_text)} ${tags ? `<strong>(${tags})</strong>` : ''}</li>`;
        }).join('');

        return `
            <article class="result-item">
                <h2>Question ${index + 1}</h2>
                <p>${escapeHtml(item.question.question_text)}</p>
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
