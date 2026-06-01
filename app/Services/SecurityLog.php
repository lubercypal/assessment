<?php

namespace App\Services;

final class SecurityLog
{
    public static function record(string $event, ?int $userId = null, array $context = []): void
    {
        $stmt = db()->prepare(
            'INSERT INTO security_events (event, user_id, ip_address, user_agent, context)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $event,
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            json_encode($context),
        ]);
    }
}
