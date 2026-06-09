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
<body class="auth-page login-page">
    <div id="globalLoader" class="loader-overlay hidden" aria-live="polite" aria-busy="true">
        <div class="loader-card">
            <img src="assets/img/assessment-loader.svg" alt="Loading">
            <div class="loader-text" data-loader-text>Working...</div>
            <div class="loader-subtext">Please wait.</div>
        </div>
    </div>
    <main class="login-shell" aria-label="Assessment login">
        <section class="login-brand-panel">
            <div class="login-brand-lockup">
                <img src="assets/img/assessment-loader.svg" alt="" aria-hidden="true">
                <span>Assessment Platform</span>
            </div>
            <div>
                <p class="login-eyebrow">Candidate Access</p>
                <h1>Secure assessment sign in</h1>
                <p class="login-lead">Use your verified email account to continue to the dashboard.</p>
            </div>
            <div class="login-status-grid" aria-label="Assessment access steps">
                <div>
                    <span>01</span>
                    <strong>Register</strong>
                </div>
                <div>
                    <span>02</span>
                    <strong>Verify Email</strong>
                </div>
                <div>
                    <span>03</span>
                    <strong>Begin Assessment</strong>
                </div>
            </div>
        </section>

        <section class="login-card panel">
            <div class="login-card__header">
                <p class="login-eyebrow">Welcome Back</p>
                <h2>Login</h2>
                <p>Enter your credentials to continue.</p>
            </div>
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
        </section>
    </main>
    <script src="assets/js/api.js"></script>
    <script src="assets/js/auth.js"></script>
</body>
</html>
