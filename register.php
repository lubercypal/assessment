<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register | Assessment Platform</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-page">
    <main class="auth-shell stack">
        <section class="panel">
            <h1>Student Registration</h1>
            <p>Create your account. Email verification is required before login.</p>
            <form id="registerForm" class="stack">
                <label class="field"><span>Full Name</span><input name="full_name" required autocomplete="name"></label>
                <label class="field"><span>Email ID</span><input type="email" name="email" required autocomplete="email"></label>
                <label class="field"><span>Mobile Number</span><input name="mobile_number" required autocomplete="tel"></label>
                <div class="grid">
                    <label class="field"><span>Password</span><input type="password" name="password" required autocomplete="new-password"></label>
                    <label class="field"><span>Confirm Password</span><input type="password" name="password_confirmation" required autocomplete="new-password"></label>
                </div>
                <label class="check"><input id="terms" type="checkbox" required> I accept the consent and terms.</label>
                <div id="message" class="message"></div>
                <div class="actions">
                    <button type="submit">Register</button>
                    <a href="login">Cancel</a>
                </div>
            </form>
        </section>
        <section id="otpPanel" class="panel hidden">
            <h2>Email Verification</h2>
            <form id="otpForm" class="stack">
                <label class="field"><span>Email</span><input id="otpEmail" type="email" name="email" required></label>
                <label class="field"><span>OTP</span><input name="otp" inputmode="numeric" maxlength="6" required></label>
                <button type="submit">Verify Email</button>
            </form>
        </section>
    </main>
    <script src="assets/js/api.js"></script>
    <script src="assets/js/auth.js"></script>
</body>
</html>
