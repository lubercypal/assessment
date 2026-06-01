<?php

namespace App\Core;

use RuntimeException;

final class Jwt
{
    public static function encode(array $claims, string $secret): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $segments = [
            self::base64UrlEncode(json_encode($header)),
            self::base64UrlEncode(json_encode($claims)),
        ];
        $signature = hash_hmac('sha256', implode('.', $segments), $secret, true);
        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    public static function decode(string $jwt, string $secret): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('Invalid token format.');
        }

        [$header64, $payload64, $signature64] = $parts;
        $expected = self::base64UrlEncode(hash_hmac('sha256', "$header64.$payload64", $secret, true));

        if (!hash_equals($expected, $signature64)) {
            throw new RuntimeException('Invalid token signature.');
        }

        $payload = json_decode(self::base64UrlDecode($payload64), true);
        if (!is_array($payload)) {
            throw new RuntimeException('Invalid token payload.');
        }

        if (($payload['exp'] ?? 0) < time()) {
            throw new RuntimeException('Token expired.');
        }

        return $payload;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/')) ?: '';
    }
}
