<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class DataQualityService
{
    public static function log(string $jobName, string $status, string $message, int $retryCount = 0, array $context = []): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO data_quality_logs (job_name, status, message, retry_count, context_json, logged_at, created_at)
             VALUES (:job_name, :status, :message, :retry_count, :context_json, NOW(), NOW())'
        );

        $stmt->execute([
            'job_name' => $jobName,
            'status' => $status,
            'message' => $message,
            'retry_count' => $retryCount,
            'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
