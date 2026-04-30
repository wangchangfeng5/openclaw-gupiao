<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class DailyReviewService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId, int $days = 45): array
    {
        if ($userId <= 0) {
            return [];
        }

        $days = max(7, min(365, $days));
        $stmt = Database::connection()->prepare(
            'SELECT *
             FROM daily_operation_reviews
             WHERE user_id = :user_id
               AND review_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
             ORDER BY review_date DESC, id DESC'
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':days', $days, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            fn(array $row): array => $this->hydrateRow($row),
            $stmt->fetchAll() ?: []
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function latestForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $stmt = Database::connection()->prepare(
            'SELECT *
             FROM daily_operation_reviews
             WHERE user_id = :user_id
             ORDER BY review_date DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrateRow($row) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $userId, int $id): ?array
    {
        if ($userId <= 0 || $id <= 0) {
            return null;
        }

        $stmt = Database::connection()->prepare(
            'SELECT *
             FROM daily_operation_reviews
             WHERE user_id = :user_id AND id = :id
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'id' => $id,
        ]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrateRow($row) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function generateForUser(int $userId, ?string $reviewDate = null, string $slot = 'close'): array
    {
        if ($userId <= 0) {
            return [];
        }

        $reviewDate = $this->normalizeDate($reviewDate);
        $slot = $this->normalizeSlot($slot);
        $metrics = $this->buildMetrics($userId, $reviewDate);
        $narrative = $this->buildNarrative($metrics);

        $stmt = Database::connection()->prepare(
            'INSERT INTO daily_operation_reviews (
                user_id, review_date, review_slot, auto_generated, generated_at,
                realized_pnl, unrealized_pnl, total_pnl, trade_count, win_trade_count, loss_trade_count,
                auto_profit_summary, auto_issues, auto_shortcomings, auto_suggestions, auto_metrics_json,
                created_at, updated_at
             ) VALUES (
                :user_id, :review_date, :review_slot, 1, NOW(),
                :realized_pnl, :unrealized_pnl, :total_pnl, :trade_count, :win_trade_count, :loss_trade_count,
                :auto_profit_summary, :auto_issues, :auto_shortcomings, :auto_suggestions, :auto_metrics_json,
                NOW(), NOW()
             )
             ON DUPLICATE KEY UPDATE
                review_slot = VALUES(review_slot),
                auto_generated = 1,
                generated_at = NOW(),
                realized_pnl = VALUES(realized_pnl),
                unrealized_pnl = VALUES(unrealized_pnl),
                total_pnl = VALUES(total_pnl),
                trade_count = VALUES(trade_count),
                win_trade_count = VALUES(win_trade_count),
                loss_trade_count = VALUES(loss_trade_count),
                auto_profit_summary = VALUES(auto_profit_summary),
                auto_issues = VALUES(auto_issues),
                auto_shortcomings = VALUES(auto_shortcomings),
                auto_suggestions = VALUES(auto_suggestions),
                auto_metrics_json = VALUES(auto_metrics_json),
                updated_at = NOW()'
        );

        $stmt->execute([
            'user_id' => $userId,
            'review_date' => $reviewDate,
            'review_slot' => $slot,
            'realized_pnl' => $metrics['realized_pnl'],
            'unrealized_pnl' => $metrics['unrealized_pnl'],
            'total_pnl' => $metrics['total_pnl'],
            'trade_count' => $metrics['trade_count'],
            'win_trade_count' => $metrics['win_trade_count'],
            'loss_trade_count' => $metrics['loss_trade_count'],
            'auto_profit_summary' => $narrative['profit_summary'],
            'auto_issues' => $narrative['issues'],
            'auto_shortcomings' => $narrative['shortcomings'],
            'auto_suggestions' => $narrative['suggestions'],
            'auto_metrics_json' => json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $latest = $this->findByDate($userId, $reviewDate);
        return $latest ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function generateForAllUsers(?string $reviewDate = null, string $slot = 'close', int $targetUserId = 0): array
    {
        $reviewDate = $this->normalizeDate($reviewDate);
        $slot = $this->normalizeSlot($slot);
        $users = [];

        if ($targetUserId > 0) {
            $users = [$targetUserId];
        } else {
            $users = array_map(
                static fn(array $row): int => (int) ($row['id'] ?? 0),
                Database::connection()->query('SELECT id FROM users ORDER BY id ASC')->fetchAll() ?: []
            );
        }

        $written = 0;
        $details = [];
        foreach ($users as $userId) {
            if ($userId <= 0) {
                continue;
            }
            $row = $this->generateForUser($userId, $reviewDate, $slot);
            if ($row !== []) {
                $written++;
                $details[] = [
                    'user_id' => $userId,
                    'review_date' => (string) ($row['review_date'] ?? $reviewDate),
                    'total_pnl' => $row['total_pnl'] ?? 0,
                    'trade_count' => $row['trade_count'] ?? 0,
                ];
            }
        }

        return [
            'review_date' => $reviewDate,
            'slot' => $slot,
            'users_total' => count($users),
            'written' => $written,
            'details' => $details,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateSelfReview(int $userId, int $id, array $payload): ?array
    {
        if ($userId <= 0 || $id <= 0) {
            return null;
        }

        $allowed = [
            'self_profit_summary',
            'self_issues',
            'self_shortcomings',
            'self_suggestions',
            'self_plan',
            'self_score',
        ];

        $set = [];
        $bindings = [
            'id' => $id,
            'user_id' => $userId,
        ];

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $set[] = $key . ' = :' . $key;
            if ($key === 'self_score') {
                $v = $payload[$key];
                if ($v === null || $v === '') {
                    $bindings[$key] = null;
                } else {
                    $bindings[$key] = max(0, min(100, (int) $v));
                }
                continue;
            }
            $bindings[$key] = $this->sanitizeText($payload[$key]);
        }

        if ($set === []) {
            return $this->findById($userId, $id);
        }

        $sql = sprintf(
            'UPDATE daily_operation_reviews
             SET %s, updated_at = NOW()
             WHERE id = :id AND user_id = :user_id',
            implode(', ', $set)
        );

        $stmt = Database::connection()->prepare($sql);
        foreach ($bindings as $key => $value) {
            if ($key === 'id' || $key === 'user_id') {
                $stmt->bindValue(':' . $key, (int) $value, \PDO::PARAM_INT);
                continue;
            }
            if ($value === null) {
                $stmt->bindValue(':' . $key, null, \PDO::PARAM_NULL);
                continue;
            }
            if ($key === 'self_score') {
                $stmt->bindValue(':' . $key, (int) $value, \PDO::PARAM_INT);
                continue;
            }
            $stmt->bindValue(':' . $key, (string) $value);
        }
        $stmt->execute();

        return $this->findById($userId, $id);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByDate(int $userId, string $reviewDate): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT *
             FROM daily_operation_reviews
             WHERE user_id = :user_id
               AND review_date = :review_date
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'review_date' => $reviewDate,
        ]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrateRow($row) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function hydrateRow(array $row): array
    {
        $metrics = [];
        $raw = $row['auto_metrics_json'] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $metrics = $decoded;
            }
        } elseif (is_array($raw)) {
            $metrics = $raw;
        }
        $row['auto_metrics'] = $metrics;
        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMetrics(int $userId, string $reviewDate): array
    {
        $tradeSummaryStmt = Database::connection()->prepare(
            "SELECT
                COUNT(*) AS trade_count,
                SUM(CASE WHEN realized_pnl IS NOT NULL THEN 1 ELSE 0 END) AS realized_trade_count,
                SUM(CASE WHEN realized_pnl > 0 THEN 1 ELSE 0 END) AS win_trade_count,
                SUM(CASE WHEN realized_pnl < 0 THEN 1 ELSE 0 END) AS loss_trade_count,
                COALESCE(SUM(realized_pnl), 0) AS realized_pnl
             FROM position_trades
             WHERE user_id = :user_id
               AND DATE(traded_at) = :review_date"
        );
        $tradeSummaryStmt->execute([
            'user_id' => $userId,
            'review_date' => $reviewDate,
        ]);
        $tradeSummary = $tradeSummaryStmt->fetch() ?: [];

        $tradeRowsStmt = Database::connection()->prepare(
            "SELECT
                pt.id,
                pt.position_id,
                pt.trade_type,
                pt.quantity,
                pt.price,
                pt.realized_pnl,
                pt.traded_at,
                p.symbol,
                p.name
             FROM position_trades pt
             INNER JOIN positions p
                ON p.id = pt.position_id
               AND p.user_id = pt.user_id
             WHERE pt.user_id = :user_id
               AND DATE(pt.traded_at) = :review_date
             ORDER BY pt.traded_at ASC, pt.id ASC
             LIMIT 260"
        );
        $tradeRowsStmt->execute([
            'user_id' => $userId,
            'review_date' => $reviewDate,
        ]);
        $tradeRows = $tradeRowsStmt->fetchAll() ?: [];

        $holdingStmt = Database::connection()->prepare(
            "SELECT
                p.symbol,
                p.name,
                p.quantity,
                p.cost_price,
                p.current_price,
                p.stop_loss_price,
                q.price AS quote_price
             FROM positions p
             LEFT JOIN market_quotes_latest q
                ON q.symbol = p.symbol AND q.market = p.market
             WHERE p.user_id = :user_id
               AND p.quantity > 0
               AND p.status IN ('holding', 'watching')
             ORDER BY p.updated_at DESC, p.id DESC"
        );
        $holdingStmt->execute(['user_id' => $userId]);
        $holdings = $holdingStmt->fetchAll() ?: [];

        $realizedPnl = round((float) ($tradeSummary['realized_pnl'] ?? 0), 4);
        $tradeCount = (int) ($tradeSummary['trade_count'] ?? 0);
        $realizedTradeCount = (int) ($tradeSummary['realized_trade_count'] ?? 0);
        $winTradeCount = (int) ($tradeSummary['win_trade_count'] ?? 0);
        $lossTradeCount = (int) ($tradeSummary['loss_trade_count'] ?? 0);

        $totalCost = 0.0;
        $totalMarket = 0.0;
        $stoplossMissingCount = 0;
        $holdingRows = [];
        foreach ($holdings as $row) {
            $qty = max(0.0, (float) ($row['quantity'] ?? 0));
            if ($qty <= 0) {
                continue;
            }

            $costPrice = (float) ($row['cost_price'] ?? 0);
            $quotePrice = (float) ($row['quote_price'] ?? 0);
            $currentPrice = (float) ($row['current_price'] ?? 0);
            $price = $quotePrice > 0 ? $quotePrice : ($currentPrice > 0 ? $currentPrice : $costPrice);
            if ($price <= 0 || $costPrice <= 0) {
                continue;
            }

            $marketValue = $qty * $price;
            $costValue = $qty * $costPrice;
            $totalCost += $costValue;
            $totalMarket += $marketValue;

            $stopLossPrice = (float) ($row['stop_loss_price'] ?? 0);
            if ($stopLossPrice <= 0) {
                $stoplossMissingCount++;
            }

            $holdingRows[] = [
                'symbol' => (string) ($row['symbol'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'market_value' => round($marketValue, 4),
                'weight_pct' => 0,
                'pnl_pct' => $costValue > 0 ? round((($marketValue - $costValue) / $costValue) * 100, 4) : 0,
            ];
        }

        usort(
            $holdingRows,
            static fn(array $a, array $b): int => (float) ($b['market_value'] ?? 0) <=> (float) ($a['market_value'] ?? 0)
        );
        foreach ($holdingRows as &$row) {
            $row['weight_pct'] = $totalMarket > 0
                ? round(((float) ($row['market_value'] ?? 0) / $totalMarket) * 100, 4)
                : 0;
        }
        unset($row);

        $unrealizedPnl = round($totalMarket - $totalCost, 4);
        $totalPnl = round($realizedPnl + $unrealizedPnl, 4);
        $top1Pct = isset($holdingRows[0]['weight_pct']) ? (float) $holdingRows[0]['weight_pct'] : 0.0;
        $top3Pct = 0.0;
        foreach (array_slice($holdingRows, 0, 3) as $row) {
            $top3Pct += (float) ($row['weight_pct'] ?? 0);
        }
        $top3Pct = round($top3Pct, 4);

        $winTrades = array_values(array_filter(
            $tradeRows,
            static fn(array $row): bool => isset($row['realized_pnl']) && (float) $row['realized_pnl'] > 0
        ));
        usort(
            $winTrades,
            static fn(array $a, array $b): int => (float) ($b['realized_pnl'] ?? 0) <=> (float) ($a['realized_pnl'] ?? 0)
        );

        $lossTrades = array_values(array_filter(
            $tradeRows,
            static fn(array $row): bool => isset($row['realized_pnl']) && (float) $row['realized_pnl'] < 0
        ));
        usort(
            $lossTrades,
            static fn(array $a, array $b): int => (float) ($a['realized_pnl'] ?? 0) <=> (float) ($b['realized_pnl'] ?? 0)
        );

        $sumWinPnl = 0.0;
        foreach ($winTrades as $row) {
            $sumWinPnl += (float) ($row['realized_pnl'] ?? 0);
        }

        $sumLossPnl = 0.0;
        foreach ($lossTrades as $row) {
            $sumLossPnl += (float) ($row['realized_pnl'] ?? 0);
        }

        $avgWinPnl = $winTradeCount > 0 ? round($sumWinPnl / $winTradeCount, 4) : null;
        $avgLossPnl = $lossTradeCount > 0 ? round($sumLossPnl / $lossTradeCount, 4) : null;
        $winRate = $realizedTradeCount > 0 ? round(($winTradeCount / $realizedTradeCount) * 100, 2) : null;
        $profitFactor = $sumLossPnl < 0 ? round($sumWinPnl / abs($sumLossPnl), 4) : null;

        $marketOverview = $this->loadMarketOverview();
        $marketRegime = (string) ($marketOverview['regime'] ?? 'neutral');

        $score = 82;
        if ($totalPnl < 0) {
            $score -= 20;
        }
        if ($realizedPnl < 0) {
            $score -= 10;
        }
        if ($lossTradeCount > $winTradeCount) {
            $score -= 8;
        }
        if ($tradeCount >= 8) {
            $score -= 8;
        } elseif ($tradeCount >= 5) {
            $score -= 4;
        }
        if ($top1Pct >= 45) {
            $score -= 12;
        } elseif ($top1Pct >= 35) {
            $score -= 6;
        }
        if ($stoplossMissingCount > 0) {
            $score -= min(16, $stoplossMissingCount * 4);
        }
        if ($totalPnl > 0) {
            $score += 6;
        }
        if ($marketRegime === 'cold' && $totalPnl > 0) {
            $score += 3;
        }
        if ($marketRegime === 'hot' && $totalPnl < 0) {
            $score -= 4;
        }
        $score = max(0, min(100, $score));

        $grade = $score >= 90 ? 'A+' : ($score >= 80 ? 'A' : ($score >= 70 ? 'B' : ($score >= 60 ? 'C' : 'D')));

        $ratingComment = '执行一般，建议回归计划。';
        if ($score >= 90) {
            $ratingComment = '执行非常稳定，仓位和节奏控制优秀。';
        } elseif ($score >= 80) {
            $ratingComment = '执行较稳，风控与节奏基本达标。';
        } elseif ($score >= 70) {
            $ratingComment = '有亮点，但仍有可优化空间。';
        } elseif ($score < 60) {
            $ratingComment = '执行偏弱，需优先修复风控与纪律。';
        }

        $strengthPoints = [];
        if ($totalPnl > 0) {
            $strengthPoints[] = '当日总体收益为正，说明择时或节奏有一定有效性。';
        }
        if ($winRate !== null && $winRate >= 55) {
            $strengthPoints[] = sprintf('胜率 %.2f%%，交易筛选质量尚可。', $winRate);
        }
        if ($tradeCount > 0 && $tradeCount <= 4) {
            $strengthPoints[] = '交易频率控制较好，避免了过度操作。';
        }
        if ($stoplossMissingCount === 0 && count($holdingRows) > 0) {
            $strengthPoints[] = '持仓止损设置完整，风控执行到位。';
        }
        if ($marketRegime === 'cold' && $totalPnl >= 0) {
            $strengthPoints[] = '在偏弱市场环境下仍保持稳定，抗波动能力较好。';
        }
        if ($strengthPoints === []) {
            $strengthPoints[] = '今日暂无明显优势项，建议先聚焦纪律与风控一致性。';
        }

        $improvementPoints = [];
        if ($lossTradeCount > $winTradeCount) {
            $improvementPoints[] = '提高开仓筛选阈值，减少低质量试错单。';
        }
        if ($tradeCount >= 6) {
            $improvementPoints[] = '控制交易频率，优先做高确定性机会。';
        }
        if ($top1Pct >= 40) {
            $improvementPoints[] = '降低单票仓位集中度，增强组合稳定性。';
        }
        if ($stoplossMissingCount > 0) {
            $improvementPoints[] = '补齐并前置止损设置，做到触发即执行。';
        }
        if ($avgLossPnl !== null && $avgWinPnl !== null && abs($avgLossPnl) > $avgWinPnl) {
            $improvementPoints[] = '优化盈亏比，避免单笔亏损大于平均盈利。';
        }
        if ($improvementPoints === []) {
            $improvementPoints[] = '保持当前流程，继续通过复盘微调入场与仓位管理。';
        }

        return [
            'review_date' => $reviewDate,
            'realized_pnl' => $realizedPnl,
            'unrealized_pnl' => $unrealizedPnl,
            'total_pnl' => $totalPnl,
            'trade_count' => $tradeCount,
            'realized_trade_count' => $realizedTradeCount,
            'win_trade_count' => $winTradeCount,
            'loss_trade_count' => $lossTradeCount,
            'win_rate_pct' => $winRate,
            'avg_win_pnl' => $avgWinPnl,
            'avg_loss_pnl' => $avgLossPnl,
            'profit_factor' => $profitFactor,
            'holding_count' => count($holdingRows),
            'stoploss_missing_count' => $stoplossMissingCount,
            'concentration_top1_pct' => round($top1Pct, 4),
            'concentration_top3_pct' => $top3Pct,
            'score' => $score,
            'grade' => $grade,
            'today_rating' => [
                'score' => $score,
                'grade' => $grade,
                'comment' => $ratingComment,
            ],
            'market_overview' => $marketOverview,
            'strength_points' => $strengthPoints,
            'improvement_points' => $improvementPoints,
            'top_holdings' => array_slice($holdingRows, 0, 8),
            'top_win_trades' => array_slice($winTrades, 0, 5),
            'top_loss_trades' => array_slice($lossTrades, 0, 5),
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array<string, string>
     */
    private function buildNarrative(array $metrics): array
    {
        $market = is_array($metrics['market_overview'] ?? null) ? $metrics['market_overview'] : [];
        $rating = is_array($metrics['today_rating'] ?? null) ? $metrics['today_rating'] : [];

        $profitSummary = sprintf(
            '今日已实现 %.2f，持仓浮动 %.2f，合计 %.2f；交易 %d 笔（赢 %d / 亏 %d，胜率 %s）。市场：上涨 %d，下跌 %d，平盘 %d，平均涨跌 %.2f%%。今日评分 %s(%d)：%s',
            (float) ($metrics['realized_pnl'] ?? 0),
            (float) ($metrics['unrealized_pnl'] ?? 0),
            (float) ($metrics['total_pnl'] ?? 0),
            (int) ($metrics['trade_count'] ?? 0),
            (int) ($metrics['win_trade_count'] ?? 0),
            (int) ($metrics['loss_trade_count'] ?? 0),
            isset($metrics['win_rate_pct']) && $metrics['win_rate_pct'] !== null ? number_format((float) $metrics['win_rate_pct'], 2) . '%' : '-',
            (int) ($market['up_count'] ?? 0),
            (int) ($market['down_count'] ?? 0),
            (int) ($market['flat_count'] ?? 0),
            (float) ($market['avg_change_pct'] ?? 0),
            (string) ($rating['grade'] ?? '-'),
            (int) ($rating['score'] ?? 0),
            (string) ($rating['comment'] ?? '无')
        );

        $issues = [];
        $shortcomings = [];
        $suggestions = [];

        $totalPnl = (float) ($metrics['total_pnl'] ?? 0);
        $tradeCount = (int) ($metrics['trade_count'] ?? 0);
        $winTrades = (int) ($metrics['win_trade_count'] ?? 0);
        $lossTrades = (int) ($metrics['loss_trade_count'] ?? 0);
        $top1 = (float) ($metrics['concentration_top1_pct'] ?? 0);
        $stoplossMissing = (int) ($metrics['stoploss_missing_count'] ?? 0);
        $topLossTrades = is_array($metrics['top_loss_trades'] ?? null) ? $metrics['top_loss_trades'] : [];

        $marketRegime = (string) ($market['regime'] ?? 'neutral');
        $upCount = (int) ($market['up_count'] ?? 0);
        $downCount = (int) ($market['down_count'] ?? 0);
        $avgChange = (float) ($market['avg_change_pct'] ?? 0);

        if ($totalPnl < 0) {
            $issues[] = '今日总体收益为负，收益曲线与风险暴露不匹配。';
            $shortcomings[] = '执行中可能存在情绪化动作，亏损控制未完全按计划。';
            $suggestions[] = '下一交易日先防守，优先把总风险降下来。';
        }

        if ($lossTrades > $winTrades) {
            $issues[] = '亏损笔数高于盈利笔数，交易质量偏弱。';
            $shortcomings[] = '信号筛选不够严格，入场标准存在放宽。';
            $suggestions[] = '收紧开仓条件，只做最强信号与最清晰结构。';
        }

        if ($tradeCount >= 8) {
            $issues[] = '交易频次偏高，容易出现无效操作。';
            $shortcomings[] = '节奏控制不足，存在临盘临时决策。';
            $suggestions[] = '限制单日交易次数，先评估机会质量再出手。';
        }

        if ($top1 >= 45) {
            $issues[] = '单票仓位集中度过高，回撤弹性会放大。';
            $shortcomings[] = '仓位结构分散不够，组合风险过于集中。';
            $suggestions[] = '单票仓位控制在 30%-35% 内，分批调整结构。';
        }

        if ($stoplossMissing > 0) {
            $issues[] = sprintf('仍有 %d 只持仓未设置止损位。', $stoplossMissing);
            $shortcomings[] = '风控动作滞后，止损纪律未完全落地。';
            $suggestions[] = '开盘前补全止损价，触发条件后严格执行。';
        }

        if ($marketRegime === 'hot' && $avgChange >= 1.0) {
            $issues[] = '市场处于偏热区间，追高回撤风险上升。';
            $suggestions[] = '后续以分歧低吸或回踩确认后介入为主，不盲目追涨。';
        } elseif ($marketRegime === 'cold' && $avgChange <= -1.0) {
            $issues[] = '市场偏弱，容错率下降。';
            $suggestions[] = '后续先保留仓位弹性，优先观察防守和低波动方向。';
        } else {
            $suggestions[] = '市场偏中性，按“计划-触发-执行”流程做结构化交易。';
        }

        if ($upCount > 0 && $downCount > 0 && ($downCount / max(1, $upCount)) >= 1.35) {
            $issues[] = '下跌家数明显多于上涨家数，情绪面偏弱。';
            $suggestions[] = '后续减少逆势交易，等宽度修复后再提升进攻仓位。';
        }

        if ($topLossTrades !== []) {
            $worst = $topLossTrades[0];
            $worstLoss = (float) ($worst['realized_pnl'] ?? 0);
            if ($worstLoss <= -300) {
                $issues[] = sprintf(
                    '单笔亏损偏大（%s %s，%.2f），需要复盘进出场依据。',
                    (string) ($worst['symbol'] ?? ''),
                    (string) ($worst['name'] ?? ''),
                    $worstLoss
                );
                $suggestions[] = '对大亏单做单独复盘，写清触发条件和纠偏动作。';
            }
        }

        $improvements = is_array($metrics['improvement_points'] ?? null) ? $metrics['improvement_points'] : [];
        foreach ($improvements as $point) {
            if (!is_string($point) || trim($point) === '') {
                continue;
            }
            $shortcomings[] = trim($point);
        }

        if ($issues === []) {
            $issues[] = '今日未出现明显结构性错误，执行相对稳定。';
        }
        if ($shortcomings === []) {
            $shortcomings[] = '仍需持续强化一致性，避免临盘冲动。';
        }
        if ($suggestions === []) {
            $suggestions[] = '继续按计划交易，保持仓位与止损纪律。';
        }

        return [
            'profit_summary' => $profitSummary,
            'issues' => $this->toBulletText($issues),
            'shortcomings' => $this->toBulletText($shortcomings),
            'suggestions' => $this->toBulletText($suggestions),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadMarketOverview(): array
    {
        $stmt = Database::connection()->query(
            "SELECT
                COUNT(*) AS total_count,
                SUM(CASE WHEN change_pct > 0 THEN 1 ELSE 0 END) AS up_count,
                SUM(CASE WHEN change_pct < 0 THEN 1 ELSE 0 END) AS down_count,
                SUM(CASE WHEN change_pct = 0 OR change_pct IS NULL THEN 1 ELSE 0 END) AS flat_count,
                AVG(change_pct) AS avg_change_pct,
                SUM(CASE WHEN change_pct >= 9.7 THEN 1 ELSE 0 END) AS limit_up_count,
                SUM(CASE WHEN change_pct <= -9.7 THEN 1 ELSE 0 END) AS limit_down_count
             FROM market_quotes_latest
             WHERE market = 'A_STOCK_MAIN'
               AND symbol REGEXP '^(000|001|002|003|600|601|603|605)[0-9]{3}$'"
        );
        $row = $stmt->fetch() ?: [];

        $total = (int) ($row['total_count'] ?? 0);
        $up = (int) ($row['up_count'] ?? 0);
        $down = (int) ($row['down_count'] ?? 0);
        $flat = (int) ($row['flat_count'] ?? 0);
        $avg = round((float) ($row['avg_change_pct'] ?? 0), 4);
        $upRatio = $total > 0 ? round(($up / $total) * 100, 2) : 0.0;
        $downRatio = $total > 0 ? round(($down / $total) * 100, 2) : 0.0;

        $regime = 'neutral';
        if ($upRatio >= 65 && $avg >= 1.0) {
            $regime = 'hot';
        } elseif ($downRatio >= 60 && $avg <= -1.0) {
            $regime = 'cold';
        }

        $hotSectors = [];
        $weakSectors = [];
        $latestSample = (string) (Database::connection()->query('SELECT MAX(sample_time) FROM sector_strength')->fetchColumn() ?: '');
        if ($latestSample !== '') {
            $hotStmt = Database::connection()->prepare(
                "SELECT sector_name, strength_score, change_pct, leading_symbol, active_count
                 FROM sector_strength
                 WHERE sample_time = :sample_time
                 ORDER BY strength_score DESC, change_pct DESC
                 LIMIT 5"
            );
            $hotStmt->execute(['sample_time' => $latestSample]);
            $hotSectors = $hotStmt->fetchAll() ?: [];

            $weakStmt = Database::connection()->prepare(
                "SELECT sector_name, strength_score, change_pct, leading_symbol, active_count
                 FROM sector_strength
                 WHERE sample_time = :sample_time
                 ORDER BY strength_score ASC, change_pct ASC
                 LIMIT 5"
            );
            $weakStmt->execute(['sample_time' => $latestSample]);
            $weakSectors = $weakStmt->fetchAll() ?: [];
        }

        return [
            'total_count' => $total,
            'up_count' => $up,
            'down_count' => $down,
            'flat_count' => $flat,
            'up_ratio_pct' => $upRatio,
            'down_ratio_pct' => $downRatio,
            'avg_change_pct' => $avg,
            'limit_up_count' => (int) ($row['limit_up_count'] ?? 0),
            'limit_down_count' => (int) ($row['limit_down_count'] ?? 0),
            'regime' => $regime,
            'sample_time' => $latestSample !== '' ? $latestSample : null,
            'hot_sectors' => $hotSectors,
            'weak_sectors' => $weakSectors,
        ];
    }

    /**
     * @param array<int, string> $rows
     */
    private function toBulletText(array $rows): string
    {
        $rows = array_values(array_filter(array_map(
            fn(string $x): string => trim($x),
            $rows
        ), static fn(string $x): bool => $x !== ''));

        if ($rows === []) {
            return '';
        }

        return implode("\n", array_map(static fn(string $x): string => '- ' . $x, $rows));
    }

    private function normalizeDate(?string $date): string
    {
        $date = trim((string) $date);
        if ($date === '') {
            return date('Y-m-d');
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return date('Y-m-d');
        }
        return date('Y-m-d', $ts);
    }

    private function normalizeSlot(string $slot): string
    {
        $slot = strtolower(trim($slot));
        return in_array($slot, ['close', 'manual'], true) ? $slot : 'close';
    }

    private function sanitizeText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }
}
