<?php

namespace App\Controllers;

use App\Core\Response;
use App\Core\Validator;
use App\Services\AuthService;
use App\Services\Mailer;
use App\Services\RateLimiter;
use App\Services\SecurityLog;

final class AuthController
{
    public function register(array $data): void
    {
        if (!RateLimiter::hit('register', RateLimiter::ip(), 10, 3600)) {
            Response::error('Too many registration attempts. Please try later.', 429);
        }

        $errors = Validator::required($data, ['full_name', 'email', 'mobile_number', 'password', 'password_confirmation', 'terms']);

        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (($data['password'] ?? '') !== ($data['password_confirmation'] ?? '')) {
            $errors['password_confirmation'] = 'Passwords do not match.';
        }
        if ($message = Validator::password((string) ($data['password'] ?? ''))) {
            $errors['password'] = $message;
        }
        if (empty($data['terms'])) {
            $errors['terms'] = 'Terms acceptance is required.';
        }

        if ($errors) {
            Response::error('Validation failed.', 422, $errors);
        }

        $exists = db()->prepare('SELECT id FROM users WHERE email = ?');
        $exists->execute([$email]);
        if ($exists->fetch()) {
            Response::error('Email is already registered.', 409);
        }

        $stmt = db()->prepare(
            'INSERT INTO users (full_name, email, mobile_number, password_hash, terms_accepted_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            trim((string) $data['full_name']),
            $email,
            trim((string) $data['mobile_number']),
            password_hash((string) $data['password'], PASSWORD_DEFAULT),
        ]);

        $userId = (int) db()->lastInsertId();
        $this->sendOtp($userId, $email);
        SecurityLog::record('register', $userId, ['email' => $email]);

        Response::ok(['message' => 'Registration successful. Check your email for the OTP.'], 201);
    }

    public function verifyEmail(array $data): void
    {
        $errors = Validator::required($data, ['email', 'otp']);
        if ($errors) {
            Response::error('Validation failed.', 422, $errors);
        }

        $email = strtolower(trim((string) $data['email']));
        if (!RateLimiter::hit('verify_email', RateLimiter::ip() . '|' . $email, 8, 900)) {
            Response::error('Too many OTP attempts. Please try later.', 429);
        }

        $user = $this->findUserByEmail($email);
        if (!$user) {
            Response::error('Invalid verification request.', 404);
        }

        $stmt = db()->prepare(
            'SELECT id, otp_hash FROM email_otps
             WHERE user_id = ? AND consumed_at IS NULL AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$user['id']]);
        $otp = $stmt->fetch();

        if (!$otp || !password_verify((string) $data['otp'], $otp['otp_hash'])) {
            Response::error('Invalid or expired OTP.', 422);
        }

        db()->prepare('UPDATE email_otps SET consumed_at = NOW() WHERE id = ?')->execute([$otp['id']]);
        db()->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = ?')->execute([$user['id']]);
        SecurityLog::record('email_verified', (int) $user['id'], ['email' => $email]);

        Response::ok(['message' => 'Email verified. You can now log in.']);
    }

    public function resendOtp(array $data): void
    {
        $errors = Validator::required($data, ['email']);
        if ($errors) {
            Response::error('Validation failed.', 422, $errors);
        }

        $email = strtolower(trim((string) $data['email']));
        if (!RateLimiter::hit('resend_otp', RateLimiter::ip() . '|' . $email, 3, 900)) {
            Response::error('Too many OTP resend requests. Please try later.', 429);
        }

        $user = $this->findUserByEmail($email);
        if (!$user) {
            Response::error('Email not found.', 404);
        }
        if ($user['email_verified_at']) {
            Response::error('Email is already verified.', 409);
        }

        $this->sendOtp((int) $user['id'], $email);
        Response::ok(['message' => 'A fresh OTP has been sent.']);
    }

    public function login(array $data): void
    {
        $errors = Validator::required($data, ['email', 'password']);
        if ($errors) {
            Response::error('Validation failed.', 422, $errors);
        }

        $email = strtolower(trim((string) $data['email']));
        if (!RateLimiter::hit('login', RateLimiter::ip() . '|' . $email, 6, 900)) {
            Response::error('Too many login attempts. Please try later.', 429);
        }

        $user = $this->findUserByEmail($email);
        if (!$user || !password_verify((string) $data['password'], $user['password_hash'])) {
            SecurityLog::record('login_failed', $user ? (int) $user['id'] : null, ['email' => $email]);
            Response::error('Invalid email or password.', 401);
        }
        if (!$user['email_verified_at']) {
            Response::error('Please verify your email before logging in.', 403);
        }

        $session = AuthService::createSession((int) $user['id']);
        SecurityLog::record('login_success', (int) $user['id'], ['email' => $email]);
        Response::ok([
            'csrf_token' => $session['csrf_token'],
            'expires_at' => $session['expires_at'],
            'user' => [
                'id' => (int) $user['id'],
                'full_name' => $user['full_name'],
                'email' => $user['email'],
            ],
        ]);
    }

    public function forgotPassword(array $data): void
    {
        $errors = Validator::required($data, ['email']);
        if ($errors) {
            Response::error('Validation failed.', 422, $errors);
        }

        $email = strtolower(trim((string) $data['email']));
        if (!RateLimiter::hit('forgot_password', RateLimiter::ip() . '|' . $email, 4, 3600)) {
            Response::error('Too many password reset requests. Please try later.', 429);
        }

        $user = $this->findUserByEmail($email);
        if ($user) {
            $token = bin2hex(random_bytes(32));
            $stmt = db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))');
            $stmt->execute([$user['id'], hash('sha256', $token)]);

            $link = rtrim(app_config('app_url'), '/') . '/reset-password?token=' . urlencode($token) . '&email=' . urlencode($email);
            Mailer::send($email, 'Reset your assessment password', "Use this secure link within 30 minutes:\n\n{$link}");
            SecurityLog::record('password_reset_requested', (int) $user['id'], ['email' => $email]);
        }

        Response::ok(['message' => 'If the email exists, a reset link has been sent.']);
    }

    public function resetPassword(array $data): void
    {
        $errors = Validator::required($data, ['email', 'token', 'password', 'password_confirmation']);
        if (($data['password'] ?? '') !== ($data['password_confirmation'] ?? '')) {
            $errors['password_confirmation'] = 'Passwords do not match.';
        }
        if ($message = Validator::password((string) ($data['password'] ?? ''))) {
            $errors['password'] = $message;
        }
        if ($errors) {
            Response::error('Validation failed.', 422, $errors);
        }

        $user = $this->findUserByEmail(strtolower(trim((string) $data['email'])));
        if (!RateLimiter::hit('reset_password', RateLimiter::ip() . '|' . strtolower(trim((string) $data['email'])), 6, 3600)) {
            Response::error('Too many password reset attempts. Please try later.', 429);
        }

        if (!$user) {
            Response::error('Invalid reset request.', 422);
        }

        $hash = hash('sha256', (string) $data['token']);
        $stmt = db()->prepare(
            'SELECT id FROM password_resets
             WHERE user_id = ? AND token_hash = ? AND consumed_at IS NULL AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$user['id'], $hash]);
        $reset = $stmt->fetch();

        if (!$reset) {
            Response::error('Invalid or expired reset token.', 422);
        }

        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([
            password_hash((string) $data['password'], PASSWORD_DEFAULT),
            $user['id'],
        ]);
        db()->prepare('UPDATE password_resets SET consumed_at = NOW() WHERE id = ?')->execute([$reset['id']]);
        db()->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ?')->execute([$user['id']]);
        SecurityLog::record('password_reset_completed', (int) $user['id']);

        Response::ok(['message' => 'Password updated. Please log in again.']);
    }

    public function me(): void
    {
        Response::ok(['user' => AuthService::user()]);
    }

    public function logout(): void
    {
        $user = null;
        try {
            $user = AuthService::user();
        } catch (\RuntimeException) {
        }
        AuthService::logout();
        SecurityLog::record('logout', $user ? (int) $user['id'] : null);
        Response::ok(['message' => 'Logged out.']);
    }

    private function sendOtp(int $userId, string $email): void
    {
        $otp = (string) random_int(100000, 999999);
        $stmt = db()->prepare('INSERT INTO email_otps (user_id, otp_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))');
        $stmt->execute([$userId, password_hash($otp, PASSWORD_DEFAULT)]);

        Mailer::send($email, 'Your assessment verification OTP', "Your OTP is {$otp}. It expires in 10 minutes.");
    }

    private function findUserByEmail(string $email): ?array
    {
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        return $user ?: null;
    }
}
