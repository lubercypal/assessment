<?php

declare(strict_types=1);

use App\Controllers\AssessmentController;
use App\Controllers\AuthController;
use App\Core\Request;
use App\Core\Response;

require_once __DIR__ . '/../app/bootstrap.php';

header('Access-Control-Allow-Origin: same-origin');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = trim((string) ($_GET['route'] ?? ''), '/');
$body = Request::json();

try {
    $csrfProtected = [
        'auth/logout',
        'attempts/start',
    ];

    if ($method === 'POST') {
        $needsCsrf = in_array($path, $csrfProtected, true)
            || preg_match('#^attempts/\d+/(answer|submit)$#', $path);

        if ($needsCsrf) {
            App\Services\AuthService::validateCsrf();
        }
    }

    $auth = new AuthController();
    $assessment = new AssessmentController();

    if ($method === 'POST' && $path === 'auth/register') {
        $auth->register($body);
    }
    if ($method === 'POST' && $path === 'auth/verify-email') {
        $auth->verifyEmail($body);
    }
    if ($method === 'POST' && $path === 'auth/resend-otp') {
        $auth->resendOtp($body);
    }
    if ($method === 'POST' && $path === 'auth/login') {
        $auth->login($body);
    }
    if ($method === 'POST' && $path === 'auth/forgot-password') {
        $auth->forgotPassword($body);
    }
    if ($method === 'POST' && $path === 'auth/reset-password') {
        $auth->resetPassword($body);
    }
    if ($method === 'GET' && $path === 'auth/me') {
        $auth->me();
    }
    if ($method === 'POST' && $path === 'auth/logout') {
        $auth->logout();
    }

    if ($method === 'GET' && $path === 'categories') {
        $assessment->categories();
    }
    if ($method === 'GET' && $path === 'topics') {
        $assessment->topics($_GET);
    }
    if ($method === 'POST' && $path === 'attempts/start') {
        $assessment->start($body);
    }
    if ($method === 'GET' && preg_match('#^attempts/(\d+)/question$#', $path, $matches)) {
        $assessment->question((int) $matches[1], $_GET);
    }
    if ($method === 'POST' && preg_match('#^attempts/(\d+)/answer$#', $path, $matches)) {
        $assessment->saveAnswer((int) $matches[1], $body);
    }
    if ($method === 'POST' && preg_match('#^attempts/(\d+)/submit$#', $path, $matches)) {
        $assessment->submit((int) $matches[1]);
    }
    if ($method === 'GET' && preg_match('#^attempts/(\d+)/result$#', $path, $matches)) {
        $assessment->result((int) $matches[1]);
    }

    Response::error('Route not found.', 404);
} catch (Throwable $exception) {
    $message = strtolower($exception->getMessage());
    $status = 500;
    if (str_contains($message, 'token') || str_contains($message, 'session') || str_contains($message, 'authentication')) {
        $status = 401;
    }
    if (str_contains($message, 'csrf')) {
        $status = 419;
    }
    Response::error($status !== 500 ? $exception->getMessage() : 'Server error.', $status);
}
