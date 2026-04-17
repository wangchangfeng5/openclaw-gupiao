<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

final class JsonResponse
{
    public static function send(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function success(array $data = [], int $status = 200): void
    {
        self::send([
            'ok' => true,
            'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
            'data' => $data,
        ], $status);
    }

    public static function error(string $message, int $status = 400, array $extra = []): void
    {
        self::send([
            'ok' => false,
            'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
            'error' => $message,
            'details' => $extra,
        ], $status);
    }
}
