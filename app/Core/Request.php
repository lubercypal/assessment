<?php

namespace App\Core;

final class Request
{
    public static function json(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        if (preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    public static function authToken(): ?string
    {
        return self::bearerToken() ?: ($_COOKIE['ASSESSMENT_AUTH'] ?? null);
    }

    public static function csrfToken(): ?string
    {
        return $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    }
}
