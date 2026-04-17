<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;

final class AuditController extends BaseController
{
    public function index(): void
    {
        $userId = $this->userId();
        $limit = max(1, min(500, (int) $this->query('limit', 100)));
        $stmt = Database::connection()->prepare('SELECT * FROM audit_logs WHERE user_id = :user_id ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $this->ok(['logs' => $stmt->fetchAll() ?: []]);
    }
}
