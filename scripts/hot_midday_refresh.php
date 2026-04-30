<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;
use App\Services\IngestTargetService;
use App\Services\MarketOpportunityService;

/**
 * One-shot refresh for:
 *  - hot holdings
 *  - midday opportunities
 *
 * Usage:
 *   php scripts/hot_midday_refresh.php
 *   php scripts/hot_midday_refresh.php --user=1 --limit=40 --hot-limit=15 --json
 *   php scripts/hot_midday_refresh.php --skip-ingest --skip-reconcile --skip-snapshot
 */

function arg_has(array $argv, string $flag): bool
{
    return in_array($flag, $argv, true);
}

function arg_value(array $argv, string $prefix, ?string $fallback = null): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
    }
    return $fallback;
}

/**
 * @return array{code:int,stdout:string,stderr:string,timed_out:bool}
 */
function run_command(string $cmd, int $timeoutSec = 180): array
{
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

    $started = microtime(true);
    $stdout = '';
    $stderr = '';
    $timedOut = false;

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
        'code' => (int) $code,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'timed_out' => $timedOut,
    ];
}

function resolve_php_bin(): string
{
    $php = PHP_BINARY ?: 'php';
    return $php !== '' ? $php : 'php';
}

function resolve_user_id(?string $userArg): int
{
    $pdo = Database::connection();
    $candidate = trim((string) $userArg);

    if ($candidate !== '') {
        if (ctype_digit($candidate)) {
            $id = (int) $candidate;
            if ($id > 0) {
                return $id;
            }
        }

        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $candidate]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
    }

    $targetUsername = trim((string) (IngestTargetService::getUsername() ?? ''));
    if ($targetUsername === '') {
        $targetUsername = trim((string) env('INGEST_DEFAULT_USERNAME', ''));
    }
    if ($targetUsername === '') {
        $targetUsername = trim((string) env('ADMIN_USERNAME', ''));
    }

    if ($targetUsername !== '') {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $targetUsername]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
    }

    return (int) ($pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
}

function resolve_username_by_id(int $userId): string
{
    if ($userId <= 0) {
        return '';
    }
    $stmt = Database::connection()->prepare('SELECT username FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    return (string) ($stmt->fetchColumn() ?: '');
}

/**
 * @param array<int, array<string, mixed>> $positionRows
 * @param array<int, array<string, mixed>> $watchlistRows
 * @return array<int, array<string, mixed>>
 */
function build_hot_holdings(array $positionRows, array $watchlistRows, int $hotLimit): array
{
    $map = [];

    foreach ($positionRows as $row) {
        $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
        if ($symbol === '') {
            continue;
        }
        $score = is_numeric($row['score'] ?? null) ? (float) $row['score'] : 0.0;
        $map[$symbol] = [
            'source' => 'position',
            'symbol' => $symbol,
            'name' => (string) ($row['name'] ?? ''),
            'score' => round($score, 2),
            'signal_level' => (string) ($row['signal_level'] ?? ''),
            'price' => is_numeric($row['current_price'] ?? null) ? (float) $row['current_price'] : null,
            'pnl_pct' => is_numeric($row['pnl_pct'] ?? null) ? (float) $row['pnl_pct'] : null,
            'distance_to_support_pct' => is_numeric($row['distance_to_support_pct'] ?? null) ? (float) $row['distance_to_support_pct'] : null,
            'volume_ratio' => is_numeric($row['volume_ratio'] ?? null) ? (float) $row['volume_ratio'] : null,
            'quote_time' => (string) ($row['quote_time'] ?? ''),
            'action_advice' => (string) ($row['action_advice'] ?? ''),
        ];
    }

    foreach ($watchlistRows as $row) {
        $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
        if ($symbol === '') {
            continue;
        }
        $score = is_numeric($row['score'] ?? null) ? (float) $row['score'] : 0.0;
        if (isset($map[$symbol]) && ((float) $map[$symbol]['score']) >= $score) {
            continue;
        }
        $map[$symbol] = [
            'source' => 'watchlist',
            'symbol' => $symbol,
            'name' => (string) ($row['name'] ?? ''),
            'score' => round($score, 2),
            'ranking_score' => is_numeric($row['ranking_score'] ?? null) ? (float) $row['ranking_score'] : null,
            'ranking_grade' => (string) ($row['ranking_grade'] ?? ''),
            'price' => null,
            'pnl_pct' => null,
            'distance_to_support_pct' => null,
            'volume_ratio' => is_numeric($row['volume_ratio'] ?? null) ? (float) $row['volume_ratio'] : null,
            'quote_time' => (string) ($row['quote_time'] ?? ''),
            'action_advice' => (string) ($row['action_advice'] ?? ''),
        ];
    }

    $rows = array_values($map);
    usort(
        $rows,
        static fn(array $a, array $b): int =>
            ((float) ($b['score'] ?? 0) <=> (float) ($a['score'] ?? 0))
            ?: strcmp((string) ($a['symbol'] ?? ''), (string) ($b['symbol'] ?? ''))
    );

    return array_slice($rows, 0, max(1, min(60, $hotLimit)));
}

function minutes_since(?string $timeText): ?int
{
    $text = trim((string) $timeText);
    if ($text === '') {
        return null;
    }
    $ts = strtotime($text);
    if ($ts === false || $ts <= 0) {
        return null;
    }
    return max(0, (int) floor((time() - $ts) / 60));
}

/**
 * @return array<string, mixed>
 */
function freshness_snapshot(): array
{
    $pdo = Database::connection();

    $latestQuote = (string) ($pdo->query(
        "SELECT MAX(quote_time)
         FROM market_quotes_latest
         WHERE market = 'A_STOCK_MAIN'
           AND symbol REGEXP '^(000|001|002|003|600|601|603|605)[0-9]{3}$'"
    )->fetchColumn() ?: '');

    $latestSector = (string) ($pdo->query('SELECT MAX(sample_time) FROM sector_strength')->fetchColumn() ?: '');
    $latestNews = (string) ($pdo->query('SELECT MAX(published_at) FROM news_feed')->fetchColumn() ?: '');
    $latestCloseDate = (string) ($pdo->query('SELECT MAX(trade_date) FROM market_close_rankings')->fetchColumn() ?: '');

    return [
        'quotes_latest_time' => $latestQuote !== '' ? $latestQuote : null,
        'quotes_delay_min' => minutes_since($latestQuote),
        'sector_sample_time' => $latestSector !== '' ? $latestSector : null,
        'sector_delay_min' => minutes_since($latestSector),
        'news_latest_time' => $latestNews !== '' ? $latestNews : null,
        'news_delay_min' => minutes_since($latestNews),
        'close_rank_trade_date' => $latestCloseDate !== '' ? $latestCloseDate : null,
    ];
}

$argv = $_SERVER['argv'] ?? [];
$asJson = arg_has($argv, '--json');
$skipIngest = arg_has($argv, '--skip-ingest');
$skipReconcile = arg_has($argv, '--skip-reconcile');
$skipSnapshot = arg_has($argv, '--skip-snapshot');

$limit = max(1, min(60, (int) (arg_value($argv, '--limit=', '30') ?? '30')));
$hotLimit = max(1, min(60, (int) (arg_value($argv, '--hot-limit=', '12') ?? '12')));
$slot = trim((string) (arg_value($argv, '--slot=', 'midday') ?? 'midday'));
$userArg = arg_value($argv, '--user=', null);

$phpBin = resolve_php_bin();
$steps = [];

if (!$skipIngest) {
    $cmd = sprintf('%s %s', escapeshellarg($phpBin), escapeshellarg(base_path('scripts/ingest_once.php')));
    $result = run_command($cmd, 240);
    $steps['ingest_once'] = [
        'code' => $result['code'],
        'timed_out' => $result['timed_out'],
        'stdout_tail' => implode("\n", array_slice(preg_split('/\R/', trim($result['stdout'])) ?: [], -12)),
        'stderr_tail' => implode("\n", array_slice(preg_split('/\R/', trim($result['stderr'])) ?: [], -12)),
    ];
}

if (!$skipReconcile) {
    $cmd = sprintf(
        '%s %s --fix --json --limit=20',
        escapeshellarg($phpBin),
        escapeshellarg(base_path('scripts/reconcile_quotes_latest.php'))
    );
    $result = run_command($cmd, 120);
    $steps['reconcile_quotes_latest'] = [
        'code' => $result['code'],
        'timed_out' => $result['timed_out'],
        'stdout_tail' => implode("\n", array_slice(preg_split('/\R/', trim($result['stdout'])) ?: [], -12)),
        'stderr_tail' => implode("\n", array_slice(preg_split('/\R/', trim($result['stderr'])) ?: [], -12)),
    ];
}

if (!$skipSnapshot) {
    $cmd = sprintf(
        '%s %s --slot=%s --sync=1 --watchlist-limit=360 --insight-limit=36',
        escapeshellarg($phpBin),
        escapeshellarg(base_path('scripts/review_snapshot.php')),
        escapeshellarg($slot)
    );
    $result = run_command($cmd, 180);
    $steps['review_snapshot'] = [
        'code' => $result['code'],
        'timed_out' => $result['timed_out'],
        'stdout_tail' => implode("\n", array_slice(preg_split('/\R/', trim($result['stdout'])) ?: [], -12)),
        'stderr_tail' => implode("\n", array_slice(preg_split('/\R/', trim($result['stderr'])) ?: [], -12)),
    ];
}

$userId = resolve_user_id($userArg);
if ($userId <= 0) {
    fwrite(STDERR, "no user available\n");
    exit(2);
}
$username = resolve_username_by_id($userId);

$service = new MarketOpportunityService();
$payload = $service->build($userId, min(30, $limit));

$hotHoldings = build_hot_holdings(
    is_array($payload['positions'] ?? null) ? $payload['positions'] : [],
    is_array($payload['watchlist'] ?? null) ? $payload['watchlist'] : [],
    $hotLimit
);

$snapshot = [
    'generated_at' => now_sql(),
    'user' => [
        'id' => $userId,
        'username' => $username,
    ],
    'args' => [
        'limit' => $limit,
        'hot_limit' => $hotLimit,
        'slot' => $slot,
        'skip_ingest' => $skipIngest,
        'skip_reconcile' => $skipReconcile,
        'skip_snapshot' => $skipSnapshot,
    ],
    'freshness' => freshness_snapshot(),
    'steps' => $steps,
    'summary' => $payload['summary'] ?? [],
    'hot_holdings' => $hotHoldings,
    'midday_opportunities' => is_array($payload['opportunities'] ?? null) ? array_slice($payload['opportunities'], 0, $limit) : [],
];

$runtimeDir = base_path('storage/runtime');
if (!is_dir($runtimeDir)) {
    @mkdir($runtimeDir, 0777, true);
}
$snapshotFile = $runtimeDir . DIRECTORY_SEPARATOR . 'hot_midday_snapshot.json';
@file_put_contents(
    $snapshotFile,
    json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
    LOCK_EX
);

if ($asJson) {
    echo json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    $summary = $snapshot['summary'];
    echo '[hot_midday_refresh]' . PHP_EOL;
    echo 'user=' . $userId . ($username !== '' ? ('(' . $username . ')') : '') . PHP_EOL;
    echo 'snapshot_file=' . $snapshotFile . PHP_EOL;
    echo 'quotes_delay_min=' . (($snapshot['freshness']['quotes_delay_min'] ?? null) === null ? '-' : (string) $snapshot['freshness']['quotes_delay_min']) . PHP_EOL;
    echo 'positions=' . (string) ((int) ($summary['position_count'] ?? 0)) . PHP_EOL;
    echo 'watchlist=' . (string) ((int) ($summary['watchlist_count'] ?? 0)) . PHP_EOL;
    echo 'hot_holdings=' . (string) count($hotHoldings) . PHP_EOL;
    echo 'opportunities=' . (string) ((int) ($summary['opportunity_count'] ?? 0)) . PHP_EOL;
}

$opCount = (int) (($snapshot['summary']['opportunity_count'] ?? 0));
$hotCount = count($hotHoldings);

if ($opCount <= 0 && $hotCount <= 0) {
    exit(3);
}

exit(0);

