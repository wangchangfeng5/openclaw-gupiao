<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'OpenClaw Invest Panel'),
    'env' => env('APP_ENV', 'local'),
    'debug' => filter_var((string) env('APP_DEBUG', 'true'), FILTER_VALIDATE_BOOL),
    'url' => env('APP_URL', 'http://127.0.0.1:8080'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Shanghai'),
];
