<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : '/';
    }

    public static function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public static function header(string $name, mixed $default = null): mixed
    {
        $normalized = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$normalized] ?? $default;
    }

    public static function body(): array
    {
        static $decoded = null;
        if ($decoded !== null) {
            return $decoded;
        }

        $input = file_get_contents('php://input');
        if ($input === false || trim($input) === '') {
            $decoded = [];
            return $decoded;
        }

        $json = json_decode($input, true);
        if (is_array($json)) {
            $decoded = $json;
            return $decoded;
        }

        $decoded = $_POST;
        return is_array($decoded) ? $decoded : [];
    }
}
