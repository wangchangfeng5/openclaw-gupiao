<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;

/**
 * Usage:
 *   php scripts/reconcile_quotes_latest.php
 *   php scripts/reconcile_quotes_latest.php --fix
 *   php scripts/reconcile_quotes_latest.php --json
 *   php scripts/reconcile_quotes_latest.php --limit=30
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

function empty_like(mixed $value): bool
{
    return $value === null || $value === '';
}

function text_equal(mixed $a, mixed $b): bool
{
    if (empty_like($a) && empty_like($b)) {
        return true;
    }
    return (string) $a === (string) $b;
}

function numeric_equal(mixed $a, mixed $b, float $epsilon = 0.0001): bool
{
    if (empty_like($a) && empty_like($b)) {
        return true;
    }
    if (empty_like($a) || empty_like($b)) {
        return false;
    }
    if (!is_numeric($a) || !is_numeric($b)) {
        return (string) $a === (string) $b;
    }
    return abs((float) $a - (float) $b) <= $epsilon;
}

function row_key(array $row): string
{
    return sprintf('%s|%s', (string) ($row['market'] ?? ''), (string) ($row['symbol'] ?? ''));
}

function field_diff(array $src, array $latest): array
{
    $diffs = [];

    $textFields = ['name', 'sector_name', 'trend_direction', 'quote_time', 'source'];
    foreach ($textFields as $field) {
        if (!text_equal($src[$field] ?? null, $latest[$field] ?? null)) {
            $diffs[] = $field;
        }
    }

    $numFields = ['price', 'change_pct', 'volume', 'turnover'];
    foreach ($numFields as $field) {
        if (!numeric_equal($src[$field] ?? null, $latest[$field] ?? null)) {
            $diffs[] = $field;
        }
    }

    return $diffs;
}

$argv = $_SERVER['argv'] ?? [];
$fix = arg_has($argv, '--fix');
$asJson = arg_has($argv, '--json');
$limit = max(1, (int) (arg_value($argv, '--limit=', '20') ?? '20'));

$pdo = Database::connection();

$sourceSql = <<<'SQL'
SELECT
  mq.symbol,
  mq.market,
  mq.name,
  mq.sector_name,
  mq.trend_direction,
  mq.price,
  mq.change_pct,
  mq.volume,
  mq.turnover,
  mq.quote_time,
  mq.source,
  mq.raw_json
FROM market_quotes mq
INNER JOIN (
  SELECT symbol, market, MAX(id) AS max_id
  FROM market_quotes
  GROUP BY symbol, market
) latest ON latest.max_id = mq.id
SQL;

$latestSql = <<<'SQL'
SELECT
  symbol,
  market,
  name,
  sector_name,
  trend_direction,
  price,
  change_pct,
  volume,
  turnover,
  quote_time,
  source,
  raw_json
FROM market_quotes_latest
SQL;

$sourceRows = $pdo->query($sourceSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$latestRows = $pdo->query($latestSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$sourceMap = [];
foreach ($sourceRows as $row) {
    $sourceMap[row_key($row)] = $row;
}

$latestMap = [];
foreach ($latestRows as $row) {
    $latestMap[row_key($row)] = $row;
}

$missing = [];
$mismatch = [];
foreach ($sourceMap as $key => $srcRow) {
    if (!isset($latestMap[$key])) {
        $missing[$key] = $srcRow;
        continue;
    }

    $latestRow = $latestMap[$key];
    $diffs = field_diff($srcRow, $latestRow);
    if ($diffs !== []) {
        $mismatch[$key] = [
            'source' => $srcRow,
            'latest' => $latestRow,
            'fields' => $diffs,
        ];
    }
}

$orphan = [];
foreach ($latestMap as $key => $latestRow) {
    if (!isset($sourceMap[$key])) {
        $orphan[$key] = $latestRow;
    }
}

$summary = [
    'source_latest_symbols' => count($sourceMap),
    'latest_table_symbols' => count($latestMap),
    'missing_in_latest' => count($missing),
    'mismatched_rows' => count($mismatch),
    'orphan_rows' => count($orphan),
];

$missingSample = [];
if ($missing !== []) {
    $i = 0;
    foreach ($missing as $key => $row) {
        $i++;
        if ($i > $limit) {
            break;
        }
        $missingSample[] = [
            'symbol_key' => str_replace('|', ':', $key),
            'quote_time' => (string) ($row['quote_time'] ?? '-'),
            'source' => (string) ($row['source'] ?? '-'),
        ];
    }
}

$mismatchSample = [];
if ($mismatch !== []) {
    $i = 0;
    foreach ($mismatch as $key => $pack) {
        $i++;
        if ($i > $limit) {
            break;
        }
        $mismatchSample[] = [
            'symbol_key' => str_replace('|', ':', $key),
            'fields' => $pack['fields'],
            'source_quote_time' => (string) ($pack['source']['quote_time'] ?? '-'),
            'latest_quote_time' => (string) ($pack['latest']['quote_time'] ?? '-'),
        ];
    }
}

$orphanSample = [];
if ($orphan !== []) {
    $i = 0;
    foreach ($orphan as $key => $row) {
        $i++;
        if ($i > $limit) {
            break;
        }
        $orphanSample[] = [
            'symbol_key' => str_replace('|', ':', $key),
            'quote_time' => (string) ($row['quote_time'] ?? '-'),
            'source' => (string) ($row['source'] ?? '-'),
        ];
    }
}

$fixed = 0;
if ($fix && ($missing !== [] || $mismatch !== [])) {
    $upsertSql = <<<'SQL'
INSERT INTO market_quotes_latest (
  symbol, market, name, sector_name, trend_direction, price, change_pct, volume, turnover, quote_time, source, raw_json, created_at, updated_at
)
VALUES (
  :symbol, :market, :name, :sector_name, :trend_direction, :price, :change_pct, :volume, :turnover, :quote_time, :source, :raw_json, NOW(), NOW()
)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  sector_name = VALUES(sector_name),
  trend_direction = VALUES(trend_direction),
  price = VALUES(price),
  change_pct = VALUES(change_pct),
  volume = VALUES(volume),
  turnover = VALUES(turnover),
  quote_time = VALUES(quote_time),
  source = VALUES(source),
  raw_json = VALUES(raw_json),
  updated_at = NOW()
SQL;

    $stmt = $pdo->prepare($upsertSql);

    $toUpsert = [];
    foreach ($missing as $key => $row) {
        $toUpsert[$key] = $row;
    }
    foreach ($mismatch as $key => $pack) {
        $toUpsert[$key] = $pack['source'];
    }

    foreach ($toUpsert as $row) {
        $stmt->execute([
            'symbol' => $row['symbol'] ?? null,
            'market' => $row['market'] ?? null,
            'name' => $row['name'] ?? null,
            'sector_name' => $row['sector_name'] ?? null,
            'trend_direction' => $row['trend_direction'] ?? null,
            'price' => $row['price'] ?? null,
            'change_pct' => $row['change_pct'] ?? null,
            'volume' => $row['volume'] ?? null,
            'turnover' => $row['turnover'] ?? null,
            'quote_time' => $row['quote_time'] ?? null,
            'source' => $row['source'] ?? null,
            'raw_json' => $row['raw_json'] ?? null,
        ]);
        $fixed++;
    }
}

if ($asJson) {
    $result = [
        'summary' => $summary,
        'missing_sample' => $missingSample,
        'mismatch_sample' => $mismatchSample,
        'orphan_sample' => $orphanSample,
        'fix' => [
            'enabled' => $fix,
            'upserted' => $fixed,
            'orphan_not_deleted' => $summary['orphan_rows'],
        ],
    ];
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    echo "[SUMMARY]\n";
    echo 'source latest symbols: ' . $summary['source_latest_symbols'] . PHP_EOL;
    echo 'market_quotes_latest symbols: ' . $summary['latest_table_symbols'] . PHP_EOL;
    echo 'missing in latest: ' . $summary['missing_in_latest'] . PHP_EOL;
    echo 'mismatched rows: ' . $summary['mismatched_rows'] . PHP_EOL;
    echo 'orphan rows in latest: ' . $summary['orphan_rows'] . PHP_EOL;

    if ($missingSample !== []) {
        echo PHP_EOL . "[MISSING SAMPLE]\n";
        foreach ($missingSample as $item) {
            echo sprintf(
                '%s quote_time=%s source=%s',
                $item['symbol_key'],
                $item['quote_time'],
                $item['source']
            ) . PHP_EOL;
        }
    }

    if ($mismatchSample !== []) {
        echo PHP_EOL . "[MISMATCH SAMPLE]\n";
        foreach ($mismatchSample as $item) {
            echo sprintf(
                '%s fields=%s source_quote_time=%s latest_quote_time=%s',
                $item['symbol_key'],
                implode(',', $item['fields']),
                $item['source_quote_time'],
                $item['latest_quote_time']
            ) . PHP_EOL;
        }
    }

    if ($fix) {
        echo PHP_EOL . '[FIX] upserted rows: ' . $fixed . PHP_EOL;
        if ($orphan !== []) {
            echo '[FIX] orphan rows are not deleted automatically.' . PHP_EOL;
        }
    }
}

if (($missing !== [] || $mismatch !== []) && !$fix) {
    exit(2);
}

exit(0);
