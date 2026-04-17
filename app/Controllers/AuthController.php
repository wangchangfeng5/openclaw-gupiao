<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\IngestTargetService;

final class AuthController extends BaseController
{
    public function login(): void
    {
        $body = $this->body();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($username === '' || $password === '') {
            $this->fail('用户名和密码不能为空', 422);
            return;
        }

        if (!AuthService::attempt($username, $password)) {
            AuditService::log('auth.login.failed', 'user', $username);
            $this->fail('用户名或密码错误', 401);
            return;
        }

        AuditService::log('auth.login.success', 'user', $username);
        IngestTargetService::setUsername($username);

        $this->ok([
            'user' => [
                'id' => AuthService::id(),
                'username' => AuthService::username(),
            ],
        ]);
    }

    public function me(): void
    {
        $this->ok([
            'user' => [
                'id' => AuthService::id(),
                'username' => AuthService::username(),
            ],
        ]);
    }

    public function logout(): void
    {
        AuditService::log('auth.logout');
        AuthService::logout();
        $this->ok(['message' => '已退出登录']);
    }
}
