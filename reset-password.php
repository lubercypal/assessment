<?php require_once __DIR__ . '/app/bootstrap.php'; ?>
<?php
$resetToken = trim((string) ($_GET['token'] ?? ''));
if ($resetToken === '') {
    header('Location: forgot-password?notice=reset_link_required');
    exit;
}

$resetTokenHash = hash('sha256', $resetToken);
$resetState = 'invalid';
$resetNotice = 'This password reset link has expired. Please request a new password reset link.';
$resetEmail = htmlspecialchars(strtolower(trim((string) ($_GET['email'] ?? ''))), ENT_QUOTES);

$stmt = db()->prepare(
    'SELECT pr.id, pr.created_at, pr.consumed_at, u.email,
            CASE WHEN pr.consumed_at IS NULL AND TIMESTAMPDIFF(SECOND, pr.created_at, NOW()) < 1800 THEN 1 ELSE 0 END AS is_valid
     FROM password_resets pr
     INNER JOIN users u ON u.id = pr.user_id
     WHERE pr.token_hash = ?
     ORDER BY pr.id DESC LIMIT 1'
);
$stmt->execute([$resetTokenHash]);
$reset = $stmt->fetch();

if ($reset && (int) ($reset['is_valid'] ?? 0) === 1) {
    $resetState = 'valid';
    $resetEmail = htmlspecialchars((string) $reset['email'], ENT_QUOTES);
} elseif (!$reset) {
    $resetNotice = 'This password reset link is missing or invalid. Please request a new password reset link.';
}
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
        <?php if ($resetState !== 'valid'): ?>
            <div id="pageNotice" class="message error form-alert" role="alert">
                <strong><?php echo htmlspecialchars($resetNotice, ENT_QUOTES); ?></strong>
                <div class="form-alert-cta">
                    <a class="action-link secondary" href="forgot-password">Request Reset Link</a>
                    <span>Please request a fresh password reset link to continue.</span>
                </div>
            </div>
        <?php else: ?>
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
        <?php endif; ?>
    </main>
    <script src="assets/js/api.js"></script>
    <script src="assets/js/auth.js"></script>
</body>
</html>
