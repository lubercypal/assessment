<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Services/ErrorLogger.php';

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    \App\Services\ErrorLogger::log('php-error', $message, [
        ...\App\Services\ErrorLogger::requestContext(),
        'severity' => $severity,
        'file' => $file,
        'line' => $line,
    ]);

    return false;
});

set_exception_handler(function (Throwable $exception): void {
    \App\Services\ErrorLogger::exception($exception, \App\Services\ErrorLogger::requestContext());

    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => ['message' => 'Server error.']]);
    } else {
        fwrite(STDERR, "Server error.\n");
    }
});

register_shutdown_function(function (): void {
    $error = error_get_last();
    if (!$error) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'] ?? 0, $fatalTypes, true)) {
        return;
    }

    \App\Services\ErrorLogger::log('fatal', $error['message'] ?? 'Fatal error', [
        ...\App\Services\ErrorLogger::requestContext(),
        'file' => $error['file'] ?? null,
        'line' => $error['line'] ?? null,
        'type' => $error['type'] ?? null,
    ]);
});

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($path)) {
        require_once $path;
    }
});
