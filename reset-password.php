<?php require_once __DIR__ . '/app/bootstrap.php'; ?>
<?php
$resetToken = trim((string) ($_GET['token'] ?? ''));
if ($resetToken === '') {
    header('Location: forgot-password?notice=reset_link_required');
    exit;
}

$resetEmail = htmlspecialchars(strtolower(trim((string) ($_GET['email'] ?? ''))), ENT_QUOTES);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Set New Password | Assessment Platform</title>
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
        <h1>Set New Password</h1>
        <div id="pageNotice" class="message" role="alert" hidden></div>
        <form id="resetForm" class="stack" data-message="#message" data-token="<?php echo htmlspecialchars($resetToken, ENT_QUOTES); ?>" data-email="<?php echo $resetEmail; ?>">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($resetToken, ENT_QUOTES); ?>">
            <label class="field"><span>Email</span><input id="resetEmail" type="email" name="email" required value="<?php echo $resetEmail; ?>" readonly aria-readonly="true"></label>
            <label class="field"><span>New Password</span><input type="password" name="password" required autocomplete="new-password"></label>
            <label class="field"><span>Confirm Password</span><input type="password" name="password_confirmation" required autocomplete="new-password"></label>
            <div id="message" class="message"></div>
            <div class="verify-actions">
                <button type="submit" class="action-wide">Update Password</button>
                <a href="forgot-password" class="action-link secondary action-wide">Request Reset Link</a>
            </div>
            <div class="auth-links auth-links-full">
                <a href="login" class="action-link secondary">Back to Login</a>
            </div>
        </form>
    </main>
    <script src="assets/js/api.js"></script>
    <script src="assets/js/auth.js"></script>
</body>
</html>
