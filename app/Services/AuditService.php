<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;

final class AuditService
{
    public static function log(string $action, string $targetType = '', ?string $targetId = null, array $payload = []): void
    {
        $userId = AuthService::id();
        $stmt = Database::connection()->prepare(
            'INSERT INTO audit_logs (user_id, action, target_type, target_id, request_path, ip_addr, payload_json, created_at)
             VALUES (:user_id, :action, :target_type, :target_id, :request_path, :ip_addr, :payload_json, NOW())'
        );

        $stmt->execute([
            'user_id' => $userId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'request_path' => Request::path(),
            'ip_addr' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
