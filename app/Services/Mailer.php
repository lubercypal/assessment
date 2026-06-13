<?php

namespace App\Services;

final class Mailer
{
    private static array $lastResult = [];

    public static function send(string $to, string $subject, string $message): bool
    {
        $driver = strtolower(trim((string) app_config('mail_driver', 'mail')));
        return self::sendWithDriver($driver, $to, $subject, $message);
    }

    public static function sendUsingDriver(string $driver, string $to, string $subject, string $message): bool
    {
        return self::sendWithDriver(strtolower(trim($driver)), $to, $subject, $message);
    }

    public static function lastResult(): array
    {
        return self::$lastResult;
    }

    public static function configurationDiagnostics(?string $driver = null): array
    {
        $driver = self::normalizeDriver($driver ?: (string) app_config('mail_driver', 'mail'));
        $from = trim((string) app_config('mail_from', ''));
        $checks = [
            'driver_supported' => in_array($driver, ['graph', 'smtp', 'mail', 'log'], true),
            'from_email_valid' => filter_var($from, FILTER_VALIDATE_EMAIL) !== false,
            'error_log_writable' => self::logPathWritable(
                (string) app_config('mail_error_log_path', __DIR__ . '/../../storage/logs/mail-error.log')
            ),
            'debug_log_writable' => self::logPathWritable(
                (string) app_config('mail_debug_log_path', __DIR__ . '/../../storage/logs/mail-debug.log')
            ),
        ];
        $requiredChecks = ['driver_supported', 'from_email_valid'];
        $warnings = [];

        if (!$checks['error_log_writable']) {
            $warnings[] = 'The mail error log is not writable; failures will fall back to the PHP error log.';
        }
        if ((bool) app_config('mail_debug_log', false) && !$checks['debug_log_writable']) {
            $warnings[] = 'Mail debug logging is enabled but the debug log is not writable.';
        }

        if ($driver === 'graph') {
            $checks += [
                'graph_tenant_configured' => trim((string) app_config('graph_tenant_id', '')) !== '',
                'graph_client_configured' => trim((string) app_config('graph_client_id', '')) !== '',
                'graph_secret_configured' => (string) app_config('graph_client_secret', '') !== '',
                'graph_sender_valid' => filter_var(
                    trim((string) app_config('graph_sender', $from)),
                    FILTER_VALIDATE_EMAIL
                ) !== false,
                'graph_http_transport_available' => function_exists('curl_init') || (bool) ini_get('allow_url_fopen'),
            ];
            $requiredChecks = array_merge($requiredChecks, [
                'graph_tenant_configured',
                'graph_client_configured',
                'graph_secret_configured',
                'graph_sender_valid',
                'graph_http_transport_available',
            ]);
            if (strcasecmp($from, trim((string) app_config('graph_sender', $from))) !== 0) {
                $warnings[] = 'mail_from differs from graph_sender; Microsoft Graph will send as graph_sender.';
            }
        } elseif ($driver === 'smtp') {
            $host = strtolower(trim((string) app_config('smtp_host', '')));
            $secure = strtolower(trim((string) app_config('smtp_secure', 'tls')));
            $checks += [
                'smtp_host_configured' => $host !== '',
                'smtp_port_valid' => (int) app_config('smtp_port', 0) > 0,
                'smtp_security_valid' => in_array($secure, ['tls', 'ssl', 'none'], true),
                'smtp_username_configured' => trim((string) app_config('smtp_username', '')) !== '',
                'smtp_password_configured' => (string) app_config('smtp_password', '') !== '',
                'openssl_available' => extension_loaded('openssl'),
            ];
            $requiredChecks = array_merge($requiredChecks, [
                'smtp_host_configured',
                'smtp_port_valid',
                'smtp_security_valid',
                'smtp_username_configured',
                'smtp_password_configured',
                'openssl_available',
            ]);
            if ($host === 'smtp.office365.com') {
                $warnings[] = 'Password-based Microsoft 365 SMTP AUTH is retired/unreliable. Use mail_driver=graph.';
            }
            if (strcasecmp($from, trim((string) app_config('smtp_username', ''))) !== 0) {
                $warnings[] = 'mail_from differs from smtp_username; Microsoft 365 may require Send As permission.';
            }
        } elseif ($driver === 'log') {
            $checks['mail_log_writable'] = self::logPathWritable(
                (string) app_config('mail_log_path', __DIR__ . '/../../storage/logs/mail.log')
            );
            $requiredChecks[] = 'mail_log_writable';
        }

        $requiredResults = array_intersect_key($checks, array_flip($requiredChecks));

        return [
            'driver' => $driver,
            'checks' => $checks,
            'warnings' => $warnings,
            'ready' => !in_array(false, $requiredResults, true),
        ];
    }

    private static function sendWithDriver(string $driver, string $to, string $subject, string $message): bool
    {
        $driver = self::normalizeDriver($driver);
        $requestId = bin2hex(random_bytes(8));
        self::setResult($requestId, $driver, 'validation', false, 'Mail request validation started.');

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            self::logError("REQUEST: {$requestId}\nInvalid recipient address.\nTO: {$to}\nSUBJECT: {$subject}");
            self::setResult($requestId, $driver, 'validation', false, 'The recipient email address is invalid.');
            return false;
        }
        if ($subject === '' || preg_match('/[\r\n]/', $subject)) {
            self::logError("REQUEST: {$requestId}\nInvalid mail subject.\nTO: {$to}");
            self::setResult($requestId, $driver, 'validation', false, 'The mail subject is invalid.');
            return false;
        }

        $diagnostics = self::configurationDiagnostics($driver);
        if (!$diagnostics['ready']) {
            $failed = array_keys(array_filter($diagnostics['checks'], static fn (bool $ok): bool => !$ok));
            self::logError(
                "REQUEST: {$requestId}\nMail configuration is incomplete.\nDRIVER: {$driver}\nFAILED CHECKS: "
                . implode(', ', $failed)
            );
            self::setResult(
                $requestId,
                $driver,
                'configuration',
                false,
                'Mail configuration is incomplete: ' . implode(', ', $failed)
            );
            return false;
        }

        if ($driver === 'log') {
            return self::log($to, $subject, $message, $requestId);
        }

        if ($driver === 'graph') {
            return self::graph($to, $subject, $message, $requestId);
        }

        if ($driver === 'smtp') {
            return self::smtp($to, $subject, $message, $requestId);
        }

        if ($driver !== 'mail') {
            self::logError("REQUEST: {$requestId}\nUnknown mail driver configured: {$driver}\nTO: {$to}\nSUBJECT: {$subject}");
            self::setResult($requestId, $driver, 'configuration', false, 'Unknown mail driver.');
            return false;
        }

        $from = app_config('mail_from');
        $fromName = app_config('mail_from_name');
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            "From: {$fromName} <{$from}>",
        ];

        $ok = mail($to, $subject, $message, implode("\r\n", $headers));
        if (!$ok) {
            self::logError("REQUEST: {$requestId}\nMAIL() failed\nTO: {$to}\nSUBJECT: {$subject}");
            self::setResult($requestId, $driver, 'transport', false, 'PHP mail() rejected the message.');
        } else {
            self::logDebug("REQUEST: {$requestId}\nPHP mail() accepted\nTO: {$to}\nSUBJECT: {$subject}");
            self::setResult(
                $requestId,
                $driver,
                'accepted',
                true,
                'PHP mail() accepted the message. Final delivery is not guaranteed.'
            );
        }

        return $ok;
    }

    private static function graph(string $to, string $subject, string $message, string $requestId): bool
    {
        $tenantId = trim((string) app_config('graph_tenant_id', ''));
        $clientId = trim((string) app_config('graph_client_id', ''));
        $clientSecret = (string) app_config('graph_client_secret', '');
        $sender = trim((string) app_config('graph_sender', app_config('mail_from')));

        $missing = array_keys(array_filter([
            'graph_tenant_id' => $tenantId === '',
            'graph_client_id' => $clientId === '',
            'graph_client_secret' => $clientSecret === '',
            'graph_sender' => $sender === '',
        ]));

        if ($missing) {
            self::logError(
                "REQUEST: {$requestId}\nMicrosoft Graph mail config missing: "
                . implode(', ', $missing) . "\nTO: {$to}\nSUBJECT: {$subject}"
            );
            self::setResult($requestId, 'graph', 'configuration', false, 'Microsoft Graph configuration is incomplete.');
            return false;
        }

        self::logDebug("REQUEST: {$requestId}\nMicrosoft Graph sendMail started\nTO: {$to}\nSUBJECT: {$subject}\nSENDER: {$sender}\nTENANT: {$tenantId}");

        try {
            $token = self::graphAccessToken($tenantId, $clientId, $clientSecret, $requestId);
            if (!$token) {
                self::setResult($requestId, 'graph', 'authentication', false, 'Microsoft Graph token request failed.');
                return false;
            }

            $endpoint = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($sender) . '/sendMail';
            $payload = [
                'message' => [
                    'subject' => $subject,
                    'body' => [
                        'contentType' => 'Text',
                        'content' => $message,
                    ],
                    'toRecipients' => [[
                        'emailAddress' => [
                            'address' => $to,
                        ],
                    ]],
                ],
            ];
            if (!(bool) app_config('graph_save_to_sent_items', true)) {
                $payload['saveToSentItems'] = false;
            }

            $response = self::httpPostJson($endpoint, $payload, [
                'Authorization: Bearer ' . $token,
            ]);

            if (!in_array($response['status'], [200, 202], true)) {
                self::logError(
                    "REQUEST: {$requestId}\nMicrosoft Graph sendMail failed\n" .
                    "STATUS: {$response['status']}\n" .
                    "TO: {$to}\n" .
                    "SUBJECT: {$subject}\n" .
                    "SENDER: {$sender}\n" .
                    'RESPONSE: ' . self::compactLogBody($response['body']) .
                    ($response['error'] ? "\nHTTP ERROR: {$response['error']}" : '')
                );
                self::setResult(
                    $requestId,
                    'graph',
                    'send',
                    false,
                    'Microsoft Graph rejected the send request.',
                    $response['status']
                );
                return false;
            }

            self::logDebug("REQUEST: {$requestId}\nMicrosoft Graph sendMail accepted; final delivery is pending\nSTATUS: {$response['status']}\nTO: {$to}\nSUBJECT: {$subject}\nSENDER: {$sender}");
            self::setResult(
                $requestId,
                'graph',
                'accepted',
                true,
                'Microsoft Graph accepted the request. Final delivery is not guaranteed; check message trace or the sender mailbox for later bounces.',
                $response['status']
            );

            return true;
        } catch (\Throwable $exception) {
            self::logError(
                "REQUEST: {$requestId}\nMicrosoft Graph sendMail exception\n" .
                "TO: {$to}\n" .
                "SUBJECT: {$subject}\n" .
                'ERROR: ' . $exception->getMessage()
            );
            self::setResult($requestId, 'graph', 'exception', false, $exception->getMessage());
            return false;
        }
    }

    private static function graphAccessToken(
        string $tenantId,
        string $clientId,
        string $clientSecret,
        string $requestId
    ): ?string
    {
        self::logDebug("REQUEST: {$requestId}\nMicrosoft Graph token request started\nTENANT: {$tenantId}");

        $endpoint = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token';
        $response = self::httpPostForm($endpoint, [
            'client_id' => $clientId,
            'scope' => 'https://graph.microsoft.com/.default',
            'client_secret' => $clientSecret,
            'grant_type' => 'client_credentials',
        ]);

        $payload = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($payload) || empty($payload['access_token'])) {
            self::logError(
                "REQUEST: {$requestId}\nMicrosoft Graph token request failed\n" .
                "STATUS: {$response['status']}\n" .
                "TENANT: {$tenantId}\n" .
                'RESPONSE: ' . self::compactLogBody($response['body']) .
                ($response['error'] ? "\nHTTP ERROR: {$response['error']}" : '')
            );
            return null;
        }

        self::logDebug("REQUEST: {$requestId}\nMicrosoft Graph token request succeeded\nTENANT: {$tenantId}");

        return (string) $payload['access_token'];
    }

    private static function httpPostForm(string $url, array $fields): array
    {
        return self::httpRequest($url, http_build_query($fields), [
            'Content-Type: application/x-www-form-urlencoded',
        ]);
    }

    private static function httpPostJson(string $url, array $payload, array $headers = []): array
    {
        return self::httpRequest($url, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), [
            ...$headers,
            'Content-Type: application/json',
        ]);
    }

    private static function httpRequest(string $url, string $body, array $headers): array
    {
        $connectTimeout = max(5, (int) app_config('mail_connect_timeout_seconds', 10));
        $timeout = max($connectTimeout, (int) app_config('mail_timeout_seconds', 25));
        $verifyPeer = (bool) app_config('mail_tls_verify_peer', true);

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => $verifyPeer,
                CURLOPT_SSL_VERIFYHOST => $verifyPeer ? 2 : 0,
            ]);
            $responseBody = curl_exec($curl);
            $error = curl_error($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);

            return [
                'status' => $status,
                'body' => $responseBody === false ? '' : (string) $responseBody,
                'error' => $error,
            ];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => $timeout,
            ],
            'ssl' => [
                'verify_peer' => $verifyPeer,
                'verify_peer_name' => $verifyPeer,
                'allow_self_signed' => false,
            ],
        ]);

        $responseBody = file_get_contents($url, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
                $status = (int) $matches[1];
                break;
            }
        }

        return [
            'status' => $status,
            'body' => $responseBody === false ? '' : (string) $responseBody,
            'error' => $responseBody === false ? 'HTTP request failed.' : '',
        ];
    }

    private static function smtp(string $to, string $subject, string $message, string $requestId): bool
    {
        $host = trim((string) app_config('smtp_host', ''));
        $port = (int) app_config('smtp_port', 587);
        $secure = strtolower(trim((string) app_config('smtp_secure', 'tls')));
        $username = trim((string) app_config('smtp_username', ''));
        $password = (string) app_config('smtp_password', '');
        $from = trim((string) app_config('mail_from', ''));
        $fromName = trim((string) app_config('mail_from_name', 'Assessment Platform'));
        $ehloDomain = trim((string) app_config(
            'smtp_ehlo_domain',
            $_SERVER['SERVER_NAME'] ?? parse_url((string) app_config('app_url', ''), PHP_URL_HOST) ?: 'localhost'
        ));
        $ehloDomain = preg_replace('/[^A-Za-z0-9.-]/', '', $ehloDomain) ?: 'localhost';
        $connectTimeout = max(5, (int) app_config('mail_connect_timeout_seconds', 20));
        $readTimeout = max(5, (int) app_config('mail_timeout_seconds', 25));
        $verifyPeer = (bool) app_config('mail_tls_verify_peer', true);

        self::logDebug("REQUEST: {$requestId}\nSMTP send started\nTO: {$to}\nSUBJECT: {$subject}\nHOST: {$host}\nPORT: {$port}\nSECURE: {$secure}\nFROM: {$from}\nEHLO: {$ehloDomain}");

        $transport = $secure === 'ssl' ? "ssl://{$host}" : $host;
        $context = stream_context_create([
            'ssl' => [
                'peer_name' => $host,
                'verify_peer' => $verifyPeer,
                'verify_peer_name' => $verifyPeer,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
            ],
        ]);
        $socket = @stream_socket_client(
            "{$transport}:{$port}",
            $errno,
            $errstr,
            $connectTimeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            self::logError("REQUEST: {$requestId}\nSMTP connect failed ({$errno}): {$errstr}\nHOST: {$host}:{$port}\nTO: {$to}\nSUBJECT: {$subject}");
            self::setResult($requestId, 'smtp', 'connection', false, "SMTP connection failed ({$errno}): {$errstr}");
            return false;
        }

        stream_set_timeout($socket, $readTimeout);

        try {
            self::expect($socket, [220]);
            self::command($socket, 'EHLO ' . $ehloDomain, [250]);

            if ($secure === 'tls') {
                self::command($socket, 'STARTTLS', [220]);
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }
                if (!@stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
                    throw new \RuntimeException(
                        'STARTTLS negotiation failed. Verify TLS 1.2+, CA certificates, hostname, and outbound port access.'
                    );
                }
                self::command($socket, 'EHLO ' . $ehloDomain, [250]);
            }

            self::command($socket, 'AUTH LOGIN', [334]);
            self::command($socket, base64_encode($username), [334]);
            self::command($socket, base64_encode($password), [235]);
            self::command($socket, 'MAIL FROM:<' . $from . '>', [250]);
            self::command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::command($socket, 'DATA', [354]);

            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'From: ' . self::formatAddress($from, $fromName),
                'To: <' . $to . '>',
                'Subject: ' . self::encodeHeader($subject),
                'Date: ' . date(DATE_RFC2822),
                'Message-ID: <' . $requestId . '@' . $ehloDomain . '>',
            ];

            $normalizedMessage = preg_replace("/\r\n|\r|\n/", "\r\n", $message) ?? $message;
            $normalizedMessage = preg_replace('/(?m)^\./', '..', $normalizedMessage) ?? $normalizedMessage;
            $body = implode("\r\n", $headers) . "\r\n\r\n" . $normalizedMessage . "\r\n.";
            self::command($socket, $body, [250]);
            self::command($socket, 'QUIT', [221]);
            fclose($socket);

            self::logDebug("REQUEST: {$requestId}\nSMTP server accepted the message; final delivery is pending\nTO: {$to}\nSUBJECT: {$subject}\nHOST: {$host}\nFROM: {$from}");
            self::setResult(
                $requestId,
                'smtp',
                'accepted',
                true,
                'The SMTP server accepted the message. Final delivery is not guaranteed; check the sender mailbox for later bounces.'
            );

            return true;
        } catch (\RuntimeException $e) {
            self::logError(
                "REQUEST: {$requestId}\nSMTP send failed\nHOST: {$host}:{$port}\nTO: {$to}\nSUBJECT: {$subject}\nERROR: " . $e->getMessage()
            );
            self::setResult($requestId, 'smtp', 'protocol', false, $e->getMessage());

            if (is_resource($socket)) {
                fclose($socket);
            }

            return false;
        }
    }

    private static function log(string $to, string $subject, string $message, string $requestId): bool
    {
        $path = app_config('mail_log_path', __DIR__ . '/../../storage/logs/mail.log');
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $entry = sprintf(
            "[%s]\nRequest: %s\nTo: %s\nSubject: %s\n%s\n\n",
            date('Y-m-d H:i:s'),
            $requestId,
            $to,
            $subject,
            $message
        );

        $written = file_put_contents($path, $entry, FILE_APPEND | LOCK_EX) !== false;
        self::setResult(
            $requestId,
            'log',
            $written ? 'accepted' : 'logging',
            $written,
            $written ? 'Message written to the local mail log.' : 'Could not write the local mail log.'
        );
        return $written;
    }

    private static function command($socket, string $command, array $expectedCodes): string
    {
        if (fwrite($socket, $command . "\r\n") === false) {
            throw new \RuntimeException('Could not write to the SMTP connection.');
        }
        return self::expect($socket, $expectedCodes);
    }

    private static function expect($socket, array $expectedCodes): string
    {
        $response = '';
        do {
            $line = fgets($socket, 515);
            if ($line === false) {
                $metadata = stream_get_meta_data($socket);
                $reason = !empty($metadata['timed_out']) ? 'SMTP response timed out.' : 'SMTP connection closed.';
                throw new \RuntimeException($reason);
            }
            $response .= $line;
        } while (isset($line[3]) && $line[3] === '-');

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new \RuntimeException(
                'Unexpected SMTP response: ' .trim($response)
            );
        }

        return $response;
    }

    private static function formatAddress(string $email, string $name): string
    {
        return self::encodeHeader($name) . ' <' . $email . '>';
    }

    private static function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private static function compactLogBody(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '[empty]';
        }

        return strlen($body) > 2000 ? substr($body, 0, 2000) . '...[truncated]' : $body;
    }

    private static function normalizeDriver(string $driver): string
    {
        $driver = strtolower(trim($driver));
        return in_array($driver, ['oauth2', 'oauth2.0', 'microsoft_graph', 'msgraph'], true)
            ? 'graph'
            : $driver;
    }

    private static function setResult(
        string $requestId,
        string $driver,
        string $stage,
        bool $accepted,
        string $message,
        ?int $statusCode = null
    ): void {
        self::$lastResult = [
            'request_id' => $requestId,
            'driver' => $driver,
            'stage' => $stage,
            'accepted' => $accepted,
            'message' => $message,
            'status_code' => $statusCode,
        ];
    }

    private static function logPathWritable(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if (is_file($path)) {
            return is_writable($path);
        }
        $directory = dirname($path);
        while (!is_dir($directory)) {
            $parent = dirname($directory);
            if ($parent === $directory) {
                return false;
            }
            $directory = $parent;
        }
        return is_writable($directory);
    }

    private static function logError(string $message): void
    {
        self::writeLog('mail-error', $message, true);
    }

    private static function logDebug(string $message): void
    {
        if (!app_config('mail_debug_log', false)) {
            return;
        }

        self::writeLog('mail-debug', $message, false);
    }

    private static function writeLog(string $channel, string $message, bool $isError): void
    {
        $path = app_config(
            $isError ? 'mail_error_log_path' : 'mail_debug_log_path',
            __DIR__ . '/../../storage/logs/' . ($isError ? 'mail-error.log' : 'mail-debug.log')
        );

        $directory = dirname($path);
        $entry = '[' . date('Y-m-d H:i:s') . "] {$channel}\n" . $message . "\n\n";

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            self::fallbackLog($channel, $message, 'Could not create log directory: ' . $directory);
            return;
        }

        $written = file_put_contents($path, $entry, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            self::fallbackLog($channel, $message, 'Could not write log file: ' . $path);
        }
    }

    private static function fallbackLog(string $channel, string $message, string $reason): void
    {
        $entry = "{$channel}: {$reason}\n{$message}";
        error_log($entry);

        if (class_exists(ErrorLogger::class)) {
            try {
                ErrorLogger::log($channel, $reason, [
                    'mail_message' => $message,
                ]);
            } catch (\Throwable) {
                // PHP error_log above is the last-resort fallback.
            }
        }
    }
}
