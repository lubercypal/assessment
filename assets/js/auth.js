bindForm('#registerForm', async (data) => {
    data.terms = document.querySelector('#terms').checked ? '1' : '';
    await api('auth/register', { method: 'POST', body: JSON.stringify(data) });
    document.querySelector('#otpPanel')?.classList.remove('hidden');
    document.querySelector('#otpEmail').value = data.email;
    showMessage('Registration complete. Enter the OTP sent to your email.');
});

bindForm('#otpForm', async (data) => {
    await api('auth/verify-email', { method: 'POST', body: JSON.stringify(data) });
    showMessage('Email verified. Redirecting to login...');
    setTimeout(() => window.location.href = 'login', 800);
});

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
