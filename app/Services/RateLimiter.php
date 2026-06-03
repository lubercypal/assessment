<?php

namespace App\Services;

final class RateLimiter
{
    public static function hit(string $action, string $identifier, int $maxAttempts, int $windowSeconds): bool
    {
        $key = hash('sha256', $action . '|' . $identifier);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $windowSeconds);

        $stmt = db()->prepare(
            'INSERT INTO rate_limits (action_key, attempts, expires_at)
             VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE
                attempts = IF(expires_at < UTC_TIMESTAMP(), 1, attempts + 1),
                expires_at = IF(expires_at < UTC_TIMESTAMP(), VALUES(expires_at), expires_at)'
        );
        $stmt->execute([$key, $expiresAt]);

        $check = db()->prepare('SELECT attempts, expires_at FROM rate_limits WHERE action_key = ? LIMIT 1');
        $check->execute([$key]);
        $row = $check->fetch();

        if (!$row) {
            return true;
        }

        if ((int) $row['attempts'] > $maxAttempts) {
            return false;
        }

        return true;
    }

    public static function retryAfter(string $action, string $identifier): int
    {
        $key = hash('sha256', $action . '|' . $identifier);
        $stmt = db()->prepare('SELECT attempts, expires_at FROM rate_limits WHERE action_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        if (!$row) {
            return 0;
        }

        return max(0, strtotime((string) $row['expires_at'] . ' UTC') - time());
    }

    public static function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}
