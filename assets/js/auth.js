function otpKey(email) {
    return `otp_sent_at:${String(email || '').toLowerCase()}`;
}

function startCooldown(button, seconds) {
    if (!button) return;
    stopCooldown(button);
    let remaining = seconds;
    const baseLabel = button.dataset.baseLabel || button.textContent.trim() || 'Re-send OTP';
    button.disabled = true;
    button.textContent = `${baseLabel} (${remaining}s)`;
    button.cooldownTimer = setInterval(() => {
        remaining -= 1;
        if (remaining <= 0) {
            clearInterval(button.cooldownTimer);
            button.cooldownTimer = null;
            button.disabled = false;
            button.textContent = baseLabel;
            return;
        }
        button.textContent = `${baseLabel} (${remaining}s)`;
    }, 1000);
}

function stopCooldown(button) {
    if (!button?.cooldownTimer) return;
    clearInterval(button.cooldownTimer);
    button.cooldownTimer = null;
}

function normalizeIndianMobileDigits(value) {
    const raw = String(value || '').trim();
    const hasIndiaPrefix = raw.startsWith('+91') || raw.startsWith('91');
    let digits = raw.replace(/\D+/g, '');
    if (!hasIndiaPrefix && digits.length > 10) {
        return digits;
    }
    if (digits.length === 12 && digits.startsWith('91')) {
        digits = digits.slice(2);
    }
    if (digits.length === 11 && digits.startsWith('0')) {
        digits = digits.slice(1);
    }
    return digits.slice(0, 10);
}

function isValidEmail(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value || '').trim());
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
    data.mobile_number = normalizeIndianMobileDigits(data.mobile_number);
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

const registerMobileInput = document.querySelector('#registerForm [name="mobile_number"]');
if (registerMobileInput) {
    registerMobileInput.addEventListener('blur', () => {
        const mobileWarning = 'Enter exactly 10 digits, or include +91 for an Indian mobile number with country code.';
        const normalized = normalizeIndianMobileDigits(registerMobileInput.value);
        registerMobileInput.value = normalized;
        const rawDigits = String(normalized || '').replace(/\D+/g, '');
        const startsWithIndiaCode = String(registerMobileInput.value || '').trim().startsWith('+91')
            || String(registerMobileInput.value || '').trim().startsWith('91');
        if (!startsWithIndiaCode && rawDigits.length > 10) {
            showMessage(mobileWarning, 'error');
            return;
        }

        const message = document.querySelector('#message');
        if (message?.textContent === mobileWarning) {
            message.textContent = '';
            message.className = 'message';
        }
    });
}

bindForm('#otpForm', async (data) => {
    data.otp = String(data.otp || '').replace(/\D+/g, '').slice(0, 6);
    if (!/^\d{6}$/.test(data.otp)) {
        throw Object.assign(new Error('Enter the 6-digit OTP.'), {
            status: 422,
            details: { otp: 'OTP must be exactly 6 digits.' },
        });
    }
    await api('auth/verify-email', { method: 'POST', body: JSON.stringify(data) });
    sessionStorage.removeItem(otpKey(data.email));
    showMessage('Email verified successfully. Redirecting to login...', 'success');
    setTimeout(() => window.location.href = 'login', 2800);
});

const otpCodeInput = document.querySelector('#otpCode');
if (otpCodeInput) {
    otpCodeInput.addEventListener('input', () => {
        otpCodeInput.value = otpCodeInput.value.replace(/\D+/g, '').slice(0, 6);
    });
}

const sendOtpBtn = document.querySelector('#sendOtpBtn');
if (sendOtpBtn) {
    const emailInput = document.querySelector('#otpEmail');
    sendOtpBtn.dataset.baseLabel = 'Re-send OTP';
    const resetResendButton = () => {
        stopCooldown(sendOtpBtn);
        sendOtpBtn.disabled = false;
        sendOtpBtn.textContent = sendOtpBtn.dataset.baseLabel;
    };
    const resumeCooldownForEmail = () => {
        const email = (emailInput?.value || '').trim().toLowerCase();
        resetResendButton();
        if (!isValidEmail(email)) {
            return;
        }

        const sentAt = Number(sessionStorage.getItem(otpKey(email)) || 0);
        const elapsed = sentAt ? Math.floor((Date.now() - sentAt) / 1000) : 0;
        const remaining = Math.max(0, 45 - elapsed);
        if (remaining > 0) {
            startCooldown(sendOtpBtn, remaining);
        }
    };

    emailInput?.addEventListener('input', resetResendButton);
    emailInput?.addEventListener('blur', resumeCooldownForEmail);
    resumeCooldownForEmail();

    sendOtpBtn.addEventListener('click', async () => {
        const email = (emailInput?.value || '').trim();
        if (!isValidEmail(email)) {
            showMessage('Enter a valid email address before requesting an OTP.', 'error');
            emailInput?.focus();
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
