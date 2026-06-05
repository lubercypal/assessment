function otpKey(email) {
    return `otp_sent_at:${String(email || '').toLowerCase()}`;
}

function startCooldown(button, seconds) {
    if (!button) return;
    let remaining = seconds;
    const baseLabel = button.dataset.baseLabel || button.textContent.trim() || 'Re-send OTP';
    button.disabled = true;
    button.textContent = `${baseLabel} (${remaining}s)`;
    const timer = setInterval(() => {
        remaining -= 1;
        if (remaining <= 0) {
            clearInterval(timer);
            button.disabled = false;
            button.textContent = baseLabel;
            return;
        }
        button.textContent = `${baseLabel} (${remaining}s)`;
    }, 1000);
}

function readQuery(name) {
    return new URLSearchParams(window.location.search).get(name) || '';
}

function showPageNotice() {
    const notice = readQuery('notice');
    const message = document.querySelector('#message');
    const loginForm = document.querySelector('#loginForm');
    const forgotForm = document.querySelector('#forgotForm');
    if (!notice || !message || (!loginForm && !forgotForm)) return;

    const notices = {
        already_verified: 'This email is already verified. Please log in.',
        verify_later: 'Your account is not verified yet. You can verify it now or later from the verification page.',
        reset_link_required: 'Please request a new password reset link to continue.',
    };

    if (notices[notice]) {
        message.textContent = notices[notice];
        message.className = 'message error';
    }
}

let loginThrottleTimer = null;

function clearLoginThrottleCountdown() {
    if (loginThrottleTimer) {
        clearInterval(loginThrottleTimer);
        loginThrottleTimer = null;
    }
}

function showLoginThrottleCountdown(seconds) {
    const node = document.querySelector('#throttleMessage');
    if (!node) return;

    clearLoginThrottleCountdown();
    let remaining = Math.max(0, Number(seconds) || 450);
    if (remaining <= 0) {
        node.textContent = 'Too many login attempts. Please wait 7m 30s before trying again.';
        node.className = 'message error form-alert';
        node.setAttribute('role', 'alert');
        return;
    }

    const render = () => {
        const mins = Math.floor(remaining / 60);
        const secs = remaining % 60;
        node.textContent = `Too many login attempts. Please wait ${mins}m ${String(secs).padStart(2, '0')}s before trying again.`;
        node.className = 'message error form-alert';
        node.setAttribute('role', 'alert');
    };

    render();
    loginThrottleTimer = setInterval(() => {
        remaining -= 1;
        if (remaining <= 0) {
            clearLoginThrottleCountdown();
            node.textContent = 'You can try logging in again now.';
            node.className = 'message success form-alert';
            node.setAttribute('role', 'status');
            return;
        }
        render();
    }, 1000);
}

bindForm('#registerForm', async (data) => {
    data.terms = document.querySelector('#terms').checked ? '1' : '';
    try {
        const result = await api('auth/register', { method: 'POST', body: JSON.stringify(data) });
        const nextEmail = result.email || data.email;
        sessionStorage.setItem(otpKey(nextEmail), String(Date.now()));
        window.location.href = `verify-email?email=${encodeURIComponent(nextEmail)}&locked=1`;
    } catch (error) {
        if (error.details?.action === 'login') {
            window.location.href = 'login?notice=already_verified';
            return;
        }
        if (error.details?.action === 'verify-email') {
            const nextEmail = error.details?.email || data.email;
            sessionStorage.setItem(otpKey(nextEmail), String(Date.now()));
            window.location.href = `verify-email?email=${encodeURIComponent(nextEmail)}&locked=1`;
            return;
        }
        throw error;
    }
});

bindForm('#otpForm', async (data) => {
    await api('auth/verify-email', { method: 'POST', body: JSON.stringify(data) });
    window.location.href = 'login';
});

const sendOtpBtn = document.querySelector('#sendOtpBtn');
if (sendOtpBtn) {
    const emailInput = document.querySelector('#otpEmail');
    sendOtpBtn.dataset.baseLabel = 'Re-send OTP';
    const syncCooldown = () => {
        const email = (emailInput?.value || '').trim().toLowerCase();
        if (!email) {
            sendOtpBtn.disabled = true;
            sendOtpBtn.textContent = sendOtpBtn.dataset.baseLabel;
            return;
        }

        const sentAt = Number(sessionStorage.getItem(otpKey(email)) || 0);
        const elapsed = sentAt ? Math.floor((Date.now() - sentAt) / 1000) : 0;
        const remaining = Math.max(0, 45 - elapsed);
        if (remaining > 0) {
            startCooldown(sendOtpBtn, remaining);
        } else {
            sendOtpBtn.disabled = false;
            sendOtpBtn.textContent = sendOtpBtn.dataset.baseLabel;
        }
    };

    emailInput?.addEventListener('input', syncCooldown);
    syncCooldown();

    sendOtpBtn.addEventListener('click', async () => {
        const email = (emailInput?.value || '').trim();
        if (!email) {
            showMessage('Enter your email first.', 'error');
            return;
        }

        showLoader('Sending OTP...');
        sendOtpBtn.disabled = true;
        try {
            await api('auth/resend-otp', {
                method: 'POST',
                body: JSON.stringify({ email }),
            });
            sessionStorage.setItem(otpKey(email), String(Date.now()));
            startCooldown(sendOtpBtn, 45);
            showMessage('OTP sent. Check your email.');
        } catch (error) {
            const wait = Number(error.details?.retry_after_seconds || 0);
            if (wait > 0) {
                sessionStorage.setItem(otpKey(email), String(Date.now() - ((45 - wait) * 1000)));
                startCooldown(sendOtpBtn, wait);
            } else {
                sendOtpBtn.disabled = false;
            }
            showMessage(error.message, 'error');
        } finally {
            hideLoader();
        }
    });
}

    bindForm('#loginForm', async (data) => {
    try {
        clearLoginThrottleCountdown();
        await api('auth/login', { method: 'POST', body: JSON.stringify(data) });
        window.location.href = 'dashboard';
    } catch (error) {
        if (error.status === 429) {
            showLoginThrottleCountdown(error.details?.retry_after_seconds || 450);
            return;
        }
        throw error;
    }
});

bindForm('#forgotForm', async (data) => {
    const result = await api('auth/forgot-password', { method: 'POST', body: JSON.stringify(data) });
    showMessage(result.message || 'Password reset link sent successfully. Please check your email.');
});

bindForm('#resetForm', async (data) => {
    await api('auth/reset-password', { method: 'POST', body: JSON.stringify(data) });
    showMessage('Password updated. Redirecting to login...');
    setTimeout(() => window.location.href = 'login', 800);
});

showPageNotice();
