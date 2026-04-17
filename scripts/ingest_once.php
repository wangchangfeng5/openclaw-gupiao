<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

$runtimeDir = base_path('storage/runtime');
if (!is_dir($runtimeDir)) {
    @mkdir($runtimeDir, 0777, true);
}

$stateFile = $runtimeDir . DIRECTORY_SEPARATOR . 'ingest_once_state.json';
$lockFile = $runtimeDir . DIRECTORY_SEPARATOR . 'ingest_once.lock';

$lockHandle = @fopen($lockFile, 'c+');
if ($lockHandle === false) {
    fwrite(STDERR, "cannot open lock file\n");
    exit(1);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    // Another ingest worker is already running.
    fclose($lockHandle);
    exit(0);
}

$writeState = static function (array $state) use ($stateFile): void {
    $state['updated_at'] = now_sql();
    $state['updated_ts'] = time();
    @file_put_contents(
        $stateFile,
        json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
};

$python = (string) env('PYTHON_BIN', 'python');
$adviceScript = base_path('workers/advice_ingestor.py');
$marketScript = base_path('workers/market_collector.py');
$workerTimeoutSec = max(10, min(180, (int) env('INGEST_WORKER_TIMEOUT_SEC', 45)));

$writeState([
    'status' => 'running',
    'started_at' => now_sql(),
    'started_ts' => time(),
    'finished_at' => null,
    'finished_ts' => null,
    'advice_code' => null,
    'market_code' => null,
    'message' => 'ingest worker started',
]);

$adviceCmd = sprintf('%s %s --once', escapeshellarg($python), escapeshellarg($adviceScript));
$marketCmd = sprintf('%s %s --once', escapeshellarg($python), escapeshellarg($marketScript));

$runWithTimeout = static function (string $cmd, int $timeoutSec): array {
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = @proc_open($cmd, $descriptors, $pipes, base_path());
    if (!is_resource($proc)) {
        return [
            'code' => 1,
            'stdout' => '',
            'stderr' => 'cannot start process',
            'timed_out' => false,
        ];
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $timedOut = false;
    $started = microtime(true);

    while (true) {
        $status = proc_get_status($proc);
        $running = (bool) ($status['running'] ?? false);

        $read = [];
        if (is_resource($pipes[1]) && !feof($pipes[1])) {
            $read[] = $pipes[1];
        }
        if (is_resource($pipes[2]) && !feof($pipes[2])) {
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

        if ((microtime(true) - $started) >= $timeoutSec) {
            $timedOut = true;
            @proc_terminate($proc);
            usleep(200000);
            $status = proc_get_status($proc);
            if ((bool) ($status['running'] ?? false)) {
                @proc_terminate($proc, 9);
            }
            break;
        }
    }

    if (is_resource($pipes[1])) {
        $chunk = stream_get_contents($pipes[1]);
        if ($chunk !== false && $chunk !== '') {
            $stdout .= $chunk;
        }
        fclose($pipes[1]);
    }
    if (is_resource($pipes[2])) {
        $chunk = stream_get_contents($pipes[2]);
        if ($chunk !== false && $chunk !== '') {
            $stderr .= $chunk;
        }
        fclose($pipes[2]);
    }

    $code = proc_close($proc);
    if ($timedOut) {
        $code = 124;
    }

    return [
        'code' => $code,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'timed_out' => $timedOut,
    ];
};

$adviceResult = $runWithTimeout($adviceCmd, $workerTimeoutSec);
$marketResult = $runWithTimeout($marketCmd, $workerTimeoutSec);

$adviceCode = (int) ($adviceResult['code'] ?? 1);
$marketCode = (int) ($marketResult['code'] ?? 1);
$adviceOutput = preg_split('/\R/', trim((string) ($adviceResult['stdout'] ?? ''))) ?: [];
$marketOutput = preg_split('/\R/', trim((string) ($marketResult['stdout'] ?? ''))) ?: [];
$adviceError = trim((string) ($adviceResult['stderr'] ?? ''));
$marketError = trim((string) ($marketResult['stderr'] ?? ''));

$status = ($adviceCode === 0 && $marketCode === 0) ? 'ok' : 'partial_failed';
$writeState([
    'status' => $status,
    'started_at' => null,
    'started_ts' => null,
    'finished_at' => now_sql(),
    'finished_ts' => time(),
    'advice_code' => $adviceCode,
    'market_code' => $marketCode,
    'worker_timeout_sec' => $workerTimeoutSec,
    'advice_timed_out' => (bool) ($adviceResult['timed_out'] ?? false),
    'market_timed_out' => (bool) ($marketResult['timed_out'] ?? false),
    'advice_error_tail' => $adviceError !== '' ? substr($adviceError, -4000) : '',
    'market_error_tail' => $marketError !== '' ? substr($marketError, -4000) : '',
    'advice_output_tail' => implode("\n", array_slice($adviceOutput, -20)),
    'market_output_tail' => implode("\n", array_slice($marketOutput, -20)),
    'message' => $status === 'ok' ? 'ingest worker completed' : 'ingest worker completed with partial failures',
]);

flock($lockHandle, LOCK_UN);
fclose($lockHandle);

exit($status === 'ok' ? 0 : 1);
