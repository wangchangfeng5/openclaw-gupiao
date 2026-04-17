<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditService;
use App\Services\RiskService;

final class SystemOpsController extends BaseController
{
    public function backup(): void
    {
        $php = 'D:\\phpstudy_pro\\Extensions\\php\\php8.2.9nts\\php.exe';
        $script = base_path('scripts/backup.php');

        $cmd = '"' . $php . '" "' . $script . '"';
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);

        AuditService::log('system.backup', 'system', null, ['exit_code' => $code]);

        if ($code !== 0) {
            $this->fail('backup failed', 500, ['output' => implode("\n", $output)]);
            return;
        }

        $this->ok(['message' => 'backup started', 'output' => implode("\n", $output)]);
    }

    public function snapshotRisk(): void
    {
        RiskService::snapshot($this->userId());
        AuditService::log('system.snapshot_risk');

        $this->ok(['message' => 'risk snapshot created']);
    }

    public function runIngestOnce(): void
    {
        $wait = $this->queryBool('wait', false);
        $force = $this->queryBool('force', false);
        $cooldownSec = max(20, min(600, (int) env('INGEST_ONCE_COOLDOWN_SEC', 75)));
        $staleRunningSec = max(120, min(3600, (int) env('INGEST_ONCE_STALE_RUNNING_SEC', 300)));

        if ($wait) {
            $this->runIngestOnceSync();
            return;
        }

        $state = $this->readIngestState();
        $now = time();
        $running = ($state['status'] ?? '') === 'running';
        $startedTs = (int) ($state['started_ts'] ?? 0);
        $finishedTs = (int) ($state['finished_ts'] ?? 0);

        if ($running && $startedTs > 0 && ($now - $startedTs) <= $staleRunningSec && !$force) {
            $this->ok([
                'status' => 'running',
                'message' => 'ingest worker is already running',
                'cooldown_sec' => $cooldownSec,
                'state' => $state,
            ], 202);
            return;
        }

        $lastTs = max($startedTs, $finishedTs);
        if (!$force && $lastTs > 0 && ($now - $lastTs) < $cooldownSec) {
            $this->ok([
                'status' => 'cooldown',
                'message' => 'ingest recently triggered; skipped by cooldown',
                'cooldown_sec' => $cooldownSec,
                'retry_after_sec' => $cooldownSec - ($now - $lastTs),
                'state' => $state,
            ], 202);
            return;
        }

        if (!$this->spawnIngestWorker()) {
            $this->fail('failed to start ingest worker', 500);
            return;
        }

        AuditService::log('system.ingest_once.trigger', 'system', null, [
            'mode' => 'async',
            'force' => $force,
            'cooldown_sec' => $cooldownSec,
        ]);

        $this->ok([
            'status' => 'accepted',
            'message' => 'ingest worker started in background',
            'cooldown_sec' => $cooldownSec,
            'state' => $this->readIngestState(),
        ], 202);
    }

    private function runIngestOnceSync(): void
    {
        $python = (string) env('PYTHON_BIN', 'python');
        $adviceScript = base_path('workers/advice_ingestor.py');
        $marketScript = base_path('workers/market_collector.py');

        $adviceCmd = sprintf('%s %s --once', escapeshellarg($python), escapeshellarg($adviceScript));
        $marketCmd = sprintf('%s %s --once', escapeshellarg($python), escapeshellarg($marketScript));

        $adviceOutput = [];
        $adviceCode = 0;
        exec($adviceCmd, $adviceOutput, $adviceCode);

        $marketOutput = [];
        $marketCode = 0;
        exec($marketCmd, $marketOutput, $marketCode);

        $status = ($adviceCode === 0 && $marketCode === 0) ? 'ok' : 'partial_failed';
        $this->writeIngestState([
            'status' => $status,
            'started_at' => null,
            'started_ts' => null,
            'finished_at' => now_sql(),
            'finished_ts' => time(),
            'advice_code' => $adviceCode,
            'market_code' => $marketCode,
            'message' => $status === 'ok' ? 'ingest once completed' : 'ingest once completed with partial failures',
        ]);

        AuditService::log('system.ingest_once', 'system', null, [
            'mode' => 'sync',
            'advice_code' => $adviceCode,
            'market_code' => $marketCode,
        ]);

        $this->ok([
            'status' => $status,
            'message' => $status === 'ok' ? 'ingest once completed' : 'ingest once completed with partial failures',
            'advice_code' => $adviceCode,
            'market_code' => $marketCode,
            'advice_output' => implode("\n", $adviceOutput),
            'market_output' => implode("\n", $marketOutput),
        ]);
    }

    private function ingestStateFile(): string
    {
        return base_path('storage/runtime/ingest_once_state.json');
    }

    /**
     * @return array<string, mixed>
     */
    private function readIngestState(): array
    {
        $file = $this->ingestStateFile();
        if (!is_file($file)) {
            return [
                'status' => 'idle',
                'started_at' => null,
                'started_ts' => null,
                'finished_at' => null,
                'finished_ts' => null,
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

    /**
     * @param array<string, mixed> $state
     */
    private function writeIngestState(array $state): void
    {
        $dir = base_path('storage/runtime');
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $state['updated_at'] = now_sql();
        $state['updated_ts'] = time();

        @file_put_contents(
            $this->ingestStateFile(),
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    private function spawnIngestWorker(): bool
    {
        $script = base_path('scripts/ingest_once.php');
        if (!is_file($script)) {
            return false;
        }

        $php = PHP_BINARY ?: 'php';

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = 'start "" /B "' . $php . '" "' . $script . '"';
            $proc = @popen($cmd, 'r');
            if (is_resource($proc)) {
                @pclose($proc);
                return true;
            }
            return false;
        }

        $cmd = sprintf('%s %s > /dev/null 2>&1 &', escapeshellarg($php), escapeshellarg($script));
        @exec($cmd, $out, $code);
        return $code === 0;
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
