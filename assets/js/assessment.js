(() => {
    const defaultParams = new URLSearchParams(window.location.search);

    const AssessmentApp = {
        mount(root = document, options = {}) {
            const state = {
                attemptId: String(options.attemptId || defaultParams.get('attempt') || ''),
                mode: options.mode || defaultParams.get('mode') || 'assessment',
                kioskMode: options.kioskMode ?? defaultParams.get('kiosk') === '1',
                inlineResult: options.inlineResult ?? false,
                onExit: typeof options.onExit === 'function' ? options.onExit : null,
            };

            if (!state.attemptId) {
                window.location.href = 'dashboard';
                return { destroy() {} };
            }

            const query = (selector) => root.querySelector(selector);
            const listeners = [];
            const cleanup = [];
            const statusMap = new Map();
            let currentIndex = 0;
            let totalQuestions = 0;
            let currentQuestion = null;
            let currentAttempt = null;
            let tickHandle = null;
            let remainingSeconds = 0;
            let warningNode = null;
            let warningTimer = null;

            const on = (target, event, handler, options) => {
                target.addEventListener(event, handler, options);
                cleanup.push(() => target.removeEventListener(event, handler, options));
            };

            const ensureMounted = () => {
                if (!query('#questionText') || !query('#options')) {
                    throw new Error('Assessment session markup is missing.');
                }
            };

            const stopTimer = () => {
                if (tickHandle) {
                    clearInterval(tickHandle);
                    tickHandle = null;
                }
            };

            const showWarning = () => {
                if (!warningNode) return;
                warningNode.classList.remove('hidden');
                clearTimeout(warningTimer);
                warningTimer = setTimeout(() => warningNode?.classList.add('hidden'), 2200);
            };

            const enableKioskMode = () => {
                warningNode = document.createElement('div');
                warningNode.id = 'keyboardWarning';
                warningNode.className = 'keyboard-warning hidden';
                warningNode.textContent = 'Keyboard input is disabled here. Please use mouse clicks only.';
                document.body.appendChild(warningNode);

                on(document, 'keydown', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    document.activeElement?.blur?.();
                    showWarning();
                }, true);

                on(document, 'keypress', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    document.activeElement?.blur?.();
                }, true);

                on(document, 'contextmenu', (event) => {
                    event.preventDefault();
                    showWarning();
                }, true);

                on(window, 'focus', () => {
                    query('#questionText')?.focus?.();
                });

                if (root instanceof HTMLElement) {
                    root.setAttribute('tabindex', '-1');
                    root.focus();
                } else {
                    document.documentElement.setAttribute('tabindex', '-1');
                    document.documentElement.focus();
                }
            };

            const trapHistory = () => {
                try {
                    history.replaceState({ kiosk: true }, '', window.location.href);
                    history.pushState({ kiosk: true }, '', window.location.href);
                    on(window, 'popstate', () => {
                        history.pushState({ kiosk: true }, '', window.location.href);
                    });
                } catch {
                    // no-op
                }
            };

            const selectedOptionIds = () => [...querySelectorAll(root, '[name="option"]:checked')].map((item) => Number(item.value));

            function querySelectorAll(scope, selector) {
                return scope.querySelectorAll(selector);
            }

            async function loadQuestion(index) {
                showLoader('Loading question...');
                try {
                    const data = await api(`attempts/${state.attemptId}/question?index=${index}`);
                    currentIndex = data.index;
                    currentQuestion = data.question;
                    currentAttempt = data.attempt;
                    totalQuestions = data.attempt.total_questions;
                    state.mode = data.attempt.mode;
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
                query('#modeLabel').textContent = state.mode === 'demo' ? 'Demo Mode' : 'Assessment Mode';
                query('#questionNumber').textContent = `Question ${currentIndex + 1} of ${totalQuestions}`;
                query('#questionText').textContent = q.question_text;
                const feedback = query('#feedback');
                feedback.classList.add('hidden');
                feedback.innerHTML = '';

                const selected = new Set((data.response?.selected_option_ids || []).map(Number));
                statusMap.set(currentIndex, data.response?.status || statusMap.get(currentIndex) || 'skipped');

                const type = q.question_type === 'multi' ? 'checkbox' : 'radio';
                query('#options').innerHTML = q.options.map((option) => `
                    <label class="option">
                        <input type="${type}" name="option" value="${option.id}" ${selected.has(Number(option.id)) ? 'checked' : ''}>
                        <span>${escapeHtml(option.option_text)}</span>
                    </label>
                `).join('');

                query('#previous').disabled = currentIndex === 0;
                query('#next').disabled = currentIndex >= totalQuestions - 1;
            }

            async function save(status = 'answered') {
                if (!currentQuestion) return null;
                const selected = selectedOptionIds();
                const finalStatus = status === 'answered' && selected.length === 0 ? 'skipped' : status;
                showLoader('Saving answer...');
                let result;
                try {
                    result = await api(`attempts/${state.attemptId}/answer`, {
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
                statusMap.set(currentIndex, finalStatus);
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
                const nav = query('#questionNav');
                nav.innerHTML = '';
                for (let i = 0; i < totalQuestions; i++) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = String(i + 1);
                    button.className = [i === currentIndex ? 'active' : '', statusMap.get(i) || ''].join(' ');
                    button.addEventListener('click', async () => {
                        await save('answered');
                        await loadQuestion(i);
                    });
                    nav.appendChild(button);
                }
            }

            function renderFeedback(feedback) {
                const node = query('#feedback');
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
                stopTimer();
                updateTimer();
                tickHandle = setInterval(updateTimer, 1000);
            }

            function updateTimer() {
                const display = Math.max(0, remainingSeconds);
                query('#timer').textContent = `${String(Math.floor(display / 60)).padStart(2, '0')}:${String(display % 60).padStart(2, '0')}`;
                if (remainingSeconds <= 0) {
                    stopTimer();
                    submitAttempt();
                    return;
                }
                remainingSeconds -= 1;
            }

            function renderInlineResult(data) {
                const summary = data.summary;
                const responseCards = data.responses.map((item, index) => {
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

                if (!(root instanceof HTMLElement)) {
                    return;
                }

                root.innerHTML = `
                    <main class="page-shell session-result-shell">
                        <header class="topbar">
                            <div class="brand">Assessment Result</div>
                            <button id="inlineExitSession" type="button" class="secondary">Exit</button>
                        </header>
                        <section class="panel stack">
                            <h1>Final Submission</h1>
                            <div class="grid">
                                <p><strong>Total Questions</strong><br>${summary.total_questions}</p>
                                <p><strong>Attempted</strong><br>${summary.attempted}</p>
                                <p><strong>Not Attempted</strong><br>${summary.not_attempted}</p>
                                <p><strong>Marked for Review</strong><br>${summary.marked_for_review}</p>
                                <p><strong>Time Used</strong><br>${Math.floor(summary.time_used_seconds / 60)} min ${summary.time_used_seconds % 60} sec</p>
                                <p><strong>Final Score</strong><br>${summary.score}</p>
                            </div>
                            <div>${responseCards}</div>
                        </section>
                    </main>
                `;

                root.querySelector('#inlineExitSession')?.addEventListener('click', () => {
                    state.onExit?.();
                });
            }

            async function submitAttempt() {
                showLoader('Submitting assessment...');
                try {
                    await save('answered').catch(() => {});
                    const result = await api(`attempts/${state.attemptId}/submit`, { method: 'POST', body: '{}' });
                    sessionStorage.setItem(`result_${state.attemptId}`, JSON.stringify(result));
                    if (state.inlineResult) {
                        stopTimer();
                        renderInlineResult(result);
                    } else {
                        window.location.replace(`result?attempt=${state.attemptId}`);
                    }
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

            ensureMounted();

            if (state.kioskMode) {
                enableKioskMode();
                trapHistory();
            }

            on(query('#submitTest'), 'click', submitAttempt);
            on(query('#next'), 'click', () => saveAndMove(currentIndex + 1, 'answered'));
            on(query('#previous'), 'click', () => saveAndMove(currentIndex - 1, 'answered'));
            on(query('#skip'), 'click', () => saveAndMove(currentIndex + 1, 'skipped'));
            on(query('#review'), 'click', () => saveAndMove(currentIndex + 1, 'review'));

            (async () => {
                const session = await requireAuth();
                if (!session) return;
                if (root instanceof HTMLElement) {
                    root.dataset.userId = String(session.user.id);
                }
                await loadQuestion(0);
            })();

            return {
                destroy() {
                    stopTimer();
                    cleanup.forEach((fn) => fn());
                    if (warningNode) {
                        warningNode.remove();
                        warningNode = null;
                    }
                },
            };
        },
    };

    window.AssessmentApp = AssessmentApp;

    document.addEventListener('DOMContentLoaded', () => {
        if (document.querySelector('#questionText') && document.querySelector('#options')) {
            AssessmentApp.mount(document);
        }
    });
})();
