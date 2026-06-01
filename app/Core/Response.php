<?php

namespace App\Core;

final class Response
{
    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(array $data = [], int $status = 200): void
    {
        self::json(['ok' => true, 'data' => $data], $status);
    }

    public static function error(string $message, int $status = 400, array $details = []): void
    {
        self::json([
            'ok' => false,
            'error' => [
                'message' => $message,
                'details' => $details,
            ],
        ], $status);
    }
}
