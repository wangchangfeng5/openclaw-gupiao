<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/autoload.php';

use App\Services\CronPayloadMapper;

$payload = [
    'name' => '午盘策略',
    'session' => 'isolated',
    'cron' => '0 13 * * 1-5',
    'message' => '拉取强势板块并更新建议',
    'tz' => 'Asia/Shanghai',
    'announce' => false,
    'light_context' => true,
];

$args = CronPayloadMapper::buildAddArgs($payload);

if (!in_array('--name', $args, true) || !in_array('午盘策略', $args, true)) {
    throw new RuntimeException('buildAddArgs should include name');
}

if (!in_array('--no-deliver', $args, true)) {
    throw new RuntimeException('buildAddArgs should include --no-deliver when announce=false');
}

if (!in_array('--light-context', $args, true)) {
    throw new RuntimeException('buildAddArgs should include --light-context');
}

echo "test_cron_payload_mapper passed\n";
