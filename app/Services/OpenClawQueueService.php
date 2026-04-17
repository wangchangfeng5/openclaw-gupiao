<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;

final class OpenClawQueueService
{
    private const MAX_ATTEMPTS = 8;
    private int $userId;

    public function __construct(?int $userId = null)
    {
        $this->userId = $this->resolveUserId($userId);
    }

    public function cachedJobs(): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT *
             FROM openclaw_jobs
             WHERE user_id = :user_id
             ORDER BY enabled DESC, next_run_at ASC, id DESC'
        );
        $stmt->execute(['user_id' => $this->userId]);
        return $stmt->fetchAll() ?: [];
    }

    public function cachedRuns(string $jobId, int $limit = 50): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT run_id, status, started_at, ended_at, duration_ms, summary, error_message, created_at
             FROM openclaw_job_runs
             WHERE user_id = :user_id AND job_id = :job_id
             ORDER BY id DESC
             LIMIT :limit'
        );

        $stmt->bindValue(':user_id', $this->userId, \PDO::PARAM_INT);
        $stmt->bindValue(':job_id', $jobId);
        $stmt->bindValue(':limit', max(1, min(200, $limit)), \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];

        return array_map(static fn(array $row): array => [
            'runId' => $row['run_id'] ?? null,
            'status' => $row['status'] ?? 'unknown',
            'startedAt' => $row['started_at'] ?? null,
            'endedAt' => $row['ended_at'] ?? null,
            'durationMs' => isset($row['duration_ms']) ? (int) $row['duration_ms'] : null,
            'summary' => $row['summary'] ?? '',
            'error' => $row['error_message'] ?? '',
            'source' => 'cache',
            'createdAt' => $row['created_at'] ?? null,
        ], $rows);
    }

    public function summary(): array
    {
        $pdo = Database::connection();

        $pendingStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM openclaw_job_queue
             WHERE user_id = :user_id
               AND status IN ('pending','retry','processing')"
        );
        $pendingStmt->execute(['user_id' => $this->userId]);
        $pending = (int) ($pendingStmt->fetchColumn() ?: 0);

        $failedStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM openclaw_job_queue
             WHERE user_id = :user_id AND status = 'failed'"
        );
        $failedStmt->execute(['user_id' => $this->userId]);
        $failed = (int) ($failedStmt->fetchColumn() ?: 0);

        $latestStmt = $pdo->prepare(
            "SELECT id, action, job_id, status, queued_at, last_error, attempt_count
             FROM openclaw_job_queue
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT 1"
        );
        $latestStmt->execute(['user_id' => $this->userId]);
        $latest = $latestStmt->fetch() ?: null;

        return [
            'pending' => $pending,
            'failed' => $failed,
            'latest' => $latest,
        ];
    }

    public function enqueueCreate(array $payload, string $reason): array
    {
        $localJobId = $this->generateLocalJobId();
        $queuePayload = $payload;
        $queuePayload['local_job_id'] = $localJobId;

        $queueId = $this->enqueue('create', $localJobId, $queuePayload, $reason);
        $this->upsertLocalJobCache($localJobId, $payload, $reason);

        return [
            'queue_id' => $queueId,
            'local_job_id' => $localJobId,
        ];
    }

    public function enqueuePatch(string $jobId, array $payload, string $reason): array
    {
        $queueId = $this->enqueue('patch', $jobId, $payload, $reason);
        $this->applyLocalPatchToCache($jobId, $payload, $reason);

        return [
            'queue_id' => $queueId,
            'job_id' => $jobId,
        ];
    }

    public function enqueueRun(string $jobId, string $reason, array $payload = []): array
    {
        $queueId = $this->enqueue('run', $jobId, $payload, $reason);
        $this->markQueuedRun($jobId, $reason);

        return [
            'queue_id' => $queueId,
            'job_id' => $jobId,
        ];
    }

    public function flush(OpenClawBridgeService $bridge, int $limit = 20): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT *
             FROM openclaw_job_queue
             WHERE user_id = :user_id
               AND status IN ('pending','retry')
               AND (next_retry_at IS NULL OR next_retry_at <= NOW())
             ORDER BY id ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':user_id', $this->userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, min(200, $limit)), \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $processed = 0;
        $success = 0;
        $failed = 0;
        $blocked = false;
        $needSync = false;

        foreach ($rows as $row) {
            $processed++;

            $id = (int) ($row['id'] ?? 0);
            $jobId = (string) ($row['job_id'] ?? '');
            $action = (string) ($row['action'] ?? '');
            $attempt = (int) ($row['attempt_count'] ?? 0) + 1;
            $payload = $this->decodeJson($row['payload_json'] ?? null);

            $this->markProcessing($id, $attempt);

            $result = $this->dispatchQueuedAction($bridge, $action, $jobId, $payload);
            if ($result['ok']) {
                $this->markDone($id, $result['json'] ?? null);

                if ($action === 'create') {
                    $remoteJobId = $this->extractJobId($result['json'] ?? null);
                    if ($remoteJobId === null) {
                        $remoteJobId = $this->resolveRemoteJobIdByName($bridge, $payload);
                    }

                    $localJobId = (string) ($payload['local_job_id'] ?? $jobId);
                    if ($remoteJobId !== null && $localJobId !== '') {
                        $this->mapLocalJobId($localJobId, $remoteJobId);
                    }
                }

                if ($action === 'run' && $jobId !== '') {
                    $this->markQueuedRunsSubmitted($jobId);
                }

                $success++;
                $needSync = true;
                continue;
            }

            $failed++;
            $error = trim((string) ($result['error'] ?? 'unknown error'));
            $status = $this->markRetryOrFail($id, $attempt, $error);

            if ($status === 'failed') {
                AlertService::create(
                    'cron_queue_failed',
                    'OpenClaw queue job failed',
                    "action={$action}, job={$jobId}, attempts={$attempt}, error=" . $this->singleLine($error),
                    'critical',
                    'cron',
                    $jobId !== '' ? $jobId : null,
                    $this->userId
                );
            }

            if ($this->isGatewayUnavailable($error)) {
                $blocked = true;
                break;
            }
        }

        if ($needSync) {
            $bridge->syncJobsToDb($this->userId);
        }

        return [
            'processed' => $processed,
            'success' => $success,
            'failed' => $failed,
            'blocked' => $blocked,
            'queue' => $this->summary(),
        ];
    }

    private function dispatchQueuedAction(OpenClawBridgeService $bridge, string $action, string $jobId, array $payload): array
    {
        if ($action === 'create') {
            return $bridge->addJob($payload);
        }

        if ($action === 'patch') {
            if ($jobId === '' || str_starts_with($jobId, 'local-')) {
                return [
                    'ok' => false,
                    'error' => 'waiting for local job to be mapped to remote job id',
                    'json' => null,
                ];
            }

            return $bridge->editJob($jobId, $payload);
        }

        if ($action === 'run') {
            if ($jobId === '' || str_starts_with($jobId, 'local-')) {
                return [
                    'ok' => false,
                    'error' => 'waiting for local job to be mapped to remote job id',
                    'json' => null,
                ];
            }

            return $bridge->runJob($jobId);
        }

        return [
            'ok' => false,
            'error' => 'unknown queue action: ' . $action,
            'json' => null,
        ];
    }

    private function upsertLocalJobCache(string $localJobId, array $payload, string $reason): void
    {
        $scheduleType = 'cron';
        $scheduleExpr = (string) ($payload['cron'] ?? '');
        if ($scheduleExpr === '' && (string) ($payload['every'] ?? '') !== '') {
            $scheduleType = 'every';
            $scheduleExpr = (string) $payload['every'];
        }
        if ($scheduleExpr === '' && (string) ($payload['at'] ?? '') !== '') {
            $scheduleType = 'at';
            $scheduleExpr = (string) $payload['at'];
        }

        $enabled = array_key_exists('disabled', $payload)
            ? ((bool) $payload['disabled'] ? 0 : 1)
            : 1;

        $raw = [
            'local_shadow' => true,
            'sync_state' => 'queued_create',
            'queue_reason' => $this->singleLine($reason),
            'updated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];

        $stmt = Database::connection()->prepare(
            'INSERT INTO openclaw_jobs (
                user_id, job_id, name, description, schedule_type, schedule_expr, timezone, session_target,
                enabled, delivery_mode, delivery_target, payload_json, raw_json, last_run_at, next_run_at,
                synced_at, created_at, updated_at
             ) VALUES (
                :user_id, :job_id, :name, :description, :schedule_type, :schedule_expr, :timezone, :session_target,
                :enabled, :delivery_mode, :delivery_target, :payload_json, :raw_json, NULL, NULL,
                NULL, NOW(), NOW()
             )
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                description = VALUES(description),
                schedule_type = VALUES(schedule_type),
                schedule_expr = VALUES(schedule_expr),
                timezone = VALUES(timezone),
                session_target = VALUES(session_target),
                enabled = VALUES(enabled),
                payload_json = VALUES(payload_json),
                raw_json = VALUES(raw_json),
                updated_at = NOW()'
        );

        $stmt->execute([
            'user_id' => $this->userId,
            'job_id' => $localJobId,
            'name' => (string) ($payload['name'] ?? 'local queued job'),
            'description' => (string) ($payload['description'] ?? ''),
            'schedule_type' => $scheduleType,
            'schedule_expr' => $scheduleExpr,
            'timezone' => (string) ($payload['tz'] ?? 'Asia/Shanghai'),
            'session_target' => (string) ($payload['session'] ?? 'isolated'),
            'enabled' => $enabled,
            'delivery_mode' => ((bool) ($payload['announce'] ?? true)) ? 'announce' : 'none',
            'delivery_target' => (string) ($payload['to'] ?? ''),
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'raw_json' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function applyLocalPatchToCache(string $jobId, array $payload, string $reason): void
    {
        $stmt = Database::connection()->prepare('SELECT * FROM openclaw_jobs WHERE user_id = :user_id AND job_id = :job_id LIMIT 1');
        $stmt->execute([
            'user_id' => $this->userId,
            'job_id' => $jobId,
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            return;
        }

        $name = (string) ($payload['name'] ?? $row['name']);
        $description = (string) ($payload['description'] ?? $row['description']);
        $timezone = (string) ($payload['tz'] ?? $row['timezone']);
        $sessionTarget = (string) ($payload['session'] ?? $row['session_target']);

        $scheduleType = (string) ($row['schedule_type'] ?? 'cron');
        $scheduleExpr = (string) ($row['schedule_expr'] ?? '');
        if ((string) ($payload['cron'] ?? '') !== '') {
            $scheduleType = 'cron';
            $scheduleExpr = (string) $payload['cron'];
        } elseif ((string) ($payload['every'] ?? '') !== '') {
            $scheduleType = 'every';
            $scheduleExpr = (string) $payload['every'];
        } elseif ((string) ($payload['at'] ?? '') !== '') {
            $scheduleType = 'at';
            $scheduleExpr = (string) $payload['at'];
        }

        $enabled = array_key_exists('enabled', $payload)
            ? ((bool) $payload['enabled'] ? 1 : 0)
            : (int) ($row['enabled'] ?? 1);

        $oldPayload = $this->decodeJson($row['payload_json'] ?? null);
        $mergedPayload = array_merge($oldPayload, $payload);

        $raw = [
            'local_shadow' => str_starts_with($jobId, 'local-'),
            'sync_state' => 'queued_patch',
            'queue_reason' => $this->singleLine($reason),
            'updated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];

        $update = Database::connection()->prepare(
            'UPDATE openclaw_jobs
             SET name = :name,
                 description = :description,
                 schedule_type = :schedule_type,
                 schedule_expr = :schedule_expr,
                 timezone = :timezone,
                 session_target = :session_target,
                 enabled = :enabled,
                 payload_json = :payload_json,
                 raw_json = :raw_json,
                 updated_at = NOW()
             WHERE user_id = :user_id AND job_id = :job_id'
        );

        $update->execute([
            'name' => $name,
            'description' => $description,
            'schedule_type' => $scheduleType,
            'schedule_expr' => $scheduleExpr,
            'timezone' => $timezone,
            'session_target' => $sessionTarget,
            'enabled' => $enabled,
            'payload_json' => json_encode($mergedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'raw_json' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'user_id' => $this->userId,
            'job_id' => $jobId,
        ]);
    }

    private function markQueuedRun(string $jobId, string $reason): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO openclaw_job_runs (user_id, job_id, run_id, status, started_at, ended_at, duration_ms, summary, error_message, raw_json, created_at)
             VALUES (:user_id, :job_id, :run_id, :status, NOW(), NULL, NULL, :summary, :error_message, :raw_json, NOW())'
        );

        $runId = 'queued-' . date('YmdHis') . '-' . substr(sha1($jobId . microtime(true)), 0, 8);

        $stmt->execute([
            'user_id' => $this->userId,
            'job_id' => $jobId,
            'run_id' => $runId,
            'status' => 'queued',
            'summary' => 'queued locally, waiting for gateway',
            'error_message' => $this->singleLine($reason),
            'raw_json' => json_encode(['queued' => true, 'reason' => $this->singleLine($reason)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function markQueuedRunsSubmitted(string $jobId): void
    {
        $stmt = Database::connection()->prepare(
            "UPDATE openclaw_job_runs
             SET status = 'submitted',
                 summary = 'queued run submitted to OpenClaw',
                 error_message = '',
                 ended_at = NOW()
             WHERE user_id = :user_id
               AND job_id = :job_id
               AND status = 'queued'"
        );

        $stmt->execute([
            'user_id' => $this->userId,
            'job_id' => $jobId,
        ]);
    }

    private function mapLocalJobId(string $localJobId, string $remoteJobId): void
    {
        if ($localJobId === '' || $remoteJobId === '' || !str_starts_with($localJobId, 'local-')) {
            return;
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $check = $pdo->prepare('SELECT id FROM openclaw_jobs WHERE user_id = :user_id AND job_id = :job_id LIMIT 1');
            $check->execute([
                'user_id' => $this->userId,
                'job_id' => $remoteJobId,
            ]);
            $remoteExists = (bool) $check->fetch();

            if ($remoteExists) {
                $del = $pdo->prepare('DELETE FROM openclaw_jobs WHERE user_id = :user_id AND job_id = :job_id');
                $del->execute([
                    'user_id' => $this->userId,
                    'job_id' => $localJobId,
                ]);
            } else {
                $upd = $pdo->prepare(
                    'UPDATE openclaw_jobs
                     SET job_id = :remote_job_id,
                         synced_at = NOW(),
                         updated_at = NOW()
                     WHERE user_id = :user_id AND job_id = :local_job_id'
                );
                $upd->execute([
                    'remote_job_id' => $remoteJobId,
                    'user_id' => $this->userId,
                    'local_job_id' => $localJobId,
                ]);
            }

            $queueUpd = $pdo->prepare(
                "UPDATE openclaw_job_queue
                 SET job_id = :remote_job_id,
                     updated_at = NOW()
                 WHERE user_id = :user_id
                   AND job_id = :local_job_id
                   AND status IN ('pending','retry','processing')"
            );
            $queueUpd->execute([
                'remote_job_id' => $remoteJobId,
                'user_id' => $this->userId,
                'local_job_id' => $localJobId,
            ]);

            $runsUpd = $pdo->prepare('UPDATE openclaw_job_runs SET job_id = :remote_job_id WHERE user_id = :user_id AND job_id = :local_job_id');
            $runsUpd->execute([
                'remote_job_id' => $remoteJobId,
                'user_id' => $this->userId,
                'local_job_id' => $localJobId,
            ]);

            $pdo->commit();
        } catch (\Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    private function extractJobId(mixed $json): ?string
    {
        if (!is_array($json)) {
            return null;
        }

        $candidates = [
            $json['id'] ?? null,
            $json['jobId'] ?? null,
            $json['job']['id'] ?? null,
            $json['job']['jobId'] ?? null,
            $json['data']['id'] ?? null,
            $json['data']['jobId'] ?? null,
            $json[0]['id'] ?? null,
            $json[0]['jobId'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveRemoteJobIdByName(OpenClawBridgeService $bridge, array $payload): ?string
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $list = $bridge->listJobs();
        if (!$list['ok']) {
            return null;
        }

        $jobs = $list['jobs'] ?? [];
        if (!is_array($jobs)) {
            return null;
        }

        for ($i = count($jobs) - 1; $i >= 0; $i--) {
            $job = $jobs[$i] ?? null;
            if (!is_array($job)) {
                continue;
            }

            if (trim((string) ($job['name'] ?? '')) !== $name) {
                continue;
            }

            $id = (string) ($job['id'] ?? $job['jobId'] ?? '');
            if ($id !== '') {
                return $id;
            }
        }

        return null;
    }

    private function enqueue(string $action, ?string $jobId, array $payload, string $reason): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO openclaw_job_queue (
                user_id, action, job_id, payload_json, status, attempt_count, last_error,
                queued_at, last_attempt_at, next_retry_at, completed_at, result_json,
                created_at, updated_at
             ) VALUES (
                :user_id, :action, :job_id, :payload_json, :status, :attempt_count, :last_error,
                NOW(), NULL, NOW(), NULL, NULL,
                NOW(), NOW()
             )'
        );

        $stmt->execute([
            'user_id' => $this->userId,
            'action' => $action,
            'job_id' => $jobId,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'pending',
            'attempt_count' => 0,
            'last_error' => $this->singleLine($reason),
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    private function markProcessing(int $id, int $attempt): void
    {
        $stmt = Database::connection()->prepare(
            "UPDATE openclaw_job_queue
             SET status = 'processing',
                 attempt_count = :attempt_count,
                 last_attempt_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id AND user_id = :user_id"
        );

        $stmt->execute([
            'attempt_count' => $attempt,
            'id' => $id,
            'user_id' => $this->userId,
        ]);
    }

    private function markDone(int $id, mixed $resultJson): void
    {
        $stmt = Database::connection()->prepare(
            "UPDATE openclaw_job_queue
             SET status = 'done',
                 completed_at = NOW(),
                 result_json = :result_json,
                 updated_at = NOW()
             WHERE id = :id AND user_id = :user_id"
        );

        $stmt->execute([
            'result_json' => json_encode($resultJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'id' => $id,
            'user_id' => $this->userId,
        ]);
    }

    private function markRetryOrFail(int $id, int $attempt, string $error): string
    {
        $status = $attempt >= self::MAX_ATTEMPTS ? 'failed' : 'retry';
        $nextRetry = (new DateTimeImmutable())->modify('+' . $this->retryDelaySeconds($attempt) . ' seconds')->format('Y-m-d H:i:s');

        $stmt = Database::connection()->prepare(
            'UPDATE openclaw_job_queue
             SET status = :status,
                 last_error = :last_error,
                 next_retry_at = :next_retry_at,
                 completed_at = :completed_at,
                 updated_at = NOW()
             WHERE id = :id AND user_id = :user_id'
        );

        $stmt->execute([
            'status' => $status,
            'last_error' => $this->singleLine($error),
            'next_retry_at' => $status === 'retry' ? $nextRetry : null,
            'completed_at' => $status === 'failed' ? (new DateTimeImmutable())->format('Y-m-d H:i:s') : null,
            'id' => $id,
            'user_id' => $this->userId,
        ]);

        return $status;
    }

    private function retryDelaySeconds(int $attempt): int
    {
        $delay = 20 * $attempt * $attempt;
        return max(20, min(300, $delay));
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function generateLocalJobId(): string
    {
        try {
            $suffix = bin2hex(random_bytes(3));
        } catch (\Throwable) {
            $suffix = substr(md5(uniqid('', true)), 0, 6);
        }

        return 'local-' . date('YmdHis') . '-' . $suffix;
    }

    private function singleLine(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    private function isGatewayUnavailable(string $error): bool
    {
        $text = strtolower($error);
        $keywords = [
            'pairing required',
            'gateway closed',
            'gateway connect failed',
            'connect failed',
            'eperm',
            'operation not permitted',
            'device-auth.json',
            'timeout',
        ];

        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function resolveUserId(?int $userId): int
    {
        if ($userId !== null && $userId > 0) {
            return $userId;
        }

        return (int) (AuthService::id() ?? 0);
    }
}
