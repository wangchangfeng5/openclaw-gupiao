<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Services\AlertService;
use App\Services\AuditService;
use App\Services\OpenClawBridgeService;
use App\Services\OpenClawQueueService;

final class OpenClawCronController extends BaseController
{
    private OpenClawBridgeService $bridge;

    public function __construct()
    {
        $this->bridge = new OpenClawBridgeService();
    }

    public function listJobs(): void
    {
        $userId = $this->userId();
        $queue = new OpenClawQueueService($userId);

        $sync = $this->queryBool('sync', false);
        $flushEnabled = $this->queryBool('flush', false);
        $flush = [
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'blocked' => false,
            'queue' => $queue->summary(),
        ];

        if ($flushEnabled) {
            $flush = $queue->flush($this->bridge, 12);
        }

        $degraded = false;
        $reason = null;

        if ($sync) {
            $result = $this->bridge->syncJobsToDb($userId);
            if (!$result['ok']) {
                $degraded = true;
                $reason = (string) ($result['error'] ?? 'unknown');
            }
        }

        $jobs = $queue->cachedJobs();
        $queueSummary = $queue->summary();

        $this->ok([
            'jobs' => $jobs,
            'degraded' => $degraded,
            'reason' => $reason,
            'from_cache' => $degraded,
            'queue' => $queueSummary,
            'queue_flush' => $flush,
        ]);
    }

    public function flushQueue(): void
    {
        $userId = $this->userId();
        $queue = new OpenClawQueueService($userId);

        $result = $queue->flush($this->bridge, 30);
        AuditService::log('cron.queue.flush', 'cron_queue', null, $result);

        $this->ok([
            'result' => $result,
            'queue' => $queue->summary(),
        ]);
    }

    public function createJob(): void
    {
        $userId = $this->userId();
        $queue = new OpenClawQueueService($userId);
        $payload = $this->body();
        $result = $this->bridge->addJob($payload);

        if (!$result['ok']) {
            $reason = (string) ($result['error'] ?? 'unknown');
            $queued = $queue->enqueueCreate($payload, $reason);

            AlertService::create(
                'cron_degraded',
                'OpenClaw gateway unavailable; queued locally',
                'Create-job request has been queued and will retry automatically.',
                'warning',
                'cron',
                $queued['local_job_id'],
                $userId
            );

            AuditService::log('cron.create.queued', 'cron', $queued['local_job_id'], [
                'payload' => $payload,
                'reason' => $reason,
                'queue_id' => $queued['queue_id'],
            ]);

            $this->ok([
                'queued' => true,
                'degraded' => true,
                'queue_id' => $queued['queue_id'],
                'job_id' => $queued['local_job_id'],
                'reason' => $reason,
            ], 202);
            return;
        }

        $this->bridge->syncJobsToDb($userId);
        AuditService::log('cron.create', 'cron', null, $payload);

        $this->ok([
            'queued' => false,
            'degraded' => false,
            'result' => $result['json'] ?? trim((string) ($result['stdout'] ?? '')),
        ], 201);
    }

    public function patchJob(array $params): void
    {
        $userId = $this->userId();
        $queue = new OpenClawQueueService($userId);

        $jobId = (string) ($params['id'] ?? '');
        if ($jobId === '') {
            $this->fail('job id required', 422);
            return;
        }

        $payload = $this->body();
        $result = $this->bridge->editJob($jobId, $payload);

        if (!$result['ok']) {
            $reason = (string) ($result['error'] ?? 'unknown');
            $queued = $queue->enqueuePatch($jobId, $payload, $reason);

            AlertService::create(
                'cron_degraded',
                'OpenClaw gateway unavailable; edit queued',
                "Patch request for job {$jobId} has been queued.",
                'warning',
                'cron',
                $jobId,
                $userId
            );

            AuditService::log('cron.patch.queued', 'cron', $jobId, [
                'payload' => $payload,
                'reason' => $reason,
                'queue_id' => $queued['queue_id'],
            ]);

            $this->ok([
                'queued' => true,
                'degraded' => true,
                'queue_id' => $queued['queue_id'],
                'job_id' => $jobId,
                'reason' => $reason,
            ], 202);
            return;
        }

        $this->bridge->syncJobsToDb($userId);
        AuditService::log('cron.patch', 'cron', $jobId, $payload);

        $this->ok([
            'queued' => false,
            'degraded' => false,
            'result' => $result['json'] ?? trim((string) ($result['stdout'] ?? '')),
        ]);
    }

    public function runJob(array $params): void
    {
        $userId = $this->userId();
        $queue = new OpenClawQueueService($userId);

        $jobId = (string) ($params['id'] ?? '');
        if ($jobId === '') {
            $this->fail('job id required', 422);
            return;
        }

        $result = $this->bridge->runJob($jobId);
        if (!$result['ok']) {
            $reason = (string) ($result['error'] ?? 'unknown');
            $queued = $queue->enqueueRun($jobId, $reason);

            AlertService::create(
                'cron_degraded',
                'OpenClaw gateway unavailable; run queued',
                "Run request for job {$jobId} has been queued.",
                'warning',
                'cron',
                $jobId,
                $userId
            );

            AuditService::log('cron.run.queued', 'cron', $jobId, [
                'reason' => $reason,
                'queue_id' => $queued['queue_id'],
            ]);

            $this->ok([
                'queued' => true,
                'degraded' => true,
                'queue_id' => $queued['queue_id'],
                'job_id' => $jobId,
                'reason' => $reason,
            ], 202);
            return;
        }

        AuditService::log('cron.run', 'cron', $jobId, []);
        AlertService::create('cron_run', 'job triggered', "job {$jobId} submitted", 'info', 'cron', $jobId, $userId);

        $this->ok([
            'queued' => false,
            'degraded' => false,
            'result' => $result['json'] ?? trim((string) ($result['stdout'] ?? '')),
        ]);
    }

    public function runs(array $params): void
    {
        $userId = $this->userId();
        $queue = new OpenClawQueueService($userId);

        $jobId = (string) ($params['id'] ?? '');
        if ($jobId === '') {
            $this->fail('job id required', 422);
            return;
        }

        $limit = max(1, min(200, (int) $this->query('limit', 50)));
        $result = $this->bridge->listRuns($jobId, $limit);

        if (!$result['ok']) {
            $this->ok([
                'job_id' => $jobId,
                'runs' => $queue->cachedRuns($jobId, $limit),
                'degraded' => true,
                'from_cache' => true,
                'reason' => (string) ($result['error'] ?? 'unknown'),
            ]);
            return;
        }

        $runs = $result['runs'] ?? [];

        $pdo = Database::connection();
        $insert = $pdo->prepare(
            'INSERT INTO openclaw_job_runs (user_id, job_id, run_id, status, started_at, ended_at, duration_ms, summary, error_message, raw_json, created_at)
             VALUES (:user_id, :job_id, :run_id, :status, :started_at, :ended_at, :duration_ms, :summary, :error_message, :raw_json, NOW())'
        );

        foreach ($runs as $run) {
            $insert->execute([
                'user_id' => $userId,
                'job_id' => $jobId,
                'run_id' => (string) ($run['runId'] ?? $run['id'] ?? ''),
                'status' => (string) ($run['status'] ?? 'unknown'),
                'started_at' => $this->normalizeDate($run['startedAt'] ?? null),
                'ended_at' => $this->normalizeDate($run['endedAt'] ?? null),
                'duration_ms' => isset($run['durationMs']) ? (int) $run['durationMs'] : null,
                'summary' => (string) ($run['summary'] ?? ''),
                'error_message' => (string) ($run['error'] ?? ''),
                'raw_json' => json_encode($run, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }

        $this->ok([
            'job_id' => $jobId,
            'runs' => $runs,
            'degraded' => false,
            'from_cache' => false,
        ]);
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable((string) $value))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function queryBool(string $key, bool $default): bool
    {
        $value = $this->query($key, null);
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
