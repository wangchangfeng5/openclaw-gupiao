<?php

declare(strict_types=1);

return [
    'host' => (string) env('DB_HOST', '127.0.0.1'),
    'port' => (string) env('DB_PORT', '3306'),
    'database' => (string) env('DB_DATABASE', 'openclaw_invest'),
    'username' => (string) env('DB_USERNAME', 'root'),
    'password' => (string) env('DB_PASSWORD', ''),
    'charset' => (string) env('DB_CHARSET', 'utf8mb4'),
];
