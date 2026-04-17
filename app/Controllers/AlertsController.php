<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;

final class AlertsController extends BaseController
{
    public function index(): void
    {
        $userId = $this->userId();
        $limit = max(1, min(200, (int) $this->query('limit', 100)));
        $stmt = Database::connection()->prepare('SELECT * FROM alerts WHERE user_id = :user_id ORDER BY triggered_at DESC, id DESC LIMIT :limit');
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $this->ok(['alerts' => $stmt->fetchAll() ?: []]);
    }

    public function markRead(array $params): void
    {
        $userId = $this->userId();
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            $this->fail('alert id invalid', 422);
            return;
        }

        $stmt = Database::connection()->prepare('UPDATE alerts SET status = "read", read_at = NOW(), updated_at = NOW() WHERE id = :id AND user_id = :user_id');
        $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
        ]);

        $this->ok(['id' => $id]);
    }
}
