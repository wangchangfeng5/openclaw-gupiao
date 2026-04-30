<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Services\DailyReviewService;

$slot = 'close';
$reviewDate = null;
$targetUserId = 0;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--slot=')) {
        $slot = trim((string) substr($arg, 7));
        continue;
    }
    if (str_starts_with($arg, '--date=')) {
        $reviewDate = trim((string) substr($arg, 7));
        continue;
    }
    if (str_starts_with($arg, '--user=')) {
        $targetUserId = max(0, (int) substr($arg, 7));
        continue;
    }
}

$service = new DailyReviewService();
$result = $service->generateForAllUsers($reviewDate, $slot, $targetUserId);

$details = is_array($result['details'] ?? null) ? $result['details'] : [];
echo sprintf(
    "daily_review_snapshot done date=%s slot=%s users=%d written=%d\n",
    (string) ($result['review_date'] ?? date('Y-m-d')),
    (string) ($result['slot'] ?? $slot),
    (int) ($result['users_total'] ?? 0),
    (int) ($result['written'] ?? 0)
);

foreach ($details as $row) {
    if (!is_array($row)) {
        continue;
    }
    echo sprintf(
        "user=%d review_date=%s total_pnl=%.2f trades=%d\n",
        (int) ($row['user_id'] ?? 0),
        (string) ($row['review_date'] ?? ''),
        (float) ($row['total_pnl'] ?? 0),
        (int) ($row['trade_count'] ?? 0)
    );
}
