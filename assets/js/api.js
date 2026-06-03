function token() {
    return null;
}

function setToken(value) {
}

function clearToken() {
}

function cookieValue(name) {
    return document.cookie
        .split('; ')
        .find((row) => row.startsWith(`${name}=`))
        ?.split('=')
        .slice(1)
        .join('=') || '';
}

function getLoader() {
    return document.querySelector('#globalLoader');
}

function showLoader(text = 'Working...') {
    const loader = getLoader();
    if (!loader) return;
    const label = loader.querySelector('[data-loader-text]');
    if (label) {
        label.textContent = text;
    }
    loader.classList.remove('hidden');
}

function hideLoader() {
    const loader = getLoader();
    if (!loader) return;
    loader.classList.add('hidden');
}

const SESSION_IDLE_TIMEOUT_MS = 15 * 60 * 1000;
let sessionIdleTimer = null;
let sessionTimeoutBound = false;

function clearSessionTimeout() {
    if (sessionIdleTimer) {
        clearTimeout(sessionIdleTimer);
        sessionIdleTimer = null;
    }
}

function startSessionTimeout() {
    clearSessionTimeout();
    const reset = () => {
        clearSessionTimeout();
        sessionIdleTimer = setTimeout(logoutDueToTimeout, SESSION_IDLE_TIMEOUT_MS);
    };

    if (!sessionTimeoutBound) {
        ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'].forEach((eventName) => {
            document.addEventListener(eventName, reset, { passive: true, capture: true });
        });
        sessionTimeoutBound = true;
    }

    reset();
}

async function logoutDueToTimeout() {
    showLoader('Session timed out. Logging out...');
    try {
        await api('auth/logout', { method: 'POST', body: '{}' }).catch(() => {});
    } finally {
        clearToken();
        window.location.href = 'login?reason=timeout';
    }
}

function clearFieldErrors(form) {
    if (!form) return;
    form.querySelectorAll('.field-error').forEach((node) => node.remove());
    form.querySelectorAll('[aria-invalid="true"]').forEach((node) => node.removeAttribute('aria-invalid'));
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function showFieldErrors(form, details = {}) {
    if (!form || !details || typeof details !== 'object') return;

    Object.entries(details).forEach(([field, value]) => {
        if (field === '_form' || field === 'action' || field === 'retry_after_seconds') {
            return;
        }

        const input = form.querySelector(`[name="${CSS.escape(field)}"]`);
        if (!input) return;

        input.setAttribute('aria-invalid', 'true');
        const message = Array.isArray(value) ? value.join(' ') : String(value || '');
        const container = input.closest('.field') || input.parentElement;
        if (!container) return;

        const error = document.createElement('p');
        error.className = 'field-error';
        error.textContent = message;
        container.appendChild(error);
    });
}

function formMessageFromError(error) {
    if (!error) return 'Request failed.';
    if (error.status === 422 && error.details && Object.keys(error.details).length > 0) {
        return error.details._form || 'Please correct the highlighted fields.';
    }
    return error.message || 'Request failed.';
}

function renderErrorSummary(messageNode, error) {
    if (!messageNode || !error) return;

    const details = error.details || {};
    if (error.status === 429 && Number(details.retry_after_seconds || 0) > 0) {
        const seconds = Number(details.retry_after_seconds || 0);
        const tick = (node, remaining) => {
            const mins = Math.floor(remaining / 60);
            const secs = remaining % 60;
            node.textContent = `Too many login attempts. Please wait ${mins}m ${String(secs).padStart(2, '0')}s before trying again.`;
        };

        messageNode.innerHTML = '<span class="countdown-copy"></span>';
        const copy = messageNode.querySelector('.countdown-copy');
        if (copy) {
            let remaining = seconds;
            tick(copy, remaining);
            const timer = setInterval(() => {
                remaining -= 1;
                if (remaining <= 0) {
                    clearInterval(timer);
                    messageNode.textContent = 'You can try logging in again now.';
                    messageNode.className = 'message success form-alert';
                    messageNode.setAttribute('role', 'status');
                    return;
                }
                tick(copy, remaining);
            }, 1000);
        }
        messageNode.className = 'message error form-alert';
        messageNode.setAttribute('role', 'alert');
        return;
    }

    const fields = Object.entries(details)
        .filter(([key]) => !['_form', 'action', 'retry_after_seconds'].includes(key))
        .map(([, value]) => Array.isArray(value) ? value.join(' ') : String(value || ''))
        .filter(Boolean);

    if (error.status === 422 && (fields.length > 0 || details._form || details.action)) {
        const action = details.action || '';
        const email = encodeURIComponent(String(details.email || ''));
        const actionMap = {
            login: { label: 'Go to Login', href: 'login' },
            'verify-email': { label: 'Open Verification', href: email ? `verify-email?email=${email}&locked=1` : 'verify-email' },
            'forgot-password': { label: 'Request Reset Link', href: 'forgot-password' },
        };
        const recovery = actionMap[action];
        const helperText = action === 'resend-otp'
            ? 'Use the Re-send OTP button below to continue.'
            : action === 'forgot-password'
                ? 'Request a fresh password reset link to continue.'
                : 'Continue from the linked page.';
        const listItems = fields.map((item) => `<li>${escapeHtml(item)}</li>`).join('');
        messageNode.innerHTML = `
            <strong>${escapeHtml(error.details._form || 'Please correct the highlighted fields.')}</strong>
            ${fields.length > 0 ? `<ul class="form-alert-list">${listItems}</ul>` : ''}
            ${recovery ? `<div class="form-alert-cta"><a class="action-link" href="${escapeHtml(recovery.href)}">${escapeHtml(recovery.label)}</a><span>${escapeHtml(helperText)}</span></div>` : `<div class="form-alert-cta"><span>${escapeHtml(helperText)}</span></div>`}
        `;
        messageNode.className = 'message error form-alert';
        messageNode.setAttribute('role', 'alert');
        return;
    }

    messageNode.textContent = error.message || 'Request failed.';
    messageNode.className = 'message error form-alert';
    messageNode.setAttribute('role', 'alert');
}

async function api(route, options = {}) {
    const headers = {
        'Content-Type': 'application/json',
        ...(options.headers || {}),
    };
    const csrfToken = cookieValue('ASSESSMENT_CSRF');
    if (csrfToken && (options.method || 'GET').toUpperCase() !== 'GET') {
        headers['X-CSRF-Token'] = decodeURIComponent(csrfToken);
    }

    const response = await fetch(`/api/${route}`, {
        ...options,
        headers,
        credentials: 'same-origin',
    });
    const payload = await response.json().catch(() => ({ ok: false, error: { message: 'Invalid server response.' } }));

    if (!response.ok || !payload.ok) {
        const error = new Error(payload.error?.message || 'Request failed.');
        error.details = payload.error?.details || {};
        error.status = response.status;
        throw error;
    }

    return payload.data;
}

function bindForm(selector, handler) {
    const form = document.querySelector(selector);
    if (!form) return;
    const loader = form.dataset.loader ? document.querySelector(form.dataset.loader) : null;
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = form.querySelector('button[type="submit"]');
        const message = document.querySelector(form.dataset.message || '#message');
        const overlay = loader || getLoader();
        let succeeded = false;
        if (overlay) {
            overlay.classList.remove('hidden');
        }
        button && (button.disabled = true);
        clearFieldErrors(form);
        if (message) {
            message.className = 'message';
            message.textContent = 'Working...';
        }
        try {
            await handler(Object.fromEntries(new FormData(form).entries()), form);
            succeeded = true;
        } catch (error) {
            if (selector === '#loginForm' && error.status === 429 && typeof showLoginThrottleCountdown === 'function') {
                if (message) {
                    message.textContent = '';
                    message.className = 'message';
                }
                showLoginThrottleCountdown(error.details?.retry_after_seconds || 0);
                return;
            }
            renderErrorSummary(message, error);
            showFieldErrors(form, error.details);
        } finally {
            button && (button.disabled = false);
            if (overlay) {
                overlay.classList.add('hidden');
            }
            if (message && succeeded) {
                message.textContent = '';
                message.className = 'message';
            }
        }
    });
}

function showMessage(text, type = 'success', selector = '#message') {
    const node = document.querySelector(selector);
    if (!node) return;
    node.textContent = text;
    node.className = `message ${type}`;
}

async function requireAuth() {
    try {
        const session = await api('auth/me');
        startSessionTimeout();
        return session;
    } catch {
        clearToken();
        window.location.href = 'login';
        return null;
    }
}
