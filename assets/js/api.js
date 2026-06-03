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

function clearFieldErrors(form) {
    if (!form) return;
    form.querySelectorAll('.field-error').forEach((node) => node.remove());
    form.querySelectorAll('[aria-invalid="true"]').forEach((node) => node.removeAttribute('aria-invalid'));
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
        } catch (error) {
            if (message) {
                message.textContent = formMessageFromError(error);
                message.className = 'message error';
            }
            showFieldErrors(form, error.details);
        } finally {
            button && (button.disabled = false);
            if (overlay) {
                overlay.classList.add('hidden');
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
        return await api('auth/me');
    } catch {
        clearToken();
        window.location.href = 'login';
        return null;
    }
}
