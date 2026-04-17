<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class RiskService
{
    public static function calculatePortfolioRisk(?int $userId = null): array
    {
        $userId = self::resolveUserId($userId);
        $stmt = Database::connection()->prepare(
            'SELECT id, symbol, quantity, cost_price, current_price, stop_loss_price, status
             FROM positions
             WHERE user_id = :user_id AND status = "holding"'
        );
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll() ?: [];
        return self::calculateFromRows($rows);
    }

    public static function calculateFromRows(array $rows): array
    {
        $rows = array_values(array_filter($rows, static function (mixed $row): bool {
            return is_array($row);
        }));

        $totalCost = 0.0;
        $totalMarket = 0.0;
        $maxPosition = 0.0;
        $stopLossRiskCount = 0;

        foreach ($rows as $row) {
            $qty = (float) $row['quantity'];
            $cost = (float) $row['cost_price'];
            $current = (float) ($row['current_price'] ?? $cost);
            $stopLoss = $row['stop_loss_price'] !== null ? (float) $row['stop_loss_price'] : null;

            $costValue = $qty * $cost;
            $marketValue = $qty * $current;

            $totalCost += $costValue;
            $totalMarket += $marketValue;
            $maxPosition = max($maxPosition, $marketValue);

            if ($stopLoss !== null && $current <= $stopLoss) {
                $stopLossRiskCount++;
            }
        }

        $unrealizedPnl = $totalMarket - $totalCost;
        $drawdown = $totalCost > 0 ? min(0.0, $unrealizedPnl / $totalCost * 100) : 0.0;
        $concentration = $totalMarket > 0 ? ($maxPosition / $totalMarket * 100) : 0.0;

        return [
            'holding_count' => count($rows),
            'total_cost_value' => round($totalCost, 2),
            'total_market_value' => round($totalMarket, 2),
            'unrealized_pnl' => round($unrealizedPnl, 2),
            'daily_drawdown_pct' => round($drawdown, 2),
            'concentration_score' => round($concentration, 2),
            'stoploss_risk_count' => $stopLossRiskCount,
        ];
    }

    public static function snapshot(?int $userId = null): void
    {
        $userId = self::resolveUserId($userId);
        $risk = self::calculatePortfolioRisk($userId);
        $stmt = Database::connection()->prepare(
            'INSERT INTO risk_snapshots (user_id, total_market_value, total_cost_value, unrealized_pnl, daily_drawdown, concentration_score, stoploss_risk_count, snapshot_time, raw_json, created_at)
             VALUES (:user_id, :total_market_value, :total_cost_value, :unrealized_pnl, :daily_drawdown, :concentration_score, :stoploss_risk_count, NOW(), :raw_json, NOW())'
        );

        $stmt->execute([
            'user_id' => $userId > 0 ? $userId : null,
            'total_market_value' => $risk['total_market_value'],
            'total_cost_value' => $risk['total_cost_value'],
            'unrealized_pnl' => $risk['unrealized_pnl'],
            'daily_drawdown' => $risk['daily_drawdown_pct'],
            'concentration_score' => $risk['concentration_score'],
            'stoploss_risk_count' => $risk['stoploss_risk_count'],
            'raw_json' => json_encode($risk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private static function resolveUserId(?int $userId): int
    {
        if ($userId !== null && $userId > 0) {
            return $userId;
        }

        return (int) (AuthService::id() ?? 0);
    }
}
