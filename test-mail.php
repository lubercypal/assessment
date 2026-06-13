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
    'configured_driver' => strtolower(trim((string) app_config('mail_driver', 'mail'))),
    'to' => null,
    'request_id' => null,
    'stage' => null,
    'diagnostics' => [],
    'warnings' => [],
];

if (!$enabled || $expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
    $statusCode = 403;
    $result['message'] = 'Mail test is disabled or the test key is invalid.';
} else {
    $driver = strtolower(trim((string) ($_GET['driver'] ?? 'config')));
    $allowedDrivers = ['config', 'graph', 'smtp'];
    $allowDriverOverride = (bool) app_config('test_mail_allow_driver_override', false);
    $to = trim((string) ($_GET['to'] ?? app_config('test_mail_default_to', '')));
    $effectiveDriver = $driver === 'config'
        ? strtolower(trim((string) app_config('mail_driver', 'mail')))
        : $driver;
    $diagnostics = Mailer::configurationDiagnostics($effectiveDriver);

    $result['driver'] = $effectiveDriver;
    $result['to'] = $to;
    $result['diagnostics'] = $diagnostics['checks'];
    $result['warnings'] = $diagnostics['warnings'];

    if (!in_array($driver, $allowedDrivers, true)) {
        $statusCode = 400;
        $result['status'] = 'invalid-driver';
        $result['message'] = 'Invalid driver. Use driver=config, driver=graph, or driver=smtp.';
    } elseif ($driver !== 'config' && !$allowDriverOverride) {
        $statusCode = 400;
        $result['status'] = 'driver-override-disabled';
        $result['message'] = 'Driver override is disabled. Test the configured mail_driver using driver=config.';
    } elseif (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $statusCode = 400;
        $result['status'] = 'invalid-recipient';
        $result['message'] = 'Provide a valid recipient email using the to parameter.';
    } else {
        $subject = 'Assessment mail delivery test via ' . strtoupper($effectiveDriver);
        $message = implode("\n", [
            'This is a direct assessment platform mail delivery test.',
            'Driver: ' . strtoupper($effectiveDriver),
            'Time: ' . date('Y-m-d H:i:s T'),
            'Host: ' . ($_SERVER['HTTP_HOST'] ?? 'unknown'),
        ]);

        $sent = $driver === 'config'
            ? Mailer::send($to, $subject, $message)
            : Mailer::sendUsingDriver($driver, $to, $subject, $message);
        $result['ok'] = $sent;
        $result['status'] = $sent ? 'accepted' : 'failed';
        $mailResult = Mailer::lastResult();
        $result['request_id'] = $mailResult['request_id'] ?? null;
        $result['stage'] = $mailResult['stage'] ?? null;
        $result['message'] = (string) ($mailResult['message'] ?? (
            $sent
                ? 'Mail request was accepted by the configured mail transport.'
                : 'Mail request failed. Check storage/logs/mail-error.log for details.'
        ));
        if (!$sent) {
            $statusCode = 502;
        }
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
            <p><strong>Configured Driver:</strong> <?= page_text((string) ($result['configured_driver'] ?? '-')) ?></p>
            <p><strong>Recipient:</strong> <?= page_text((string) ($result['to'] ?? '-')) ?></p>
            <p><strong>Stage:</strong> <?= page_text((string) ($result['stage'] ?? '-')) ?></p>
            <p><strong>Request ID:</strong> <?= page_text((string) ($result['request_id'] ?? '-')) ?></p>
        </div>
        <?php if (!empty($result['warnings'])): ?>
            <div class="message form-alert error" role="note">
                <?= page_text(implode(' ', $result['warnings'])) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($result['diagnostics'])): ?>
            <div class="stack">
                <h2>Configuration checks</h2>
                <?php foreach ($result['diagnostics'] as $check => $passed): ?>
                    <p>
                        <strong><?= page_text(str_replace('_', ' ', ucfirst($check))) ?>:</strong>
                        <?= $passed ? 'Passed' : 'Failed' ?>
                    </p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="muted">
            <?= (($result['driver'] ?? '') === 'graph')
                ? 'Graph returning accepted only means Microsoft accepted the send request. If a later bounce occurs, check the sender mailbox and Microsoft 365 delivery reports.'
                : 'SMTP returning accepted means the configured SMTP server accepted the message. If a later bounce occurs, check the sender mailbox and provider delivery logs.' ?>
        </p>
    </main>
</body>
</html>
