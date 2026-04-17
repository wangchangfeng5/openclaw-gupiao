<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;

$pdo = Database::connection();

$username = (string) env('ADMIN_USERNAME', 'admin');
$password = (string) env('ADMIN_PASSWORD', 'ChangeMe123!');
$hash = password_hash($password, PASSWORD_BCRYPT);

$stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
$stmt->execute(['username' => $username]);
$user = $stmt->fetch();
$adminUserId = 0;

if ($user) {
    $update = $pdo->prepare('UPDATE users SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id');
    $update->execute([
        'password_hash' => $hash,
        'id' => (int) $user['id'],
    ]);
    $adminUserId = (int) $user['id'];
    echo "Updated admin user: {$username}\n";
} else {
    $insert = $pdo->prepare('INSERT INTO users (username, password_hash, created_at, updated_at) VALUES (:username, :password_hash, NOW(), NOW())');
    $insert->execute([
        'username' => $username,
        'password_hash' => $hash,
    ]);
    $adminUserId = (int) $pdo->lastInsertId();
    echo "Created admin user: {$username}\n";
}

$templates = [
    [
        'name' => '盘前情绪扫描',
        'period' => 'pre_open',
        'prompt' => '汇总隔夜国内外重要消息，提取A股主板可能受影响的行业与个股，并给出风险提示。',
        'cron_expression' => '0 8 * * 1-5',
    ],
    [
        'name' => '盘中强势板块追踪',
        'period' => 'intraday',
        'prompt' => '追踪当前交易日主板强势板块与活跃股，给出关注池和仓位风控建议。',
        'cron_expression' => '*/15 9-14 * * 1-5',
    ],
    [
        'name' => '盘后复盘总结',
        'period' => 'post_close',
        'prompt' => '总结当日持仓表现、触发的止损止盈、建议执行结果，并形成次日计划。',
        'cron_expression' => '30 15 * * 1-5',
    ],
];

foreach ($templates as $tpl) {
    $exists = $pdo->prepare('SELECT id FROM strategy_templates WHERE user_id = :user_id AND name = :name LIMIT 1');
    $exists->execute([
        'user_id' => $adminUserId,
        'name' => $tpl['name'],
    ]);
    if ($exists->fetch()) {
        continue;
    }

    $insertTpl = $pdo->prepare('INSERT INTO strategy_templates (user_id, name, period, prompt, cron_expression, timezone, enabled, created_at, updated_at) VALUES (:user_id, :name, :period, :prompt, :cron_expression, :timezone, 1, NOW(), NOW())');
    $insertTpl->execute([
        'user_id' => $adminUserId,
        'name' => $tpl['name'],
        'period' => $tpl['period'],
        'prompt' => $tpl['prompt'],
        'cron_expression' => $tpl['cron_expression'],
        'timezone' => 'Asia/Shanghai',
    ]);
}

echo "Seed completed.\n";
