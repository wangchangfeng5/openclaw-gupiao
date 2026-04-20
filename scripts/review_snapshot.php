<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;
use App\Services\WatchlistReviewService;

$slot = 'manual';
$sync = true;
$watchlistLimit = 360;
$insightLimit = 36;
$targetUserId = 0;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--slot=')) {
        $slot = trim((string) substr($arg, 7));
        continue;
    }
    if (str_starts_with($arg, '--sync=')) {
        $v = strtolower(trim((string) substr($arg, 7)));
        $sync = in_array($v, ['1', 'true', 'yes', 'on'], true);
        continue;
    }
    if (str_starts_with($arg, '--watchlist-limit=')) {
        $watchlistLimit = max(40, min(600, (int) substr($arg, 18)));
        continue;
    }
    if (str_starts_with($arg, '--insight-limit=')) {
        $insightLimit = max(8, min(120, (int) substr($arg, 16)));
        continue;
    }
    if (str_starts_with($arg, '--user=')) {
        $targetUserId = max(0, (int) substr($arg, 7));
        continue;
    }
}

$service = new WatchlistReviewService();
$pdo = Database::connection();

if ($targetUserId > 0) {
    $userIds = [$targetUserId];
} else {
    $userIds = array_map(
        static fn(array $row): int => (int) ($row['id'] ?? 0),
        $pdo->query('SELECT id FROM users ORDER BY id ASC')->fetchAll() ?: []
    );
}

if ($userIds === []) {
    echo "review_snapshot: no users found\n";
    exit(0);
}

$summary = [
    'users' => 0,
    'sync_watchlists' => 0,
    'sync_upserts' => 0,
    'snapshot_rows' => 0,
];

foreach ($userIds as $userId) {
    if ($userId <= 0) {
        continue;
    }

    $syncStats = ['watchlists' => 0, 'upserts' => 0];
    if ($sync) {
        $syncStats = $service->syncForAllActive($userId, $watchlistLimit, $insightLimit);
    }

    $snapStats = $service->snapshotForUser($userId, $slot, $watchlistLimit);
    $latest = $service->latestGlobalSnapshot($userId);

    $summary['users']++;
    $summary['sync_watchlists'] += (int) ($syncStats['watchlists'] ?? 0);
    $summary['sync_upserts'] += (int) ($syncStats['upserts'] ?? 0);
    $summary['snapshot_rows'] += (int) ($snapStats['written'] ?? 0);

    $latestDate = (string) ($latest['snapshot_date'] ?? '-');
    $latestSlot = (string) ($latest['snapshot_slot'] ?? '-');
    $latestAcc = $latest['accuracy_rate'] ?? null;
    $latestRet5 = $latest['avg_return_5d_pct'] ?? null;

    echo sprintf(
        "user=%d sync_watchlists=%d sync_upserts=%d snapshot_rows=%d latest=%s/%s acc=%s ret5=%s\n",
        $userId,
        (int) ($syncStats['watchlists'] ?? 0),
        (int) ($syncStats['upserts'] ?? 0),
        (int) ($snapStats['written'] ?? 0),
        $latestDate,
        $latestSlot,
        $latestAcc !== null ? number_format((float) $latestAcc, 2) : '-',
        $latestRet5 !== null ? number_format((float) $latestRet5, 2) : '-'
    );
}

echo sprintf(
    "review_snapshot done users=%d sync_watchlists=%d sync_upserts=%d snapshot_rows=%d\n",
    (int) $summary['users'],
    (int) $summary['sync_watchlists'],
    (int) $summary['sync_upserts'],
    (int) $summary['snapshot_rows']
);
