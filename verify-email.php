<?php
require_once __DIR__ . '/app/bootstrap.php';
$email = htmlspecialchars($_GET['email'] ?? '', ENT_QUOTES);
$locked = !empty($_GET['locked']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify Email | Assessment Platform</title>
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
        <h1>Email Verification</h1>
        <p>Enter the OTP sent to your registered email address. If you have not received one yet, send a fresh OTP from here.</p>
        <form id="otpForm" class="stack" data-message="#message">
            <label class="field"><span>Email</span><input id="otpEmail" type="email" name="email" required value="<?php echo $email; ?>" <?php echo $locked ? 'readonly aria-readonly="true"' : ''; ?>></label>
            <label class="field"><span>OTP</span><input name="otp" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required></label>
            <div id="message" class="message"></div>
            <div class="verify-actions">
                <button id="sendOtpBtn" type="button" class="secondary action-wide">Re-send OTP</button>
                <button type="submit" class="action-wide">Verify Email</button>
            </div>
            <div class="auth-links">
                <a href="login" class="action-link">Back to Login</a>
            </div>
        </form>
    </main>
    <script src="assets/js/api.js"></script>
    <script src="assets/js/auth.js"></script>
</body>
</html>
