<?php

namespace App\Services;

final class Mailer
{
    public static function send(string $to, string $subject, string $message): bool
    {
        $driver = app_config('mail_driver', 'mail');

        if ($driver === 'log') {
            return self::log($to, $subject, $message);
        }

        if ($driver === 'smtp') {
            return self::smtp($to, $subject, $message);
        }

        $from = app_config('mail_from');
        $fromName = app_config('mail_from_name');
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            "From: {$fromName} <{$from}>",
        ];

        return mail($to, $subject, $message, implode("\r\n", $headers));
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
                "TO: {$to}\n" .
                "SUBJECT: {$subject}\n" .
                "ERROR: " . $e->getMessage()
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

    private static function logError(string $message): void
    {
        $path = app_config(
            'mail_error_log_path',
            __DIR__ . '/../../storage/logs/mail-error.log'
        );

        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents(
            $path,
            '[' . date('Y-m-d H:i:s') . "]\n" .
            $message .
            "\n\n",
            FILE_APPEND | LOCK_EX
        );
    }
}
