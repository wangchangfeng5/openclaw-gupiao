<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;

final class DataQualityController extends BaseController
{
    public function index(): void
    {
        $limit = max(1, min(500, (int) $this->query('limit', 100)));
        $stmt = Database::connection()->prepare('SELECT * FROM data_quality_logs ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $this->ok(['logs' => $stmt->fetchAll() ?: []]);
    }
}