<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use RuntimeException;

final class OpenClawBridgeService
{
    private string $openclawScript;
    private string $openclawConfigPath;
    private int $commandTimeoutSec;

    public function __construct()
    {
        $this->openclawScript = (string) env('OPENCLAW_POWERSHELL_PATH', 'D:\\coder\\openclaw\\openclaw.ps1');
        $this->openclawConfigPath = (string) env('OPENCLAW_CONFIG_PATH', 'C:\\Users\\Administrator\\.openclaw\\openclaw.json');
        $this->commandTimeoutSec = max(3, min(30, (int) env('OPENCLAW_CMD_TIMEOUT_SEC', 8)));
    }

    public function listJobs(): array
    {
        $result = $this->run(array_merge(['cron', 'list', '--all', '--json'], $this->withTokenAndUrl()));
        if (!$result['ok']) {
            return $result;
        }

        $jobs = $this->extractJobs($result['json']);
        $result['jobs'] = $jobs;
        return $result;
    }

    public function addJob(array $payload): array
    {
        $args = array_merge(CronPayloadMapper::buildAddArgs($payload), $this->withTokenAndUrl());
        return $this->run($args);
    }

    public function editJob(string $jobId, array $payload): array
    {
        $args = array_merge(CronPayloadMapper::buildEditArgs($jobId, $payload), $this->withTokenAndUrl());
        return $this->run($args);
    }

    public function runJob(string $jobId): array
    {
        return $this->run(array_merge(['cron', 'run', $jobId], $this->withTokenAndUrl()));
    }

    public function listRuns(string $jobId, int $limit = 50): array
    {
        $args = ['cron', 'runs', '--id', $jobId, '--limit', (string) max(1, min(200, $limit))];
        $result = $this->run(array_merge($args, $this->withTokenAndUrl()));

        if ($result['ok']) {
            $result['runs'] = $this->extractRuns($result['json'], $result['stdout']);
        }

        return $result;
    }

    public function syncJobsToDb(?int $userId = null): array
    {
        $userId = $this->resolveUserId($userId);
        $list = $this->listJobs();
        if (!$list['ok']) {
            return $list;
        }

        $pdo = Database::connection();
        $upsert = $pdo->prepare(
            'INSERT INTO openclaw_jobs (
                user_id, job_id, name, description, schedule_type, schedule_expr, timezone, session_target,
                enabled, delivery_mode, delivery_target, payload_json, raw_json, last_run_at, next_run_at,
                synced_at, created_at, updated_at
            ) VALUES (
                :user_id, :job_id, :name, :description, :schedule_type, :schedule_expr, :timezone, :session_target,
                :enabled, :delivery_mode, :delivery_target, :payload_json, :raw_json, :last_run_at, :next_run_at,
                NOW(), NOW(), NOW()
            )
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                description = VALUES(description),
                schedule_type = VALUES(schedule_type),
                schedule_expr = VALUES(schedule_expr),
                timezone = VALUES(timezone),
                session_target = VALUES(session_target),
                enabled = VALUES(enabled),
                delivery_mode = VALUES(delivery_mode),
                delivery_target = VALUES(delivery_target),
                payload_json = VALUES(payload_json),
                raw_json = VALUES(raw_json),
                last_run_at = VALUES(last_run_at),
                next_run_at = VALUES(next_run_at),
                synced_at = NOW(),
                updated_at = NOW()'
        );

        foreach ($list['jobs'] as $job) {
            $upsert->execute([
                'user_id' => $userId,
                'job_id' => (string) ($job['id'] ?? $job['jobId'] ?? ''),
                'name' => (string) ($job['name'] ?? 'unnamed job'),
                'description' => (string) ($job['description'] ?? ''),
                'schedule_type' => (string) ($job['scheduleType'] ?? $job['type'] ?? ''),
                'schedule_expr' => (string) ($job['cron'] ?? $job['every'] ?? $job['at'] ?? ''),
                'timezone' => (string) ($job['tz'] ?? $job['timezone'] ?? 'Asia/Shanghai'),
                'session_target' => (string) ($job['sessionTarget'] ?? $job['session'] ?? ''),
                'enabled' => (int) (($job['enabled'] ?? true) ? 1 : 0),
                'delivery_mode' => (string) ($job['delivery']['mode'] ?? $job['deliveryMode'] ?? ''),
                'delivery_target' => (string) ($job['delivery']['to'] ?? $job['to'] ?? ''),
                'payload_json' => json_encode($job['payload'] ?? $job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'raw_json' => json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'last_run_at' => $this->toDateTime($job['lastRunAt'] ?? null),
                'next_run_at' => $this->toDateTime($job['nextRunAt'] ?? null),
            ]);
        }

        return [
            'ok' => true,
            'jobs' => $list['jobs'],
            'message' => 'jobs synced',
        ];
    }

    private function run(array $args): array
    {
        if (!is_file($this->openclawScript)) {
            return [
                'ok' => false,
                'code' => 500,
                'stdout' => '',
                'stderr' => '',
                'error' => 'OpenClaw script not found: ' . $this->openclawScript,
                'json' => null,
            ];
        }

        $escaped = array_map(static fn(string $arg): string => '"' . str_replace('"', '\\"', $arg) . '"', $args);
        $command = 'powershell -ExecutionPolicy Bypass -File "' . str_replace('"', '\\"', $this->openclawScript) . '" ' . implode(' ', $escaped);

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, base_path());
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to launch openclaw process');
        }

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $startedAt = microtime(true);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $status = proc_get_status($process);
            $running = (bool) ($status['running'] ?? false);

            $read = [];
            if (isset($pipes[1]) && is_resource($pipes[1]) && !feof($pipes[1])) {
                $read[] = $pipes[1];
            }
            if (isset($pipes[2]) && is_resource($pipes[2]) && !feof($pipes[2])) {
                $read[] = $pipes[2];
            }

            if ($read !== []) {
                $write = null;
                $except = null;
                @stream_select($read, $write, $except, 0, 200000);
                foreach ($read as $stream) {
                    $chunk = stream_get_contents($stream);
                    if ($chunk === false || $chunk === '') {
                        continue;
                    }
                    if ($stream === $pipes[1]) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
            } else {
                usleep(100000);
            }

            if (!$running) {
                break;
            }

            if ((microtime(true) - $startedAt) >= $this->commandTimeoutSec) {
                $timedOut = true;
                @proc_terminate($process);
                usleep(200000);
                $status = proc_get_status($process);
                if ((bool) ($status['running'] ?? false)) {
                    @proc_terminate($process, 9);
                }
                break;
            }
        }

        if (isset($pipes[1]) && is_resource($pipes[1])) {
            $chunk = stream_get_contents($pipes[1]);
            if ($chunk !== false && $chunk !== '') {
                $stdout .= $chunk;
            }
            fclose($pipes[1]);
        }
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            $chunk = stream_get_contents($pipes[2]);
            if ($chunk !== false && $chunk !== '') {
                $stderr .= $chunk;
            }
            fclose($pipes[2]);
        }

        $code = proc_close($process);
        $json = $this->extractJson($stdout);

        $pairingRequired = str_contains(strtolower($stdout . ' ' . $stderr), 'pairing required');
        $hardError = str_contains(strtolower($stderr), 'error:');
        $ok = !$timedOut && $code === 0 && !$pairingRequired && !$hardError;

        $error = null;
        if (!$ok) {
            if ($timedOut) {
                $error = 'OpenClaw command timeout after ' . $this->commandTimeoutSec . 's';
            } else {
                $error = trim($stderr !== '' ? $stderr : $stdout);
            }
        }

        return [
            'ok' => $ok,
            'code' => $code,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'json' => $json,
            'timed_out' => $timedOut,
            'error' => $error,
        ];
    }

    private function extractJson(string $text): mixed
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return null;
        }

        $direct = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $direct;
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $trimmed) ?: []), static fn(string $line): bool => $line !== ''));
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $candidate = $lines[$i];
            $decoded = json_decode($candidate, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }

    private function extractJobs(mixed $json): array
    {
        if (is_array($json) && array_is_list($json)) {
            return $json;
        }

        if (is_array($json) && isset($json['jobs']) && is_array($json['jobs'])) {
            return $json['jobs'];
        }

        if (is_array($json) && isset($json['data']) && is_array($json['data'])) {
            return $json['data'];
        }

        return [];
    }

    private function extractRuns(mixed $json, string $stdout): array
    {
        if (is_array($json)) {
            if (array_is_list($json)) {
                return $json;
            }
            if (isset($json['runs']) && is_array($json['runs'])) {
                return $json['runs'];
            }
            if (isset($json['entries']) && is_array($json['entries'])) {
                return $json['entries'];
            }
        }

        $runs = [];
        $lines = preg_split('/\R/', trim($stdout)) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $runs[] = $decoded;
            }
        }

        return $runs;
    }

    private function withTokenAndUrl(): array
    {
        $token = $this->readGatewayToken();
        $args = ['--url', 'ws://127.0.0.1:18789'];
        if ($token !== null && $token !== '') {
            $args[] = '--token';
            $args[] = $token;
        }
        return $args;
    }

    private function readGatewayToken(): ?string
    {
        if (!is_file($this->openclawConfigPath)) {
            return null;
        }

        $json = file_get_contents($this->openclawConfigPath);
        if ($json === false) {
            return null;
        }

        $cfg = json_decode($json, true);
        if (!is_array($cfg)) {
            return null;
        }

        return $cfg['gateway']['auth']['token'] ?? null;
    }

    private function toDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable((string) $value))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveUserId(?int $userId): int
    {
        if ($userId !== null && $userId > 0) {
            return $userId;
        }

        return (int) (AuthService::id() ?? 0);
    }
}
