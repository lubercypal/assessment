<?php

function app_config(?string $key = null, mixed $default = null): mixed
{
    static $config = null;

    if ($config === null) {
        $path = __DIR__ . '/env.php';
        if (!file_exists($path)) {
            $path = __DIR__ . '/env.example.php';
        }

        $config = require $path;
    }

    if ($key === null) {
        return $config;
    }

    return $config[$key] ?? $default;
}
