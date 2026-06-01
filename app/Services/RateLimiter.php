<?php

namespace App\Services;

final class RateLimiter
{
    public static function hit(string $action, string $identifier, int $maxAttempts, int $windowSeconds): bool
    {
        $key = hash('sha256', $action . '|' . $identifier);
        $windowStart = date('Y-m-d H:i:s', time() - $windowSeconds);

        db()->prepare('DELETE FROM rate_limits WHERE expires_at < NOW()')->execute();

        $stmt = db()->prepare('SELECT id, attempts FROM rate_limits WHERE action_key = ? AND created_at >= ? LIMIT 1');
        $stmt->execute([$key, $windowStart]);
        $row = $stmt->fetch();

        if (!$row) {
            $insert = db()->prepare('INSERT INTO rate_limits (action_key, attempts, expires_at) VALUES (?, 1, DATE_ADD(NOW(), INTERVAL ? SECOND))');
            $insert->execute([$key, $windowSeconds]);
            return true;
        }

        if ((int) $row['attempts'] >= $maxAttempts) {
            return false;
        }

        db()->prepare('UPDATE rate_limits SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
        return true;
    }

    public static function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}
