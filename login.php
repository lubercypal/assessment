<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | Assessment Platform</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-page">
    <main class="auth-shell panel">
        <h1>Assessment Login</h1>
        <p>Sign in with your verified email to continue.</p>
        <form id="loginForm" class="stack">
            <label class="field"><span>Email</span><input type="email" name="email" required autocomplete="email"></label>
            <label class="field"><span>Password</span><input type="password" name="password" required autocomplete="current-password"></label>
            <div id="message" class="message"></div>
            <div class="actions">
                <button type="submit">Login</button>
                <a href="register">Register</a>
                <a href="forgot-password">Forgot Password</a>
            </div>
        </form>
    </main>
    <script src="assets/js/api.js"></script>
    <script src="assets/js/auth.js"></script>
</body>
</html>
