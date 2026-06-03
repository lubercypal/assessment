<?php require_once __DIR__ . '/app/bootstrap.php'; ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Terms and Conditions | Assessment Platform</title>
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
        <h1>Terms and Conditions</h1>
        <p>This assessment platform is provided for registered candidates only. By using the system you agree to provide accurate personal information, keep your login credentials private, and follow all assessment instructions displayed before starting a test.</p>
        <p>Answers and attempts are recorded for evaluation and reporting. Abuse, impersonation, automated access, or attempts to manipulate timing or question order may result in account restriction. The platform may store verification, session, and audit information necessary for secure operation.</p>
        <p>Use the software responsibly and only for legitimate assessment participation. The platform owner may update these terms as needed to maintain security, fairness, and service continuity.</p>
        <div class="actions">
            <a href="register" class="action-link">Back to Register</a>
            <a href="login" class="action-link">Back to Login</a>
        </div>
    </main>
    <script src="assets/js/api.js"></script>
</body>
</html>
