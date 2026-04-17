<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/autoload.php';

use App\Services\RiskService;

$rows = [
    [
        'symbol' => '600519',
        'quantity' => 10,
        'cost_price' => 100,
        'current_price' => 120,
        'stop_loss_price' => 90,
    ],
    [
        'symbol' => '601318',
        'quantity' => 20,
        'cost_price' => 50,
        'current_price' => 40,
        'stop_loss_price' => 45,
    ],
];

$risk = RiskService::calculateFromRows($rows);

if ($risk['holding_count'] !== 2) {
    throw new RuntimeException('holding_count mismatch');
}

if ($risk['stoploss_risk_count'] !== 1) {
    throw new RuntimeException('stoploss_risk_count mismatch');
}

if ($risk['unrealized_pnl'] !== 0.0) {
    throw new RuntimeException('unrealized_pnl should be 0.0 in this test');
}

echo "test_risk_service passed\n";
