<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot Password | Assessment Platform</title>
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
        <h1>Reset Password</h1>
        <p>Enter your registered email. A secure reset link will be sent if the account exists.</p>
        <form id="forgotForm" class="stack">
            <label class="field"><span>Email</span><input type="email" name="email" required autocomplete="email"></label>
            <div id="message" class="message"></div>
            <div class="actions">
                <button type="submit">Send Reset Link</button>
                <a href="login">Back to Login</a>
            </div>
        </form>
    </main>
    <script src="assets/js/api.js"></script>
    <script src="assets/js/auth.js"></script>
</body>
</html>
