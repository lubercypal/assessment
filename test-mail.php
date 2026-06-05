<?php

require_once __DIR__ . '/app/bootstrap.php';

use App\Services\Mailer;

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function page_text(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$enabled = (bool) app_config('test_mail_enabled', false);
$expectedKey = (string) app_config('test_mail_key', '');
$providedKey = (string) ($_GET['key'] ?? '');
$format = strtolower((string) ($_GET['format'] ?? 'html'));

$statusCode = 200;
$result = [
    'ok' => false,
    'status' => 'blocked',
    'message' => 'Test mail is not enabled.',
    'driver' => null,
    'to' => null,
];

if (!$enabled || $expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
    $statusCode = 403;
    $result['message'] = 'Mail test is disabled or the test key is invalid.';
} else {
    $driver = strtolower(trim((string) ($_GET['driver'] ?? 'graph')));
    $allowedDrivers = ['graph', 'smtp'];
    $to = trim((string) ($_GET['to'] ?? app_config('test_mail_default_to', '')));

    $result['driver'] = $driver;
    $result['to'] = $to;

    if (!in_array($driver, $allowedDrivers, true)) {
        $statusCode = 400;
        $result['status'] = 'invalid-driver';
        $result['message'] = 'Invalid driver. Use driver=graph or driver=smtp.';
    } elseif (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $statusCode = 400;
        $result['status'] = 'invalid-recipient';
        $result['message'] = 'Provide a valid recipient email using the to parameter.';
    } else {
        $subject = 'Assessment mail delivery test via ' . strtoupper($driver);
        $message = implode("\n", [
            'This is a direct assessment platform mail delivery test.',
            'Driver: ' . strtoupper($driver),
            'Time: ' . date('Y-m-d H:i:s T'),
            'Host: ' . ($_SERVER['HTTP_HOST'] ?? 'unknown'),
        ]);

        $sent = Mailer::sendUsingDriver($driver, $to, $subject, $message);
        $result['ok'] = $sent;
        $result['status'] = $sent ? 'accepted' : 'failed';
        $result['message'] = $sent
            ? 'Mail request was accepted by the configured mail transport.'
            : 'Mail request failed. Check storage/logs/mail-error.log for details.';
    }
}

http_response_code($statusCode);

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_SLASHES);
    exit;
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mail Test | Assessment Platform</title>
    <link rel="icon" href="assets/img/assessment-loader.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-page">
    <main class="auth-shell panel">
        <h1>Mail Test</h1>
        <p>Direct delivery check for Microsoft Graph or SMTP.</p>
        <div class="message form-alert <?= $result['ok'] ? 'success' : 'error' ?>" role="status">
            <?= page_text($result['message']) ?>
        </div>
        <div class="stack">
            <p><strong>Status:</strong> <?= page_text($result['status']) ?></p>
            <p><strong>Driver:</strong> <?= page_text((string) ($result['driver'] ?? '-')) ?></p>
            <p><strong>Recipient:</strong> <?= page_text((string) ($result['to'] ?? '-')) ?></p>
        </div>
        <p class="muted">
            Graph returning accepted only means Microsoft accepted the send request. If a later bounce occurs,
            check the sender mailbox and Microsoft 365 delivery reports.
        </p>
    </main>
</body>
</html>
