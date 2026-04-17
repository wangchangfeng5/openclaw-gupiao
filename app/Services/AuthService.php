<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class AuthService
{
    public static function attempt(string $username, string $password): bool
    {
        $stmt = Database::connection()->prepare('SELECT id, username, password_hash FROM users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = (string) $user['username'];
        $_SESSION['login_at'] = time();

        $update = Database::connection()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        $update->execute(['id' => (int) $user['id']]);

        return true;
    }

    public static function check(): bool
    {
        return isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']);
    }

    public static function id(): ?int
    {
        if (!self::check()) {
            return null;
        }

        return (int) $_SESSION['user_id'];
    }

    public static function username(): ?string
    {
        return self::check() ? (string) ($_SESSION['username'] ?? '') : null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
