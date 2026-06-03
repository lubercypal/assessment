<?php

namespace App\Services;

use Throwable;

final class ErrorLogger
{
    public static function path(): string
    {
        return app_config('app_error_log_path', __DIR__ . '/../../storage/logs/app-error.log');
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        $path = self::path();
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $entry = [
            'time' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        file_put_contents($path, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public static function exception(Throwable $exception, array $context = []): void
    {
        self::log('error', $exception->getMessage(), $context + [
            'exception' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }

    public static function requestContext(): array
    {
        return [
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'uri' => $_SERVER['REQUEST_URI'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ];
    }
}
