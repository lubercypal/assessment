<?php

namespace App\Services;

use App\Core\Jwt;
use App\Core\Request;
use RuntimeException;

final class AuthService
{
    private const AUTH_COOKIE = 'ASSESSMENT_AUTH';
    private const CSRF_COOKIE = 'ASSESSMENT_CSRF';

    public static function createSession(int $userId): array
    {
        $now = time();
        $sessionId = bin2hex(random_bytes(32));
        $csrfToken = bin2hex(random_bytes(32));
        $ttl = (int) app_config('jwt_ttl_seconds', 7200);
        $expiresAt = date('Y-m-d H:i:s', $now + $ttl);

        $claims = [
            'iss' => app_config('jwt_issuer'),
            'iat' => $now,
            'exp' => $now + $ttl,
            'sub' => $userId,
            'sid' => $sessionId,
        ];

        $stmt = db()->prepare('INSERT INTO user_sessions (id, user_id, csrf_token_hash, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $sessionId,
            $userId,
            hash('sha256', $csrfToken),
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            $expiresAt,
        ]);

        $token = Jwt::encode($claims, app_config('jwt_secret'));
        self::setAuthCookies($token, $csrfToken, $now + $ttl);

        return [
            'csrf_token' => $csrfToken,
            'expires_at' => $expiresAt,
        ];
    }

    public static function user(): array
    {
        return self::session()['user'];
    }

    public static function session(): array
    {
        $token = Request::authToken();
        if (!$token) {
            throw new RuntimeException('Missing authentication token.');
        }

        $claims = Jwt::decode($token, app_config('jwt_secret'));
        $stmt = db()->prepare(
            'SELECT s.id AS session_id, s.csrf_token_hash, u.id, u.full_name, u.email, u.mobile_number, u.email_verified_at
             FROM user_sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.id = ? AND s.user_id = ? AND s.revoked_at IS NULL AND s.expires_at > NOW()'
        );
        $stmt->execute([$claims['sid'] ?? '', $claims['sub'] ?? 0]);
        $user = $stmt->fetch();

        if (!$user) {
            throw new RuntimeException('Invalid or expired session.');
        }

        $sessionData = [
            'id' => $user['session_id'],
            'csrf_token_hash' => $user['csrf_token_hash'],
        ];
        unset($user['session_id'], $user['csrf_token_hash']);

        return [
            'claims' => $claims,
            'session' => $sessionData,
            'user' => $user,
        ];
    }

    public static function validateCsrf(): void
    {
        $session = self::session();
        $token = Request::csrfToken();

        if (!$token || !hash_equals($session['session']['csrf_token_hash'], hash('sha256', $token))) {
            throw new RuntimeException('Invalid CSRF token.');
        }
    }

    public static function requirePage(): array
    {
        try {
            return self::user();
        } catch (RuntimeException) {
            header('Location: login', true, 302);
            exit;
        }
    }

    public static function logout(): void
    {
        $token = Request::authToken();
        if (!$token) {
            self::clearAuthCookies();
            return;
        }

        try {
            $claims = Jwt::decode($token, app_config('jwt_secret'));
            $stmt = db()->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE id = ?');
            $stmt->execute([$claims['sid'] ?? '']);
        } catch (RuntimeException) {
        }

        self::clearAuthCookies();
    }

    private static function setAuthCookies(string $jwt, string $csrfToken, int $expires): void
    {
        $secure = self::secureCookie();
        setcookie(self::AUTH_COOKIE, $jwt, [
            'expires' => $expires,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        setcookie(self::CSRF_COOKIE, $csrfToken, [
            'expires' => $expires,
            'path' => '/',
            'secure' => $secure,
            'httponly' => false,
            'samesite' => 'Strict',
        ]);
    }

    private static function clearAuthCookies(): void
    {
        $secure = self::secureCookie();
        foreach ([self::AUTH_COOKIE, self::CSRF_COOKIE] as $cookie) {
            setcookie($cookie, '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => $secure,
                'httponly' => $cookie === self::AUTH_COOKIE,
                'samesite' => 'Strict',
            ]);
        }
    }

    private static function secureCookie(): bool
    {
        return (bool) app_config('cookie_secure', app_config('app_env') === 'production');
    }
}
