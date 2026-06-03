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

        $existing = $this->findUserByEmail($email);
        if ($existing && $existing['email_verified_at']) {
            Response::error('This email is already verified. Please log in.', 409, [
                'action' => 'login',
                '_form' => 'This email is already verified. Please log in.',
            ]);
        }

        if ($existing && !$existing['email_verified_at']) {
            $result = $this->issueOtp((int) $existing['id'], $email, 45);
            if ($result['cooldown']) {
                Response::error($result['message'], 429, [
                    'retry_after_seconds' => $result['retry_after_seconds'],
                    'action' => 'verify-email',
                    'email' => $email,
                    '_form' => $result['message'],
                ]);
            }

            Response::ok([
                'message' => 'Account already exists but is not verified. We sent a fresh OTP.',
                'next' => 'verify-email',
                'email' => $email,
            ], 200);
            return;
        }

        db()->beginTransaction();
        try {
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
            $result = $this->issueOtp($userId, $email, 45);
            if ($result['cooldown']) {
                throw new \RuntimeException($result['message']);
            }

            db()->commit();
            SecurityLog::record('register', $userId, ['email' => $email]);

            Response::ok([
                'message' => 'Registration successful. Check your email for the OTP.',
                'next' => 'verify-email',
                'email' => $email,
            ], 201);
        } catch (\Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            Response::error(
                $e->getMessage() ?: 'Registration could not be completed. Please try again.',
                500
            );
        }
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
            'SELECT id, otp_hash, expires_at, consumed_at FROM email_otps
             WHERE user_id = ?
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$user['id']]);
        $otp = $stmt->fetch();

        if (!$otp) {
            Response::error('No OTP request was found. Please re-send the OTP to continue.', 422, [
                'action' => 'resend-otp',
                'email' => $email,
                '_form' => 'No OTP request was found. Please re-send the OTP to continue.',
            ]);
        }

        if (!empty($otp['consumed_at']) || strtotime((string) $otp['expires_at']) <= time()) {
            Response::error('This OTP has expired. Please re-send the OTP to continue.', 422, [
                'action' => 'resend-otp',
                'email' => $email,
                '_form' => 'This OTP has expired. Please re-send the OTP to continue.',
            ]);
        }

        if (!password_verify((string) $data['otp'], $otp['otp_hash'])) {
            Response::error('The OTP you entered is incorrect. Please try again.', 422, [
                'action' => 'resend-otp',
                'email' => $email,
                '_form' => 'The OTP you entered is incorrect. Please try again.',
            ]);
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

        $result = $this->issueOtp((int) $user['id'], $email, 45);
        if ($result['cooldown']) {
            Response::error($result['message'], 429, [
                'retry_after_seconds' => $result['retry_after_seconds'],
                'action' => 'verify-email',
                'email' => $email,
            ]);
        }

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
            'SELECT id, expires_at, consumed_at FROM password_resets
             WHERE user_id = ? AND token_hash = ?
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$user['id'], $hash]);
        $reset = $stmt->fetch();

        if (!$reset || !empty($reset['consumed_at']) || strtotime((string) $reset['expires_at']) <= time()) {
            Response::error('This reset link has expired. Please request a new password reset link.', 422, [
                'action' => 'forgot-password',
                'email' => $user['email'] ?? (string) $data['email'],
                '_form' => 'This reset link has expired. Please request a new password reset link.',
            ]);
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

    public function resetLinkStatus(array $data): void
    {
        $errors = Validator::required($data, ['email', 'token']);
        if ($errors) {
            Response::error('This password reset link is missing or invalid. Please request a new password reset link.', 422, [
                'action' => 'forgot-password',
                'email' => strtolower(trim((string) ($data['email'] ?? ''))),
                '_form' => 'This password reset link is missing or invalid. Please request a new password reset link.',
            ]);
        }

        $email = strtolower(trim((string) $data['email']));
        $token = hash('sha256', (string) $data['token']);

        $user = $this->findUserByEmail($email);
        if (!$user) {
            Response::error('This password reset link is missing or invalid. Please request a new password reset link.', 422, [
                'action' => 'forgot-password',
                'email' => $email,
                '_form' => 'This password reset link is missing or invalid. Please request a new password reset link.',
            ]);
        }

        $stmt = db()->prepare(
            'SELECT expires_at, consumed_at FROM password_resets
             WHERE user_id = ? AND token_hash = ?
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$user['id'], $token]);
        $reset = $stmt->fetch();

        if (!$reset || !empty($reset['consumed_at']) || strtotime((string) $reset['expires_at']) <= time()) {
            Response::error('This password reset link has expired. Please request a new password reset link.', 422, [
                'action' => 'forgot-password',
                'email' => $email,
                '_form' => 'This password reset link has expired. Please request a new password reset link.',
            ]);
        }

        Response::ok(['message' => 'Reset link is valid.']);
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

    private function issueOtp(int $userId, string $email, int $cooldownSeconds = 45): array
    {
        $cooldown = $this->otpCooldown($userId, $cooldownSeconds);
        if ($cooldown['blocked']) {
            return [
                'cooldown' => true,
                'retry_after_seconds' => $cooldown['retry_after_seconds'],
                'message' => "Please wait {$cooldown['retry_after_seconds']} seconds before requesting another OTP.",
            ];
        }

        $otp = $this->createOtp($userId);
        if (!Mailer::send($email, 'Your assessment verification OTP', "Your OTP is {$otp}. It expires in 10 minutes.")) {
            throw new \RuntimeException('OTP email could not be sent. Please check the mail log.');
        }

        return [
            'cooldown' => false,
            'retry_after_seconds' => 0,
        ];
    }

    private function otpCooldown(int $userId, int $cooldownSeconds): array
    {
        $stmt = db()->prepare('SELECT created_at FROM email_otps WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId]);
        $lastSent = $stmt->fetchColumn();

        if (!$lastSent) {
            return ['blocked' => false, 'retry_after_seconds' => 0];
        }

        $elapsed = time() - strtotime((string) $lastSent);
        if ($elapsed < $cooldownSeconds) {
            return [
                'blocked' => true,
                'retry_after_seconds' => $cooldownSeconds - $elapsed,
            ];
        }

        return ['blocked' => false, 'retry_after_seconds' => 0];
    }

    private function createOtp(int $userId): string
    {
        $otp = (string) random_int(100000, 999999);
        $stmt = db()->prepare('INSERT INTO email_otps (user_id, otp_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))');
        $stmt->execute([$userId, password_hash($otp, PASSWORD_DEFAULT)]);
        return $otp;
    }

    private function findUserByEmail(string $email): ?array
    {
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        return $user ?: null;
    }
}
