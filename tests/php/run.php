<?php

declare(strict_types=1);

$tests = [
    __DIR__ . '/test_cron_payload_mapper.php',
    __DIR__ . '/test_risk_service.php',
    __DIR__ . '/test_watchlist_ranking_service.php',
];

$passed = 0;

foreach ($tests as $test) {
    require $test;
    $passed++;
}

echo "PHP tests passed: {$passed}\n";
