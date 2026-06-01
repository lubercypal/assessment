<?php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

if (str_starts_with($path, '/api/')) {
    $_GET['route'] = trim(substr($path, 5), '/');
    require __DIR__ . '/api/index.php';
    return true;
}

if ($path === '/') {
    require __DIR__ . '/login.php';
    return true;
}

$page = __DIR__ . '/' . trim($path, '/') . '.php';
if (is_file($page)) {
    require $page;
    return true;
}

http_response_code(404);
echo 'Not Found';
return true;
