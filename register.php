<?php require_once __DIR__ . '/app/bootstrap.php'; ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register | Assessment Platform</title>
    <link rel="icon" href="assets/img/assessment-loader.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-page">
    <div id="registerLoader" class="loader-overlay hidden" aria-live="polite" aria-busy="true">
        <div class="loader-card">
            <img src="assets/img/assessment-loader.svg" alt="Loading assessment registration">
            <div class="loader-text">Registering your account</div>
            <div class="loader-subtext">Please wait while we create your profile and send the OTP.</div>
        </div>
    </div>
    <main class="auth-shell stack">
        <section class="panel">
            <h1>Student Registration</h1>
            <p>Create your account. Email verification is required before login.</p>
            <form id="registerForm" class="stack" data-loader="#registerLoader" data-message="#message">
                <label class="field"><span>Full Name</span><input name="full_name" required autocomplete="name"></label>
                <label class="field"><span>Email ID</span><input type="email" name="email" required autocomplete="email"></label>
                <label class="field"><span>Mobile Number</span><input name="mobile_number" required autocomplete="tel"></label>
                <div class="grid">
                    <label class="field"><span>Password</span><input type="password" name="password" required autocomplete="new-password"></label>
                    <label class="field"><span>Confirm Password</span><input type="password" name="password_confirmation" required autocomplete="new-password"></label>
                </div>
                <label class="check"><input id="terms" type="checkbox" required> I accept the consent and terms. <a href="terms">Read here</a></label>
                <div id="message" class="message"></div>
                <div class="register-actions">
                    <button type="submit" class="action-wide">Register</button>
                    <a href="login" class="action-link secondary action-wide">Cancel</a>
                    <a href="verify-email" class="action-link action-wide">Verify Email</a>
                </div>
            </form>
        </section>
    </main>
    <script src="assets/js/api.js"></script>
    <script src="assets/js/auth.js"></script>
</body>
</html>
