<?php require_once __DIR__ . '/app/bootstrap.php'; ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | Assessment Platform</title>
    <link rel="icon" href="assets/img/assessment-loader.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-page">
    <div id="globalLoader" class="loader-overlay hidden" aria-live="polite" aria-busy="true">
        <div class="loader-card">
            <img src="assets/img/assessment-loader.svg" alt="Loading">
            <div class="loader-text" data-loader-text>Working...</div>
            <div class="loader-subtext">Please wait.</div>
        </div>
    </div>
    <main class="auth-shell panel">
        <h1>Assessment Login</h1>
        <p>Sign in with your verified email to continue.</p>
        <form id="loginForm" class="stack">
            <label class="field"><span>Email</span><input type="email" name="email" required autocomplete="email"></label>
            <label class="field"><span>Password</span><input type="password" name="password" required autocomplete="current-password"></label>
            <div id="message" class="message"></div>
            <div id="throttleMessage" class="message error form-alert hidden" role="alert"></div>
            <div class="actions">
                <button type="submit" class="action-wide">Login</button>
            </div>
            <div class="auth-links auth-links-grid login-links">
                <a href="register" class="action-link secondary">Register</a>
                <a href="verify-email" class="action-link secondary">Verify Email</a>
                <a href="forgot-password" class="action-link secondary">Forgot Password</a>
            </div>
        </form>
    </main>
    <script src="assets/js/api.js"></script>
    <script src="assets/js/auth.js"></script>
</body>
</html>
