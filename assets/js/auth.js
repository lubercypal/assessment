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

bindForm('#registerForm', async (data) => {
    data.terms = document.querySelector('#terms').checked ? '1' : '';
    const result = await api('auth/register', { method: 'POST', body: JSON.stringify(data) });
    const nextEmail = result.email || data.email;
    sessionStorage.setItem(otpKey(nextEmail), String(Date.now()));
    window.location.href = `verify-email?email=${encodeURIComponent(nextEmail)}`;
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
    await api('auth/login', { method: 'POST', body: JSON.stringify(data) });
    window.location.href = 'dashboard';
});

bindForm('#forgotForm', async (data) => {
    await api('auth/forgot-password', { method: 'POST', body: JSON.stringify(data) });
    showMessage('If the email exists, a reset link has been sent.');
});

bindForm('#resetForm', async (data) => {
    await api('auth/reset-password', { method: 'POST', body: JSON.stringify(data) });
    showMessage('Password updated. Redirecting to login...');
    setTimeout(() => window.location.href = 'login', 800);
});
