<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class SymbolInsightService
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $latestSuggestionMap = [];
    private ?int $latestSuggestionUserId = null;
    private bool $latestSuggestionLoaded = false;
    private ?bool $hasInsightsLatestTable = null;

    public function analyze(
        string $symbol,
        string $market = 'A_STOCK_MAIN',
        ?float $currentPrice = null,
        ?float $stopLossHint = null,
        ?float $takeProfitHint = null,
        string $scenario = 'watchlist',
        ?int $userId = null
    ): array {
        $prices = $this->recentPrices($symbol, $market, 40);

        if (($currentPrice === null || $currentPrice <= 0) && count($prices) > 0) {
            $currentPrice = $prices[0];
        }

        $hasPrice = $currentPrice !== null && $currentPrice > 0;

        if ($hasPrice && count($prices) >= 5) {
            $window = array_slice($prices, 0, 20);
            $support = (float) min($window);
            $resistance = (float) max($window);
        } elseif ($hasPrice) {
            $support = (float) $currentPrice * 0.97;
            $resistance = (float) $currentPrice * 1.03;
        } else {
            $support = null;
            $resistance = null;
        }

        if ($hasPrice && $support !== null && $resistance !== null && ($resistance - $support) < (((float) $currentPrice) * 0.01)) {
            $support = (float) $currentPrice * 0.98;
            $resistance = (float) $currentPrice * 1.02;
        }

        $stopLoss = ($stopLossHint !== null && $stopLossHint > 0 && $hasPrice)
            ? $stopLossHint
            : ($support !== null ? $support * 0.995 : null);

        $takeProfit = ($takeProfitHint !== null && $takeProfitHint > 0 && $hasPrice)
            ? $takeProfitHint
            : ($resistance !== null && $currentPrice !== null ? max($resistance * 0.995, $currentPrice * 1.03) : null);

        $zone = $this->zone($currentPrice, $support, $resistance);
        $openclaw = $this->latestOpenClawSuggestion($symbol, $userId);

        $advice = $this->advice(
            $zone,
            $currentPrice,
            $support,
            $resistance,
            $stopLoss,
            $takeProfit,
            $scenario,
            $openclaw['content'] ?? null
        );

        $confidence = 55 + min(30, count($prices));
        if (!$hasPrice) {
            $confidence -= 12;
        }
        if ($openclaw !== null) {
            $confidence += 5;
        }
        $confidence = max(50, min(95, $confidence));

        return [
            'symbol' => strtoupper($symbol),
            'market' => $market,
            'current_price' => $currentPrice !== null && $currentPrice > 0 ? $this->round4($currentPrice) : null,
            'support_price' => $support !== null ? $this->round4($support) : null,
            'resistance_price' => $resistance !== null ? $this->round4($resistance) : null,
            'stop_loss_price' => $stopLoss !== null ? $this->round4($stopLoss) : null,
            'take_profit_price' => $takeProfit !== null ? $this->round4($takeProfit) : null,
            'position_zone' => $zone,
            'action_advice' => $advice,
            'confidence' => round((float) $confidence, 2),
            'openclaw_note' => $openclaw['content'] ?? null,
            'openclaw_confidence' => $openclaw['confidence'] ?? null,
            'analyzed_at' => now_sql(),
            'next_review_at' => date('Y-m-d H:i:s', time() + 3600),
            'sample_size' => count($prices),
        ];
    }

    public function saveWatchlistInsight(int $watchlistId, array $analysis, ?int $userId = null): int
    {
        $normalizedUserId = max(0, (int) ($userId ?? 0));

        $stmt = Database::connection()->prepare(
            'INSERT INTO watchlist_insights (
                user_id, watchlist_id, symbol, current_price, support_price, resistance_price,
                stop_loss_price, take_profit_price, position_zone, action_advice, confidence,
                openclaw_note, analyzed_at, next_review_at, raw_json, created_at, updated_at
             ) VALUES (
                :user_id, :watchlist_id, :symbol, :current_price, :support_price, :resistance_price,
                :stop_loss_price, :take_profit_price, :position_zone, :action_advice, :confidence,
                :openclaw_note, :analyzed_at, :next_review_at, :raw_json, NOW(), NOW()
             )'
        );

        $stmt->execute([
            'user_id' => $normalizedUserId,
            'watchlist_id' => $watchlistId,
            'symbol' => $analysis['symbol'] ?? '',
            'current_price' => $analysis['current_price'] ?? null,
            'support_price' => $analysis['support_price'] ?? null,
            'resistance_price' => $analysis['resistance_price'] ?? null,
            'stop_loss_price' => $analysis['stop_loss_price'] ?? null,
            'take_profit_price' => $analysis['take_profit_price'] ?? null,
            'position_zone' => $analysis['position_zone'] ?? null,
            'action_advice' => $analysis['action_advice'] ?? '',
            'confidence' => $analysis['confidence'] ?? null,
            'openclaw_note' => $analysis['openclaw_note'] ?? null,
            'analyzed_at' => $analysis['analyzed_at'] ?? now_sql(),
            'next_review_at' => $analysis['next_review_at'] ?? null,
            'raw_json' => json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $insightId = (int) Database::connection()->lastInsertId();

        if ($this->hasWatchlistInsightsLatestTable()) {
            $latestStmt = Database::connection()->prepare(
                'INSERT INTO watchlist_insights_latest (
                    user_id, watchlist_id, insight_id, symbol, current_price, support_price, resistance_price,
                    stop_loss_price, take_profit_price, position_zone, action_advice, confidence,
                    openclaw_note, analyzed_at, next_review_at, raw_json, created_at, updated_at
                 ) VALUES (
                    :user_id, :watchlist_id, :insight_id, :symbol, :current_price, :support_price, :resistance_price,
                    :stop_loss_price, :take_profit_price, :position_zone, :action_advice, :confidence,
                    :openclaw_note, :analyzed_at, :next_review_at, :raw_json, NOW(), NOW()
                 )
                 ON DUPLICATE KEY UPDATE
                    insight_id = VALUES(insight_id),
                    symbol = VALUES(symbol),
                    current_price = VALUES(current_price),
                    support_price = VALUES(support_price),
                    resistance_price = VALUES(resistance_price),
                    stop_loss_price = VALUES(stop_loss_price),
                    take_profit_price = VALUES(take_profit_price),
                    position_zone = VALUES(position_zone),
                    action_advice = VALUES(action_advice),
                    confidence = VALUES(confidence),
                    openclaw_note = VALUES(openclaw_note),
                    analyzed_at = VALUES(analyzed_at),
                    next_review_at = VALUES(next_review_at),
                    raw_json = VALUES(raw_json),
                    updated_at = NOW()'
            );
            $latestStmt->execute([
                'user_id' => $normalizedUserId,
                'watchlist_id' => $watchlistId,
                'insight_id' => $insightId,
                'symbol' => $analysis['symbol'] ?? '',
                'current_price' => $analysis['current_price'] ?? null,
                'support_price' => $analysis['support_price'] ?? null,
                'resistance_price' => $analysis['resistance_price'] ?? null,
                'stop_loss_price' => $analysis['stop_loss_price'] ?? null,
                'take_profit_price' => $analysis['take_profit_price'] ?? null,
                'position_zone' => $analysis['position_zone'] ?? null,
                'action_advice' => $analysis['action_advice'] ?? '',
                'confidence' => $analysis['confidence'] ?? null,
                'openclaw_note' => $analysis['openclaw_note'] ?? null,
                'analyzed_at' => $analysis['analyzed_at'] ?? now_sql(),
                'next_review_at' => $analysis['next_review_at'] ?? null,
                'raw_json' => json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }

        return $insightId;
    }

    private function recentPrices(string $symbol, string $market, int $limit): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT price
             FROM market_quotes
             WHERE symbol = :symbol AND market = :market AND price IS NOT NULL
             ORDER BY quote_time DESC, id DESC
             LIMIT :limit'
        );

        $stmt->bindValue(':symbol', strtoupper($symbol));
        $stmt->bindValue(':market', $market);
        $stmt->bindValue(':limit', max(5, min(120, $limit)), \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $prices = [];

        foreach ($rows as $row) {
            $price = isset($row['price']) ? (float) $row['price'] : 0.0;
            if ($price > 0) {
                $prices[] = $price;
            }
        }

        return $prices;
    }

    private function latestOpenClawSuggestion(string $symbol, ?int $userId = null): ?array
    {
        $target = strtoupper(trim($symbol));
        if ($target === '') {
            return null;
        }

        $this->ensureSuggestionCache($userId);
        $row = $this->latestSuggestionMap[$target] ?? null;
        return is_array($row) ? $row : null;
    }

    private function ensureSuggestionCache(?int $userId = null): void
    {
        $normalizedUserId = ($userId !== null && $userId > 0) ? $userId : null;
        if (
            $this->latestSuggestionLoaded
            && $this->latestSuggestionUserId === $normalizedUserId
        ) {
            return;
        }

        $this->latestSuggestionLoaded = true;
        $this->latestSuggestionUserId = $normalizedUserId;
        $this->latestSuggestionMap = [];

        $limit = max(80, min(2000, (int) env('SUGGESTION_CACHE_LIMIT', 600)));

        if ($normalizedUserId !== null) {
            $stmt = Database::connection()->prepare(
                'SELECT content, confidence, symbols_json, suggested_at
                 FROM openclaw_suggestions
                 WHERE user_id = :user_id
                 ORDER BY suggested_at DESC, id DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':user_id', $normalizedUserId, \PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = Database::connection()->prepare(
                'SELECT content, confidence, symbols_json, suggested_at
                 FROM openclaw_suggestions
                 ORDER BY suggested_at DESC, id DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
        }

        $rows = $stmt->fetchAll() ?: [];
        foreach ($rows as $row) {
            $symbols = $this->decodeSymbols($row['symbols_json'] ?? null);
            if ($symbols === []) {
                continue;
            }

            foreach ($symbols as $symbol) {
                if (isset($this->latestSuggestionMap[$symbol])) {
                    continue;
                }
                $this->latestSuggestionMap[$symbol] = [
                    'content' => (string) ($row['content'] ?? ''),
                    'confidence' => $row['confidence'] ?? null,
                    'suggested_at' => (string) ($row['suggested_at'] ?? ''),
                ];
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function decodeSymbols(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }

        if (!is_array($value)) {
            return [];
        }

        $symbols = [];
        foreach ($value as $item) {
            $symbol = strtoupper(trim((string) $item));
            if ($symbol === '') {
                continue;
            }
            $symbols[] = $symbol;
        }

        return $symbols;
    }

    private function zone(?float $current, ?float $support, ?float $resistance): string
    {
        if ($current === null || $support === null || $resistance === null || $current <= 0 || $support <= 0 || $resistance <= 0 || $resistance <= $support) {
            return 'unknown';
        }

        $pos = ($current - $support) / max(0.00001, ($resistance - $support));
        if ($pos <= 0.2) {
            return 'support_zone';
        }
        if ($pos >= 0.8) {
            return 'resistance_zone';
        }
        return 'middle_zone';
    }

    private function advice(
        string $zone,
        ?float $current,
        ?float $support,
        ?float $resistance,
        ?float $stopLoss,
        ?float $takeProfit,
        string $scenario,
        ?string $openclawNote
    ): string {
        $riskText = $stopLoss !== null && $takeProfit !== null
            ? sprintf('止损 %.2f / 止盈 %.2f。', $stopLoss, $takeProfit)
            : '建议补齐止损止盈。';

        $base = match ($zone) {
            'support_zone' => sprintf('当前 %.2f 靠近支撑 %.2f，可分批观察低吸，不追高。', $current, $support),
            'resistance_zone' => sprintf('当前 %.2f 接近压力 %.2f，优先防守，避免追涨。', $current, $resistance),
            'middle_zone' => sprintf('当前 %.2f 位于区间中部（%.2f ~ %.2f），等待方向确认。', $current, $support, $resistance),
            default => '暂无有效行情数据，先保持观察，等待下一次采样。',
        };

        $scenarioHint = $scenario === 'position'
            ? '持仓建议：严格执行仓位纪律。'
            : '选股建议：先小仓试错，再逐步放大仓位。';

        $openclawHint = '';
        if ($openclawNote !== null && trim($openclawNote) !== '') {
            $openclawHint = ' OpenClaw 最近建议：' . mb_substr(trim($openclawNote), 0, 46) . '...';
        }

        return $base . ' ' . $riskText . ' ' . $scenarioHint . $openclawHint;
    }

    private function round4(float $v): float
    {
        return round($v, 4);
    }

    private function hasWatchlistInsightsLatestTable(): bool
    {
        if ($this->hasInsightsLatestTable !== null) {
            return $this->hasInsightsLatestTable;
        }

        $stmt = Database::connection()->query(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = 'watchlist_insights_latest'"
        );
        $this->hasInsightsLatestTable = ((int) ($stmt->fetchColumn() ?: 0)) > 0;
        return $this->hasInsightsLatestTable;
    }
}
