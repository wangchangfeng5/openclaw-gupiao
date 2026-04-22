<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Services\AuditService;
use App\Services\OpenClawBridgeService;
use App\Services\OpenClawQueueService;
use DateTimeImmutable;

final class SystemHealthController extends BaseController
{
    public function overview(): void
    {
        $userId = $this->userId();
        $pdo = Database::connection();
        $queue = new OpenClawQueueService($userId);

        $dbStart = microtime(true);
        $pdo->query('SELECT 1')->fetchColumn();
        $dbPingMs = round((microtime(true) - $dbStart) * 1000, 2);

        $freshness = [
            'market_quotes' => $this->maxTimestamp('market_quotes', 'quote_time'),
            'sector_strength' => $this->maxTimestamp('sector_strength', 'sample_time'),
            'news_feed' => $this->maxTimestamp('news_feed', 'published_at'),
            'openclaw_suggestions' => $this->maxTimestamp('openclaw_suggestions', 'suggested_at', true),
            'watchlist_review_snapshots' => $this->maxTimestamp('watchlist_review_snapshots', 'created_at', true),
            'market_close_rankings' => $this->maxTimestamp('market_close_rankings', 'snapshot_time'),
        ];

        $latency = [
            'quote_delay_min' => $this->minutesSince($freshness['market_quotes']),
            'sector_delay_min' => $this->minutesSince($freshness['sector_strength']),
            'news_delay_min' => $this->minutesSince($freshness['news_feed']),
            'suggestion_delay_min' => $this->minutesSince($freshness['openclaw_suggestions']),
            'review_delay_min' => $this->minutesSince($freshness['watchlist_review_snapshots']),
            'close_rank_delay_min' => $this->minutesSince($freshness['market_close_rankings']),
            'db_ping_ms' => $dbPingMs,
        ];

        $queueSummary = $queue->summary();
        $queueSuccess = $queue->successRate(24);
        $quality24h = $this->qualitySummary24h();
        $ingestState = $this->readIngestState();
        $recentErrors = $this->recentErrors($userId, 30);

        $this->ok([
            'generated_at' => now_sql(),
            'user_id' => $userId,
            'freshness' => $freshness,
            'latency' => $latency,
            'queue' => [
                'summary' => $queueSummary,
                'success_24h' => $queueSuccess,
            ],
            'quality_24h' => $quality24h,
            'ingest_state' => $ingestState,
            'recent_errors' => $recentErrors,
        ]);
    }

    public function queue(): void
    {
        $userId = $this->userId();
        $queue = new OpenClawQueueService($userId);
        $limit = max(1, min(500, (int) $this->query('limit', 120)));
        $status = strtolower(trim((string) $this->query('status', '')));

        $rows = $queue->listQueue($limit, $status);

        $this->ok([
            'rows' => $rows,
            'summary' => $queue->summary(),
            'success_24h' => $queue->successRate(24),
        ]);
    }

    public function retryOne(array $params): void
    {
        $userId = $this->userId();
        $queue = new OpenClawQueueService($userId);
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            $this->fail('queue id invalid', 422);
            return;
        }

        $item = $queue->findQueueItem($id);
        if ($item === null) {
            $this->fail('queue item not found', 404);
            return;
        }

        $status = (string) ($item['status'] ?? '');
        if (!in_array($status, ['failed', 'retry'], true)) {
            $this->fail('only failed/retry items can be retried', 422, ['status' => $status]);
            return;
        }

        $updated = $queue->markForRetry($id);
        if (!$updated) {
            $this->fail('queue item retry update failed', 500);
            return;
        }

        $flushNow = $this->queryBool('flush', true);
        $flush = null;
        if ($flushNow) {
            $flush = $queue->flush(new OpenClawBridgeService(), 20);
        }

        $after = $queue->findQueueItem($id);
        AuditService::log('system.health.queue.retry_one', 'cron_queue', (string) ($item['job_id'] ?? ''), [
            'queue_id' => $id,
            'before_status' => $status,
            'flush_now' => $flushNow,
            'flush' => $flush,
        ]);

        $this->ok([
            'retried' => true,
            'item' => $after,
            'flush' => $flush,
            'summary' => $queue->summary(),
        ]);
    }

    public function retryFailed(): void
    {
        $userId = $this->userId();
        $queue = new OpenClawQueueService($userId);
        $limit = max(1, min(1000, (int) $this->query('limit', 120)));
        $count = $queue->markFailedForRetry($limit);

        $flushNow = $this->queryBool('flush', true);
        $flush = null;
        if ($flushNow && $count > 0) {
            $flush = $queue->flush(new OpenClawBridgeService(), max(20, min(200, $count)));
        }

        AuditService::log('system.health.queue.retry_failed', 'cron_queue', null, [
            'retry_count' => $count,
            'limit' => $limit,
            'flush_now' => $flushNow,
            'flush' => $flush,
        ]);

        $this->ok([
            'retried_count' => $count,
            'flush' => $flush,
            'summary' => $queue->summary(),
            'success_24h' => $queue->successRate(24),
        ]);
    }

    private function maxTimestamp(string $table, string $column, bool $withUser = false): ?string
    {
        $allowed = [
            'market_quotes' => ['quote_time'],
            'sector_strength' => ['sample_time'],
            'news_feed' => ['published_at'],
            'openclaw_suggestions' => ['suggested_at'],
            'watchlist_review_snapshots' => ['created_at'],
            'market_close_rankings' => ['snapshot_time'],
        ];
        if (!isset($allowed[$table]) || !in_array($column, $allowed[$table], true)) {
            return null;
        }

        $sql = "SELECT MAX({$column}) AS mx FROM {$table}";
        if ($withUser) {
            $sql .= ' WHERE user_id = :user_id';
        }

        $stmt = Database::connection()->prepare($sql);
        if ($withUser) {
            $stmt->bindValue(':user_id', $this->userId(), \PDO::PARAM_INT);
        }
        $stmt->execute();
        $row = $stmt->fetch();
        $value = is_array($row) ? ($row['mx'] ?? null) : null;
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function minutesSince(?string $ts): ?int
    {
        if ($ts === null || trim($ts) === '') {
            return null;
        }
        $unix = strtotime($ts);
        if ($unix === false) {
            return null;
        }
        return max(0, (int) floor((time() - $unix) / 60));
    }

    /**
     * @return array<string, mixed>
     */
    private function qualitySummary24h(): array
    {
        $since = (new DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s');
        $stmt = Database::connection()->prepare(
            'SELECT status, COUNT(*) AS c
             FROM data_quality_logs
             WHERE logged_at >= :since
             GROUP BY status'
        );
        $stmt->execute(['since' => $since]);
        $rows = $stmt->fetchAll() ?: [];

        $ok = 0;
        $warn = 0;
        $error = 0;
        foreach ($rows as $row) {
            $status = strtolower((string) ($row['status'] ?? ''));
            $count = (int) ($row['c'] ?? 0);
            if ($status === 'ok') {
                $ok += $count;
            } elseif ($status === 'warn') {
                $warn += $count;
            } elseif ($status === 'error') {
                $error += $count;
            }
        }

        $total = $ok + $warn + $error;
        $successRate = $total > 0 ? round($ok * 100 / $total, 2) : null;

        return [
            'since' => $since,
            'ok' => $ok,
            'warn' => $warn,
            'error' => $error,
            'total' => $total,
            'success_rate' => $successRate,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentErrors(int $userId, int $limit = 30): array
    {
        $limit = max(1, min(80, $limit));
        $errors = [];

        $queueStmt = Database::connection()->prepare(
            "SELECT id, action, job_id, status, attempt_count, last_error, updated_at
             FROM openclaw_job_queue
             WHERE user_id = :user_id
               AND status IN ('retry', 'failed')
               AND last_error IS NOT NULL
               AND last_error <> ''
             ORDER BY updated_at DESC, id DESC
             LIMIT :limit"
        );
        $queueStmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $queueStmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $queueStmt->execute();
        foreach ($queueStmt->fetchAll() ?: [] as $row) {
            $errors[] = [
                'source' => 'openclaw_queue',
                'level' => ((string) ($row['status'] ?? '') === 'failed') ? 'error' : 'warn',
                'occurred_at' => (string) ($row['updated_at'] ?? ''),
                'title' => 'Queue ' . (string) ($row['status'] ?? 'unknown'),
                'message' => (string) ($row['last_error'] ?? ''),
                'ref' => [
                    'queue_id' => (int) ($row['id'] ?? 0),
                    'job_id' => (string) ($row['job_id'] ?? ''),
                    'action' => (string) ($row['action'] ?? ''),
                    'attempt_count' => (int) ($row['attempt_count'] ?? 0),
                ],
            ];
        }

        $qualityStmt = Database::connection()->prepare(
            "SELECT id, job_name, status, message, retry_count, logged_at
             FROM data_quality_logs
             WHERE status IN ('warn', 'error')
             ORDER BY logged_at DESC, id DESC
             LIMIT :limit"
        );
        $qualityStmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $qualityStmt->execute();
        foreach ($qualityStmt->fetchAll() ?: [] as $row) {
            $errors[] = [
                'source' => 'data_quality',
                'level' => (string) ($row['status'] ?? 'warn'),
                'occurred_at' => (string) ($row['logged_at'] ?? ''),
                'title' => (string) ($row['job_name'] ?? 'quality_job'),
                'message' => (string) ($row['message'] ?? ''),
                'ref' => [
                    'log_id' => (int) ($row['id'] ?? 0),
                    'retry_count' => (int) ($row['retry_count'] ?? 0),
                ],
            ];
        }

        $alertStmt = Database::connection()->prepare(
            "SELECT id, alert_type, severity, title, message, triggered_at
             FROM alerts
             WHERE user_id = :user_id
               AND severity IN ('critical', 'warning')
             ORDER BY triggered_at DESC, id DESC
             LIMIT :limit"
        );
        $alertStmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $alertStmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $alertStmt->execute();
        foreach ($alertStmt->fetchAll() ?: [] as $row) {
            $level = strtolower((string) ($row['severity'] ?? 'warning')) === 'critical' ? 'error' : 'warn';
            $errors[] = [
                'source' => 'alert',
                'level' => $level,
                'occurred_at' => (string) ($row['triggered_at'] ?? ''),
                'title' => (string) ($row['title'] ?? $row['alert_type'] ?? 'alert'),
                'message' => (string) ($row['message'] ?? ''),
                'ref' => [
                    'alert_id' => (int) ($row['id'] ?? 0),
                    'alert_type' => (string) ($row['alert_type'] ?? ''),
                ],
            ];
        }

        usort($errors, static function (array $a, array $b): int {
            $at = strtotime((string) ($a['occurred_at'] ?? '')) ?: 0;
            $bt = strtotime((string) ($b['occurred_at'] ?? '')) ?: 0;
            return $bt <=> $at;
        });

        return array_slice($errors, 0, $limit);
    }

    /**
     * @return array<string, mixed>
     */
    private function readIngestState(): array
    {
        $file = base_path('storage/runtime/ingest_once_state.json');
        if (!is_file($file)) {
            return [
                'status' => 'idle',
                'message' => 'no ingest run yet',
            ];
        }

        $raw = @file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            return ['status' => 'idle', 'message' => 'empty ingest state'];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : ['status' => 'idle', 'message' => 'invalid ingest state'];
    }

    private function queryBool(string $key, bool $default): bool
    {
        $value = $this->query($key, null);
        if ($value === null) {
            $body = $this->body();
            if (array_key_exists($key, $body)) {
                $value = $body[$key];
            }
        }

        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $default;
    }
}
