(() => {
    const defaultParams = new URLSearchParams(window.location.search);

    const AssessmentApp = {
        mount(root = document, options = {}) {
            const state = {
                attemptId: String(options.attemptId || defaultParams.get('attempt') || ''),
                mode: options.mode || defaultParams.get('mode') || 'assessment',
                kioskMode: options.kioskMode ?? defaultParams.get('kiosk') === '1',
                inlineResult: options.inlineResult ?? false,
                useGlobalLoader: options.useGlobalLoader ?? !(root instanceof HTMLElement),
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
            let ownsWarningNode = false;
            let warningTimer = null;
            let isSubmitting = false;

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

            const showBusy = (text) => {
                if (state.useGlobalLoader) {
                    showLoader(text);
                }
            };

            const hideBusy = () => {
                if (state.useGlobalLoader) {
                    hideLoader();
                }
            };

            const showWarning = () => {
                if (!warningNode) return;
                warningNode.classList.remove('hidden');
                clearTimeout(warningTimer);
                warningTimer = setTimeout(() => warningNode?.classList.add('hidden'), 2200);
            };

            const enableKioskMode = () => {
                warningNode = document.querySelector('#sessionKeyboardWarning');
                if (!warningNode) {
                    warningNode = document.createElement('div');
                    warningNode.id = 'keyboardWarning';
                    warningNode.className = 'keyboard-warning hidden';
                    warningNode.textContent = 'Keyboard input is disabled here. Please use mouse clicks only.';
                    document.body.appendChild(warningNode);
                    ownsWarningNode = true;
                }

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

                ['copy', 'cut', 'paste', 'selectstart', 'dragstart'].forEach((eventName) => {
                    on(document, eventName, (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        showWarning();
                    }, true);
                });

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

            function renderProtectedText(node, text) {
                if (!node) return;

                node.classList.add('protected-text');
                node.replaceChildren();

                const style = window.getComputedStyle(node);
                const fontSize = Number.parseFloat(style.fontSize) || 18;
                const fontWeight = style.fontWeight || '700';
                const fontFamily = style.fontFamily || 'Arial, sans-serif';
                const lineHeight = Number.parseFloat(style.lineHeight) || Math.ceil(fontSize * 1.35);
                const color = style.color || '#172033';
                const parent = node.parentElement;
                const parentWidth = parent?.clientWidth || 0;
                const inputWidth = parent?.querySelector('input')?.getBoundingClientRect?.().width || 0;
                const width = Math.max(180, Math.floor(node.clientWidth || parentWidth - inputWidth - 18 || 720));
                const ratio = Math.min(window.devicePixelRatio || 1, 2);
                const canvas = document.createElement('canvas');
                const context = canvas.getContext('2d');

                context.font = `${fontWeight} ${fontSize}px ${fontFamily}`;
                const lines = wrapCanvasText(context, String(text || ''), width);
                const height = Math.max(lineHeight, Math.ceil(lines.length * lineHeight));

                canvas.width = Math.ceil(width * ratio);
                canvas.height = Math.ceil(height * ratio);
                canvas.style.width = `${width}px`;
                canvas.style.height = `${height}px`;
                canvas.className = 'protected-text-canvas';
                canvas.setAttribute('aria-hidden', 'true');

                context.scale(ratio, ratio);
                context.font = `${fontWeight} ${fontSize}px ${fontFamily}`;
                context.fillStyle = color;
                context.textBaseline = 'top';
                lines.forEach((line, index) => {
                    context.fillText(line, 0, index * lineHeight);
                });

                node.appendChild(canvas);
            }

            function wrapCanvasText(context, text, maxWidth) {
                const lines = [];
                const paragraphs = String(text).split(/\r?\n/);

                paragraphs.forEach((paragraph) => {
                    const words = paragraph.trim().split(/\s+/).filter(Boolean);
                    let line = '';

                    words.forEach((word) => {
                        const nextLine = line ? `${line} ${word}` : word;
                        if (context.measureText(nextLine).width <= maxWidth) {
                            line = nextLine;
                            return;
                        }

                        if (line) {
                            lines.push(line);
                            line = '';
                        }

                        splitLongWord(context, word, maxWidth).forEach((part, index, parts) => {
                            if (index === parts.length - 1) {
                                line = part;
                            } else {
                                lines.push(part);
                            }
                        });
                    });

                    lines.push(line || ' ');
                });

                return lines;
            }

            function splitLongWord(context, word, maxWidth) {
                const parts = [];
                let part = '';

                [...word].forEach((char) => {
                    const nextPart = `${part}${char}`;
                    if (context.measureText(nextPart).width <= maxWidth || !part) {
                        part = nextPart;
                    } else {
                        parts.push(part);
                        part = char;
                    }
                });

                if (part) {
                    parts.push(part);
                }

                return parts;
            }

            async function loadQuestion(index) {
                showBusy('Loading question...');
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
                    hideBusy();
                }
            }

            function renderQuestion(data) {
                const q = data.question;
                query('#modeLabel').textContent = state.mode === 'demo' ? 'Demo Mode' : 'Assessment Mode';
                query('#questionNumber').textContent = `Question ${currentIndex + 1} of ${totalQuestions}`;
                renderProtectedText(query('#questionText'), q.question_text);
                const feedback = query('#feedback');
                feedback.classList.add('hidden');
                feedback.innerHTML = '';

                const selected = new Set((data.response?.selected_option_ids || []).map(Number));
                statusMap.set(currentIndex, data.response?.status || statusMap.get(currentIndex) || 'skipped');

                const type = q.question_type === 'multi' ? 'checkbox' : 'radio';
                const optionsNode = query('#options');
                optionsNode.innerHTML = '';
                q.options.forEach((option) => {
                    const label = document.createElement('label');
                    const input = document.createElement('input');
                    const copy = document.createElement('span');

                    label.className = 'option';
                    input.type = type;
                    input.name = 'option';
                    input.value = String(option.id);
                    input.checked = selected.has(Number(option.id));
                    label.append(input, copy);
                    optionsNode.appendChild(label);
                    renderProtectedText(copy, option.option_text);
                });

                query('#previous').disabled = currentIndex === 0;
                query('#next').disabled = currentIndex >= totalQuestions - 1;
                query('#skip').disabled = false;
                query('#review').disabled = false;
                query('#submitTest').disabled = false;
            }

            async function save(status = 'answered') {
                if (!currentQuestion) return null;
                const selected = selectedOptionIds();
                const finalStatus = status === 'answered' && selected.length === 0 ? 'skipped' : status;
                showBusy('Saving answer...');
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
                    hideBusy();
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
                const selected = feedback.selected_answers.map((item) => item.option_text).join(', ') || 'No answer selected';
                const correct = feedback.correct_answers.map((item) => item.option_text).join(', ');
                node.classList.remove('hidden');
                node.innerHTML = `
                    <strong>${feedback.is_correct ? 'Correct' : 'Needs review'}</strong>
                    <p>Selected answer:</p>
                    <div class="feedback-answer-copy" data-feedback-copy="selected"></div>
                    <p>Correct answer:</p>
                    <div class="feedback-answer-copy" data-feedback-copy="correct"></div>
                    <p>${escapeHtml(feedback.explanation || '')}</p>
                `;
                renderProtectedText(node.querySelector('[data-feedback-copy="selected"]'), selected);
                renderProtectedText(node.querySelector('[data-feedback-copy="correct"]'), correct);
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
                    const options = item.question.options.map((option, optionIndex) => {
                        const tags = [
                            correct.has(Number(option.id)) ? 'Correct' : '',
                            selected.has(Number(option.id)) ? 'Selected' : '',
                        ].filter(Boolean).join(' / ');
                        return `<li><span class="result-option-copy" data-response-index="${index}" data-option-index="${optionIndex}"></span>${tags ? `<strong>(${tags})</strong>` : ''}</li>`;
                    }).join('');

                    return `
                        <article class="result-item">
                            <h2>Question ${index + 1}</h2>
                            <div class="result-question-copy" data-response-index="${index}"></div>
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

                data.responses.forEach((item, index) => {
                    renderProtectedText(root.querySelector(`.result-question-copy[data-response-index="${index}"]`), item.question.question_text);
                    item.question.options.forEach((option, optionIndex) => {
                        renderProtectedText(root.querySelector(`.result-option-copy[data-response-index="${index}"][data-option-index="${optionIndex}"]`), option.option_text);
                    });
                });
            }

            async function submitAttempt() {
                if (isSubmitting) return null;
                isSubmitting = true;
                showBusy('Submitting assessment...');
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
                    return result;
                } finally {
                    isSubmitting = false;
                    hideBusy();
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
                    clearTimeout(warningTimer);
                    cleanup.forEach((fn) => fn());
                    if (warningNode) {
                        if (ownsWarningNode) {
                            warningNode.remove();
                        } else {
                            warningNode.classList.add('hidden');
                        }
                        warningNode = null;
                    }
                },
                submitAndEnd() {
                    return submitAttempt();
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
