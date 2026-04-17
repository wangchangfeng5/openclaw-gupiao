<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/autoload.php';

use App\Services\WatchlistRankingService;

$service = new WatchlistRankingService();

$rows = [
    [
        'symbol' => '',
        'market' => 'A_STOCK_MAIN',
        'current_price' => 10.2,
        'support_price' => 10.0,
        'resistance_price' => 10.8,
        'position_zone' => 'support_zone',
        'trend_direction' => 'strong_up',
        'confidence' => 80,
        'latest_volume' => 100000,
    ],
    [
        'symbol' => '',
        'market' => 'A_STOCK_MAIN',
        'current_price' => 10.7,
        'support_price' => 10.0,
        'resistance_price' => 10.8,
        'position_zone' => 'resistance_zone',
        'trend_direction' => 'strong_down',
        'confidence' => 60,
        'latest_volume' => 100000,
    ],
];

$scored = $service->enrichRows($rows);

if (count($scored) !== 2) {
    throw new RuntimeException('enrichRows should keep row count');
}

if (!isset($scored[0]['ranking_score'], $scored[1]['ranking_score'])) {
    throw new RuntimeException('ranking_score missing');
}

if ((float) $scored[0]['ranking_score'] <= (float) $scored[1]['ranking_score']) {
    throw new RuntimeException('bullish support case should score higher than bearish resistance case');
}

if (($scored[0]['ranking_grade'] ?? '') === '' || ($scored[1]['ranking_grade'] ?? '') === '') {
    throw new RuntimeException('ranking_grade missing');
}

echo "test_watchlist_ranking_service passed\n";
