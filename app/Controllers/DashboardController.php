<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Services\AlertService;
use App\Services\RiskService;

final class DashboardController extends BaseController
{
    public function overview(): void
    {
        $pdo = Database::connection();
        $userId = $this->userId();

        $this->generateRiskAlerts($userId);

        $holdingCount = $this->countBySql("SELECT COUNT(*) FROM positions WHERE user_id = :user_id AND status = 'holding'", $userId);
        $newSuggestions = $this->countBySql("SELECT COUNT(*) FROM openclaw_suggestions WHERE user_id = :user_id AND status = 'new'", $userId);
        $newAlerts = $this->countBySql("SELECT COUNT(*) FROM alerts WHERE user_id = :user_id AND status = 'new'", $userId);
        $activeJobs = $this->countBySql("SELECT COUNT(*) FROM openclaw_jobs WHERE user_id = :user_id AND enabled = 1", $userId);

        $risk = RiskService::calculatePortfolioRisk($userId);

        $latestSectors = $pdo->query('SELECT sector_name, strength_score, change_pct, leading_symbol, sample_time FROM sector_strength ORDER BY sample_time DESC, strength_score DESC LIMIT 5')->fetchAll() ?: [];
        $latestNews = $pdo->query('SELECT id, title, source, published_at, url FROM news_feed ORDER BY published_at DESC, id DESC LIMIT 5')->fetchAll() ?: [];

        $this->ok([
            'kpi' => [
                'holding_count' => $holdingCount,
                'new_suggestions' => $newSuggestions,
                'new_alerts' => $newAlerts,
                'active_jobs' => $activeJobs,
            ],
            'risk' => $risk,
            'sectors' => $latestSectors,
            'news' => $latestNews,
        ]);
    }

    private function generateRiskAlerts(int $userId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT id, symbol, name, current_price, stop_loss_price, take_profit_price
             FROM positions
             WHERE user_id = :user_id AND status = 'holding'"
        );
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll() ?: [];

        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $symbol = (string) $row['symbol'];
            $name = (string) ($row['name'] ?? $symbol);
            $current = $row['current_price'] !== null ? (float) $row['current_price'] : null;
            if ($current === null) {
                continue;
            }

            $stop = $row['stop_loss_price'] !== null ? (float) $row['stop_loss_price'] : null;
            $take = $row['take_profit_price'] !== null ? (float) $row['take_profit_price'] : null;

            if ($stop !== null && $current <= $stop && !$this->hasRecentAlert('stop_loss', $id, $userId)) {
                AlertService::create(
                    'stop_loss',
                    "止损触发: {$name}",
                    "{$symbol} current {$current}, touched stop loss {$stop}",
                    'critical',
                    'position',
                    $id,
                    $userId
                );
            }

            if ($take !== null && $current >= $take && !$this->hasRecentAlert('take_profit', $id, $userId)) {
                AlertService::create(
                    'take_profit',
                    "止盈触发: {$name}",
                    "{$symbol} current {$current}, touched take profit {$take}",
                    'warning',
                    'position',
                    $id,
                    $userId
                );
            }
        }
    }

    private function hasRecentAlert(string $type, string $relatedId, int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT id
             FROM alerts
             WHERE user_id = :user_id
               AND alert_type = :type
               AND related_id = :related_id
               AND triggered_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'type' => $type,
            'related_id' => $relatedId,
        ]);

        return (bool) $stmt->fetch();
    }

    private function countBySql(string $sql, int $userId): int
    {
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['user_id' => $userId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }
}
