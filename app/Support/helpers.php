<?php

declare(strict_types=1);

use App\Core\Env;

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $base = dirname(__DIR__, 2);
        return $path !== '' ? $base . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path) : $base;
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('now_sql')) {
    function now_sql(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
