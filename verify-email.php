<?php
$email = htmlspecialchars($_GET['email'] ?? '', ENT_QUOTES);
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
    <main class="auth-shell panel">
        <h1>Email Verification</h1>
        <p>Enter the OTP sent to your registered email address. If you have not received one yet, send a fresh OTP from here.</p>
        <form id="otpForm" class="stack" data-message="#message">
            <label class="field"><span>Email</span><input id="otpEmail" type="email" name="email" required value="<?php echo $email; ?>"></label>
            <label class="field"><span>OTP</span><input name="otp" inputmode="numeric" maxlength="6" required></label>
            <div id="message" class="message"></div>
            <div class="actions">
                <button id="sendOtpBtn" type="button" class="secondary">Send OTP</button>
                <button type="submit">Verify Email</button>
                <a href="login">Back to Login</a>
            </div>
        </form>
    </main>
    <script src="assets/js/api.js"></script>
    <script src="assets/js/auth.js"></script>
</body>
</html>
