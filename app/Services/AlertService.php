<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class AlertService
{
    public static function create(
        string $type,
        string $title,
        string $message,
        string $severity = 'info',
        ?string $relatedType = null,
        ?string $relatedId = null,
        ?int $userId = null
    ): void
    {
        $userId = self::resolveUserId($userId);
        $stmt = Database::connection()->prepare(
            'INSERT INTO alerts (user_id, alert_type, title, message, severity, status, related_type, related_id, triggered_at, created_at, updated_at)
             VALUES (:user_id, :alert_type, :title, :message, :severity, :status, :related_type, :related_id, NOW(), NOW(), NOW())'
        );

        $stmt->execute([
            'user_id' => $userId > 0 ? $userId : null,
            'alert_type' => $type,
            'title' => $title,
            'message' => $message,
            'severity' => $severity,
            'status' => 'new',
            'related_type' => $relatedType,
            'related_id' => $relatedId,
        ]);
    }

    public static function latest(int $limit = 50, ?int $userId = null): array
    {
        $userId = self::resolveUserId($userId);
        $stmt = Database::connection()->prepare('SELECT * FROM alerts WHERE user_id = :user_id ORDER BY triggered_at DESC, id DESC LIMIT :limit');
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, min(200, $limit)), \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    private static function resolveUserId(?int $userId): int
    {
        if ($userId !== null && $userId > 0) {
            return $userId;
        }

        return (int) (AuthService::id() ?? 0);
    }
}
