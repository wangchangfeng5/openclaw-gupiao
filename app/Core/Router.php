<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\AuthService;
use App\Support\JsonResponse;

final class Router
{
    /** @var array<int, array{method:string, pattern:string, regex:string, handler:callable, guard:string}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, string $guard = 'auth'): void
    {
        $regex = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => $regex,
            'handler' => $handler,
            'guard' => $guard,
        ];
    }

    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            if ($route['guard'] === 'auth' && !AuthService::check()) {
                JsonResponse::error('Unauthorized', 401);
                return;
            }

            if ($route['guard'] === 'internal') {
                $provided = Request::header('X-Ingest-Key', '');
                if (!is_string($provided) || $provided === '' || !hash_equals((string) env('INGEST_API_KEY', ''), $provided)) {
                    JsonResponse::error('Forbidden', 403);
                    return;
                }
            }

            // Release PHP session file lock for non-auth routes.
            // This avoids one long request blocking all concurrent API requests of the same user.
            if (
                session_status() === PHP_SESSION_ACTIVE
                && !str_starts_with($route['pattern'], '/api/auth/')
            ) {
                session_write_close();
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (!is_int($key)) {
                    $params[$key] = $value;
                }
            }

            call_user_func($route['handler'], $params);
            return;
        }

        JsonResponse::error('Not Found', 404);
    }
}
