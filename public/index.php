<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Core\Request;

$path = Request::path();
$method = Request::method();

if (str_starts_with($path, '/api/')) {
    /** @var App\Core\Router $router */
    $router = require base_path('routes/api.php');
    $router->dispatch($method, $path);
    return;
}

if ($path === '/favicon.ico') {
    http_response_code(204);
    return;
}

$requested = realpath(__DIR__ . $path);
$publicRoot = realpath(__DIR__);
if ($requested !== false && $publicRoot !== false && str_starts_with($requested, $publicRoot) && is_file($requested)) {
    $ext = strtolower(pathinfo($requested, PATHINFO_EXTENSION));
    $types = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'json' => 'application/json',
        'txt' => 'text/plain',
        'woff2' => 'font/woff2',
    ];

    if (isset($types[$ext])) {
        header('Content-Type: ' . $types[$ext] . '; charset=utf-8');
    }

    readfile($requested);
    return;
}

require __DIR__ . '/index.html';
