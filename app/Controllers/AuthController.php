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
        $registerKey = RateLimiter::ip();
        if (!RateLimiter::hit('register', $registerKey, 10, 3600)) {
            Response::error('Too many registration attempts. Please try later.', 429, [
                'retry_after_seconds' => RateLimiter::retryAfter('register', $registerKey),
            ]);
        }

        $errors = Validator::required($data, ['full_name', 'email', 'mobile_number', 'password', 'password_confirmation', 'terms']);

        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (($data['password'] ?? '') !== ($data['password_confirmation'] ?? '')) {
            $errors['password_confirmation'] = 'Passwords do not match.';
        }
        $mobile = $this->normalizeIndianMobile((string) ($data['mobile_number'] ?? ''));
        $mobileNumber = $mobile['normalized'];
        if (!$mobileNumber) {
            $errors['mobile_number'] = $mobile['message'];
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
            try {
                $result = $this->issueOtp((int) $existing['id'], $email, 45);
            } catch (\RuntimeException $exception) {
                Response::error($exception->getMessage(), 500);
            }
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
                $mobileNumber,
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
        if (!empty($data['otp']) && !preg_match('/^\d{6}$/', (string) $data['otp'])) {
            $errors['otp'] = 'OTP must be exactly 6 digits.';
        }
        if ($errors) {
            Response::error('Validation failed.', 422, $errors);
        }

        $email = strtolower(trim((string) $data['email']));
        $verifyKey = RateLimiter::ip() . '|' . $email;
        if (!RateLimiter::hit('verify_email', $verifyKey, 8, 900)) {
            Response::error('Too many OTP attempts. Please try later.', 429, [
                'retry_after_seconds' => RateLimiter::retryAfter('verify_email', $verifyKey),
            ]);
        }

        $user = $this->findUserByEmail($email);
        if (!$user) {
            Response::error('Invalid verification request.', 404);
        }
        if ($user['email_verified_at']) {
            Response::error('This email is already verified. Please log in.', 409, [
                'action' => 'login',
                '_form' => 'This email is already verified. Please log in.',
            ]);
        }

        $stmt = db()->prepare(
            'SELECT id, otp_hash, expires_at, consumed_at,
                    CASE WHEN consumed_at IS NULL AND expires_at > NOW() THEN 1 ELSE 0 END AS is_valid
             FROM email_otps
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

        if ((int) ($otp['is_valid'] ?? 0) !== 1) {
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
        $resendKey = RateLimiter::ip() . '|' . $email;
        if (!RateLimiter::hit('resend_otp', $resendKey, 3, 900)) {
            Response::error('Too many OTP resend requests. Please try later.', 429, [
                'retry_after_seconds' => RateLimiter::retryAfter('resend_otp', $resendKey),
            ]);
        }

        $user = $this->findUserByEmail($email);
        if (!$user) {
            Response::error('Email not found.', 404);
        }
        if ($user['email_verified_at']) {
            Response::error('This email is already verified. Please log in.', 409, [
                'action' => 'login',
                '_form' => 'This email is already verified. Please log in.',
            ]);
        }

        try {
            $result = $this->issueOtp((int) $user['id'], $email, 45);
        } catch (\RuntimeException $exception) {
            Response::error($exception->getMessage(), 500);
        }
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
        $loginKey = RateLimiter::ip() . '|' . $email;
        $loginThrottleAction = 'login_v2';
        if (!RateLimiter::hit($loginThrottleAction, $loginKey, 6, 450)) {
            $wait = RateLimiter::retryAfter($loginThrottleAction, $loginKey);
            Response::error('Too many login attempts. Please try later.', 429, [
                'retry_after_seconds' => $wait,
            ]);
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
        $rateKey = RateLimiter::ip() . '|' . $email;
        if (!RateLimiter::hit('forgot_password', $rateKey, 4, 3600)) {
            Response::error('Too many password reset requests. Please try later.', 429, [
                'retry_after_seconds' => RateLimiter::retryAfter('forgot_password', $rateKey),
            ]);
        }

        $user = $this->findUserByEmail($email);
        if (!$user) {
            Response::error('No account was found for this email address. Please check the email or register first.', 404, [
                'email' => 'No registered account was found for this email address.',
            ]);
        }

        $token = bin2hex(random_bytes(32));
        $stmt = db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))');
        $stmt->execute([$user['id'], hash('sha256', $token)]);
        $resetId = (int) db()->lastInsertId();

        $link = rtrim(app_config('app_url'), '/') . '/reset-password?token=' . urlencode($token) . '&email=' . urlencode($email);
        $sent = Mailer::send($email, 'Reset your assessment password', "Use this secure link within 30 minutes:\n\n{$link}");
        if (!$sent) {
            db()->prepare('DELETE FROM password_resets WHERE id = ?')->execute([$resetId]);
            Response::error('We could not send the reset email right now. Please try again later.', 500);
        }

        SecurityLog::record('password_reset_requested', (int) $user['id'], ['email' => $email]);

        Response::ok(['message' => 'Password reset link sent successfully. Please check your email.']);
    }

    public function resetPassword(array $data): void
    {
        $errors = Validator::required($data, ['token', 'password', 'password_confirmation']);
        if (($data['password'] ?? '') !== ($data['password_confirmation'] ?? '')) {
            $errors['password_confirmation'] = 'Passwords do not match.';
        }
        if ($message = Validator::password((string) ($data['password'] ?? ''))) {
            $errors['password'] = $message;
        }
        if ($errors) {
            Response::error('Validation failed.', 422, $errors);
        }

        $tokenHash = hash('sha256', (string) $data['token']);
        $stmt = db()->prepare(
            'SELECT pr.id, pr.created_at, pr.consumed_at, u.id AS user_id, u.email,
                    CASE WHEN pr.consumed_at IS NULL AND TIMESTAMPDIFF(SECOND, pr.created_at, NOW()) < 1800 THEN 1 ELSE 0 END AS is_valid
             FROM password_resets pr
             INNER JOIN users u ON u.id = pr.user_id
             WHERE pr.token_hash = ?
             ORDER BY pr.id DESC LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $reset = $stmt->fetch();

        if (!$reset) {
            Response::error('This reset link has expired. Please request a new password reset link.', 422, [
                'action' => 'forgot-password',
                '_form' => 'This reset link has expired. Please request a new password reset link.',
            ]);
        }

        if (!RateLimiter::hit('reset_password', RateLimiter::ip() . '|' . strtolower(trim((string) ($reset['email'] ?? ''))), 6, 3600)) {
            Response::error('Too many password reset attempts. Please try later.', 429);
        }

        if (!$reset || (int) ($reset['is_valid'] ?? 0) !== 1) {
            Response::error('This reset link has expired. Please request a new password reset link.', 422, [
                'action' => 'forgot-password',
                'email' => $reset['email'] ?? null,
                '_form' => 'This reset link has expired. Please request a new password reset link.',
            ]);
        }

        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([
            password_hash((string) $data['password'], PASSWORD_DEFAULT),
            $reset['user_id'],
        ]);
        db()->prepare('UPDATE password_resets SET consumed_at = NOW() WHERE id = ?')->execute([$reset['id']]);
        db()->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ?')->execute([$reset['user_id']]);
        SecurityLog::record('password_reset_completed', (int) $reset['user_id']);

        Response::ok(['message' => 'Password updated. Please log in again.']);
    }

    public function resetLinkStatus(array $data): void
    {
        $errors = Validator::required($data, ['token']);
        if ($errors) {
            Response::error('This password reset link is missing or invalid. Please request a new password reset link.', 422, [
                'action' => 'forgot-password',
                'email' => strtolower(trim((string) ($data['email'] ?? ''))),
                '_form' => 'This password reset link is missing or invalid. Please request a new password reset link.',
            ]);
        }

        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $token = hash('sha256', (string) $data['token']);

        $stmt = db()->prepare(
            'SELECT pr.created_at, pr.consumed_at, u.email,
                    CASE WHEN pr.consumed_at IS NULL AND TIMESTAMPDIFF(SECOND, pr.created_at, NOW()) < 1800 THEN 1 ELSE 0 END AS is_valid
             FROM password_resets pr
             INNER JOIN users u ON u.id = pr.user_id
             WHERE pr.token_hash = ?
             ORDER BY pr.id DESC LIMIT 1'
        );
        $stmt->execute([$token]);
        $reset = $stmt->fetch();

        if (!$reset || (int) ($reset['is_valid'] ?? 0) !== 1) {
            Response::error('This password reset link has expired. Please request a new password reset link.', 422, [
                'action' => 'forgot-password',
                'email' => $reset['email'] ?? $email,
                '_form' => 'This password reset link has expired. Please request a new password reset link.',
            ]);
        }

        Response::ok(['message' => 'Reset link is valid.', 'email' => $reset['email']]);
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

        $otp = (string) random_int(100000, 999999);
        if (!Mailer::send($email, 'Your assessment verification OTP', "Your OTP is {$otp}. It expires in 10 minutes.")) {
            throw new \RuntimeException('OTP email could not be sent. Please check the mail log.');
        }
        $this->storeOtp($userId, $otp);

        return [
            'cooldown' => false,
            'retry_after_seconds' => 0,
        ];
    }

    private function otpCooldown(int $userId, int $cooldownSeconds): array
    {
        $stmt = db()->prepare(
            'SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed_seconds
             FROM email_otps
             WHERE user_id = ?
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$userId]);
        $elapsed = $stmt->fetchColumn();

        if ($elapsed === false || $elapsed === null) {
            return ['blocked' => false, 'retry_after_seconds' => 0];
        }

        $elapsed = max(0, (int) $elapsed);
        if ($elapsed < $cooldownSeconds) {
            return [
                'blocked' => true,
                'retry_after_seconds' => $cooldownSeconds - $elapsed,
            ];
        }

        return ['blocked' => false, 'retry_after_seconds' => 0];
    }

    private function storeOtp(int $userId, string $otp): void
    {
        $stmt = db()->prepare('INSERT INTO email_otps (user_id, otp_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))');
        $stmt->execute([$userId, password_hash($otp, PASSWORD_DEFAULT)]);
    }

    private function normalizeIndianMobile(string $mobile): array
    {
        $raw = trim($mobile);
        $hasIndiaPrefix = str_starts_with($raw, '+91') || str_starts_with($raw, '91');
        $digits = preg_replace('/\D+/', '', $mobile) ?? '';
        if (!$hasIndiaPrefix && strlen($digits) > 10) {
            return [
                'normalized' => null,
                'message' => 'Enter exactly 10 digits, or include +91 for an Indian mobile number with country code.',
            ];
        }
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        if (!preg_match('/^[6-9]\d{9}$/', $digits)) {
            return [
                'normalized' => null,
                'message' => 'Enter a valid 10-digit Indian mobile number starting with 6, 7, 8, or 9.',
            ];
        }

        return [
            'normalized' => '+91' . $digits,
            'message' => '',
        ];
    }

    private function findUserByEmail(string $email): ?array
    {
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        return $user ?: null;
    }
}
