<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Services\AuthService;
use App\Support\JsonResponse;

abstract class BaseController
{
    protected function body(): array
    {
        return Request::body();
    }

    protected function query(string $key, mixed $default = null): mixed
    {
        return Request::query($key, $default);
    }

    protected function ok(array $data = [], int $status = 200): void
    {
        JsonResponse::success($data, $status);
    }

    protected function fail(string $message, int $status = 400, array $extra = []): void
    {
        JsonResponse::error($message, $status, $extra);
    }

    protected function userId(): int
    {
        return (int) (AuthService::id() ?? 0);
    }
}
