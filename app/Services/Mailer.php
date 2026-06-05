<?php

namespace App\Services;

final class Mailer
{
    public static function send(string $to, string $subject, string $message): bool
    {
        $driver = strtolower(trim((string) app_config('mail_driver', 'mail')));

        if ($driver === 'log') {
            return self::log($to, $subject, $message);
        }

        if (in_array($driver, ['graph', 'oauth2', 'oauth2.0', 'microsoft_graph', 'msgraph'], true)) {
            return self::graph($to, $subject, $message);
        }

        if ($driver === 'smtp') {
            return self::smtp($to, $subject, $message);
        }

        if ($driver !== 'mail') {
            self::logError("Unknown mail driver configured: {$driver}\nTO: {$to}\nSUBJECT: {$subject}");
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
            self::logError("MAIL() failed\nTO: {$to}\nSUBJECT: {$subject}");
        }

        return $ok;
    }

    private static function graph(string $to, string $subject, string $message): bool
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
            self::logError('Microsoft Graph mail config missing: ' . implode(', ', $missing) . "\nTO: {$to}\nSUBJECT: {$subject}");
            return false;
        }

        self::logDebug("Microsoft Graph sendMail started\nTO: {$to}\nSUBJECT: {$subject}\nSENDER: {$sender}\nTENANT: {$tenantId}");

        try {
            $token = self::graphAccessToken($tenantId, $clientId, $clientSecret);
            if (!$token) {
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
                'saveToSentItems' => (bool) app_config('graph_save_to_sent_items', true),
            ];

            $response = self::httpPostJson($endpoint, $payload, [
                'Authorization: Bearer ' . $token,
            ]);

            if (!in_array($response['status'], [200, 202], true)) {
                self::logError(
                    "Microsoft Graph sendMail failed\n" .
                    "STATUS: {$response['status']}\n" .
                    "TO: {$to}\n" .
                    "SUBJECT: {$subject}\n" .
                    "SENDER: {$sender}\n" .
                    'RESPONSE: ' . self::compactLogBody($response['body']) .
                    ($response['error'] ? "\nHTTP ERROR: {$response['error']}" : '')
                );
                return false;
            }

            self::logDebug("Microsoft Graph sendMail accepted\nSTATUS: {$response['status']}\nTO: {$to}\nSUBJECT: {$subject}\nSENDER: {$sender}");

            return true;
        } catch (\Throwable $exception) {
            self::logError(
                "Microsoft Graph sendMail exception\n" .
                "TO: {$to}\n" .
                "SUBJECT: {$subject}\n" .
                'ERROR: ' . $exception->getMessage()
            );
            return false;
        }
    }

    private static function graphAccessToken(string $tenantId, string $clientId, string $clientSecret): ?string
    {
        self::logDebug("Microsoft Graph token request started\nTENANT: {$tenantId}");

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
                "Microsoft Graph token request failed\n" .
                "STATUS: {$response['status']}\n" .
                "TENANT: {$tenantId}\n" .
                'RESPONSE: ' . self::compactLogBody($response['body']) .
                ($response['error'] ? "\nHTTP ERROR: {$response['error']}" : '')
            );
            return null;
        }

        self::logDebug("Microsoft Graph token request succeeded\nTENANT: {$tenantId}");

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
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_TIMEOUT => 25,
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
                'timeout' => 25,
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

    private static function smtp(string $to, string $subject, string $message): bool
    {
        $host = app_config('smtp_host');
        $port = (int) app_config('smtp_port', 587);
        $secure = app_config('smtp_secure', 'tls');
        $username = app_config('smtp_username');
        $password = app_config('smtp_password');
        $from = app_config('mail_from');
        $fromName = app_config('mail_from_name');

        $transport = $secure === 'ssl' ? "ssl://{$host}" : $host;
        $socket = stream_socket_client("{$transport}:{$port}", $errno, $errstr, 20, STREAM_CLIENT_CONNECT);

        if (!$socket) {
            self::logError("SMTP connect failed ({$errno}): {$errstr}\nTO: {$to}\nSUBJECT: {$subject}");
            return false;
        }

        stream_set_timeout($socket, 20);

        try {
            self::expect($socket, [220]);
            self::command($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250]);

            if ($secure === 'tls') {
                self::command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    fclose($socket);
                    return false;
                }
                self::command($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250]);
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
            ];

            $body = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n.", "\n..", $message) . "\r\n.";
            self::command($socket, $body, [250]);
            self::command($socket, 'QUIT', [221]);
            fclose($socket);

            return true;
        } catch (\RuntimeException $e) {
            self::logError(
                "SMTP send failed\nTO: {$to}\nSUBJECT: {$subject}\nERROR: " . $e->getMessage()
            );

            fclose($socket);

            return false;
        }
    }

    private static function log(string $to, string $subject, string $message): bool
    {
        $path = app_config('mail_log_path', __DIR__ . '/../../storage/logs/mail.log');
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $entry = sprintf(
            "[%s]\nTo: %s\nSubject: %s\n%s\n\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            $message
        );

        return file_put_contents($path, $entry, FILE_APPEND | LOCK_EX) !== false;
    }

    private static function command($socket, string $command, array $expectedCodes): string
    {
        fwrite($socket, $command . "\r\n");
        return self::expect($socket, $expectedCodes);
    }

    private static function expect($socket, array $expectedCodes): string
    {
        $response = '';
        do {
            $line = fgets($socket, 515);
            if ($line === false) {
                throw new \RuntimeException('SMTP connection closed.');
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
