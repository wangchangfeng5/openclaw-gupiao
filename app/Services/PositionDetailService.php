<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class PositionDetailService
{
    private SymbolInsightService $insight;
    private WatchlistRankingService $ranking;
    private ?bool $hasInsightsLatestTable = null;

    public function __construct(?SymbolInsightService $insight = null, ?WatchlistRankingService $ranking = null)
    {
        $this->insight = $insight ?? new SymbolInsightService();
        $this->ranking = $ranking ?? new WatchlistRankingService();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(int $userId, int $positionId, int $quoteLimit = 220): ?array
    {
        $row = $this->fetchPositionDetailRow($positionId, $userId);
        if ($row === null) {
            return null;
        }

        $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
        $market = (string) ($row['market'] ?? 'A_STOCK_MAIN');
        $currentHint = $this->toFloat($row['current_price'] ?? null) ?? $this->toFloat($row['latest_price'] ?? null);
        $stopLossHint = $this->toFloat($row['stop_loss_price'] ?? null);
        $takeProfitHint = $this->toFloat($row['take_profit_price'] ?? null);

        $insight = $this->insight->analyze(
            $symbol,
            $market,
            $currentHint,
            $stopLossHint,
            $takeProfitHint,
            'position',
            $userId
        );

        $overview = $this->composePositionOverview($row, $insight);

        return [
            'overview' => $overview,
            'quote_series' => $this->loadQuoteSeries($symbol, $market, max(30, min(600, $quoteLimit))),
            'sector_context' => $this->loadSectorContext($overview, $userId),
            'related_positions' => $this->loadRelatedPositions(
                $userId,
                (int) ($overview['id'] ?? 0),
                (string) ($overview['sector_name'] ?? ''),
                $market,
                $symbol,
                10
            ),
            'related_news' => $this->loadRelatedNews(
                $symbol,
                (string) ($overview['name'] ?? ''),
                (string) ($overview['sector_name'] ?? ''),
                16,
                72
            ),
            'recent_suggestions' => $this->loadRecentSuggestions($userId, $symbol, 8),
            'recent_trades' => $this->loadRecentTrades((int) ($overview['id'] ?? 0), $userId, 20),
            'generated_at' => now_sql(),
        ];
    }

    private function fetchPositionDetailRow(int $id, int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT p.*,
                    q.name AS quote_name,
                    q.sector_name,
                    q.trend_direction,
                    q.price AS latest_price,
                    q.change_pct,
                    q.volume AS latest_volume,
                    q.quote_time,
                    lt.trade_type AS last_trade_type,
                    lt.traded_at AS last_traded_at,
                    COALESCE(tp.realized_total, 0) AS realized_pnl_total,
                    COALESCE(tp.trade_count, 0) AS trade_count
             FROM positions p
             LEFT JOIN (
                SELECT t.position_id,
                       SUM(COALESCE(t.realized_pnl, 0)) AS realized_total,
                       COUNT(*) AS trade_count
                FROM position_trades t
                WHERE t.user_id = :user_id_tp
                GROUP BY t.position_id
             ) tp ON tp.position_id = p.id
             LEFT JOIN (
                SELECT x.position_id, x.trade_type, x.traded_at
                FROM position_trades x
                INNER JOIN (
                    SELECT position_id, MAX(id) AS max_id
                    FROM position_trades
                    WHERE user_id = :user_id_latest
                    GROUP BY position_id
                ) latest ON latest.max_id = x.id
                WHERE x.user_id = :user_id_lt
             ) lt ON lt.position_id = p.id
             LEFT JOIN market_quotes_latest q ON q.symbol = p.symbol AND q.market = p.market
             WHERE p.id = :id AND p.user_id = :user_id
             LIMIT 1"
        );
        $stmt->execute([
            'user_id_tp' => $userId,
            'user_id_latest' => $userId,
            'user_id_lt' => $userId,
            'id' => $id,
            'user_id' => $userId,
        ]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $insight
     * @return array<string, mixed>
     */
    private function composePositionOverview(array $row, array $insight): array
    {
        $quantity = $this->toFloat($row['quantity'] ?? null) ?? 0.0;
        $costPrice = $this->toFloat($row['cost_price'] ?? null) ?? 0.0;
        $latestPrice = $this->toFloat($row['latest_price'] ?? null);
        $rawCurrentPrice = $this->toFloat($row['current_price'] ?? null);
        $currentPrice = $this->toFloat($insight['current_price'] ?? null) ?? $rawCurrentPrice ?? $latestPrice ?? 0.0;

        $costValue = $quantity * $costPrice;
        $marketValue = $quantity * $currentPrice;
        $unrealizedPnl = $marketValue - $costValue;
        $unrealizedPct = $costValue > 0 ? ($unrealizedPnl / $costValue) * 100 : 0.0;

        $support = $this->toFloat($insight['support_price'] ?? null);
        $resistance = $this->toFloat($insight['resistance_price'] ?? null);
        $stopLoss = $this->toFloat($insight['stop_loss_price'] ?? null);
        $takeProfit = $this->toFloat($insight['take_profit_price'] ?? null);

        $distanceToSupport = null;
        $distanceToResistance = null;
        $distanceToStop = null;
        $distanceToTake = null;

        if ($currentPrice > 0 && $support !== null && $support > 0) {
            $distanceToSupport = (($currentPrice - $support) / $support) * 100;
        }
        if ($currentPrice > 0 && $resistance !== null && $resistance > 0) {
            $distanceToResistance = (($resistance - $currentPrice) / $currentPrice) * 100;
        }
        if ($currentPrice > 0 && $stopLoss !== null && $stopLoss > 0) {
            $distanceToStop = (($currentPrice - $stopLoss) / $currentPrice) * 100;
        }
        if ($currentPrice > 0 && $takeProfit !== null && $takeProfit > 0) {
            $distanceToTake = (($takeProfit - $currentPrice) / $currentPrice) * 100;
        }

        $changePct = $this->toFloat($row['change_pct'] ?? null);
        $trend = trim((string) ($row['trend_direction'] ?? ''));
        if ($trend === '') {
            $trend = $this->resolveTrend($changePct);
        }

        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($row['quote_name'] ?? ''));
        }

        $overview = array_merge($row, [
            'name' => $name,
            'symbol' => strtoupper(trim((string) ($row['symbol'] ?? ''))),
            'market' => (string) ($row['market'] ?? 'A_STOCK_MAIN'),
            'current_price' => round($currentPrice, 4),
            'latest_price' => $latestPrice !== null ? round($latestPrice, 4) : null,
            'change_pct' => $changePct !== null ? round($changePct, 4) : null,
            'cost_value' => round($costValue, 4),
            'market_value' => round($marketValue, 4),
            'pnl' => round($unrealizedPnl, 4),
            'pnl_pct' => round($unrealizedPct, 4),
            'trend_direction' => $trend,
            'sector_name' => trim((string) ($row['sector_name'] ?? '')),
            'insight' => $insight,
            'support_price' => $support !== null ? round($support, 4) : null,
            'resistance_price' => $resistance !== null ? round($resistance, 4) : null,
            'stop_loss_price' => $stopLoss !== null ? round($stopLoss, 4) : null,
            'take_profit_price' => $takeProfit !== null ? round($takeProfit, 4) : null,
            'position_zone' => (string) ($insight['position_zone'] ?? 'unknown'),
            'distance_to_support_pct' => $distanceToSupport !== null ? round($distanceToSupport, 4) : null,
            'distance_to_resistance_pct' => $distanceToResistance !== null ? round($distanceToResistance, 4) : null,
            'distance_to_stop_pct' => $distanceToStop !== null ? round($distanceToStop, 4) : null,
            'distance_to_take_pct' => $distanceToTake !== null ? round($distanceToTake, 4) : null,
            'risk_flags' => $this->buildRiskFlags($distanceToStop, $distanceToTake, $unrealizedPct),
        ]);

        $overview['action_advice'] = $this->resolvePositionActionAdvice($overview);
        return $overview;
    }

    /**
     * @param array<string, mixed> $overview
     * @return array<int, string>
     */
    private function buildRiskFlags(?float $distanceToStop, ?float $distanceToTake, float $unrealizedPct): array
    {
        $flags = [];

        if ($distanceToStop !== null) {
            if ($distanceToStop <= 1.0) {
                $flags[] = 'stoploss_critical';
            } elseif ($distanceToStop <= 2.5) {
                $flags[] = 'stoploss_near';
            }
        }

        if ($distanceToTake !== null) {
            if ($distanceToTake <= 1.0) {
                $flags[] = 'takeprofit_near';
            } elseif ($distanceToTake <= 2.5) {
                $flags[] = 'takeprofit_watch';
            }
        }

        if ($unrealizedPct <= -5.0) {
            $flags[] = 'deep_drawdown';
        } elseif ($unrealizedPct >= 10.0) {
            $flags[] = 'profit_expanded';
        }

        return $flags;
    }

    /**
     * @param array<string, mixed> $overview
     */
    private function resolvePositionActionAdvice(array $overview): string
    {
        $current = $this->toFloat($overview['current_price'] ?? null) ?? 0.0;
        $stopLoss = $this->toFloat($overview['stop_loss_price'] ?? null);
        $takeProfit = $this->toFloat($overview['take_profit_price'] ?? null);
        $support = $this->toFloat($overview['support_price'] ?? null);
        $resistance = $this->toFloat($overview['resistance_price'] ?? null);
        $zone = strtolower(trim((string) ($overview['position_zone'] ?? 'unknown')));
        $trend = strtolower(trim((string) ($overview['trend_direction'] ?? '')));
        $changePct = $this->toFloat($overview['change_pct'] ?? null) ?? 0.0;

        if ($current > 0 && $stopLoss !== null && $stopLoss > 0 && $current <= $stopLoss * 1.002) {
            return '接近或触及止损位，优先执行风控纪律，建议减仓或清仓。';
        }

        if ($current > 0 && $takeProfit !== null && $takeProfit > 0 && $current >= $takeProfit * 0.998) {
            return '价格已靠近止盈位，建议分批锁定利润并抬升保护止损。';
        }

        if ($zone === 'support_zone' && in_array($trend, ['strong_up', 'up_bias'], true)) {
            return '位于支撑区且趋势偏强，可考虑小幅试探加仓，跌破支撑下方 1%-2% 止损。';
        }

        if ($zone === 'resistance_zone' && in_array($trend, ['strong_down', 'down_bias'], true)) {
            return '位于压力区且走势偏弱，建议先减仓防守，等待放量突破后再评估回补。';
        }

        if ($changePct <= -3.0) {
            return '日内回撤较大，建议先控制仓位，观察是否出现缩量止跌再决策。';
        }

        if ($changePct >= 3.0 && $zone !== 'resistance_zone') {
            return '短线动能较强，可继续跟踪量能持续性，优先移动止损保护浮盈。';
        }

        if ($support !== null && $resistance !== null && $support > 0 && $resistance > $support && $current > 0) {
            return sprintf(
                '当前在区间 %.2f ~ %.2f 内运行，建议按计划执行：靠近支撑观察承接，接近压力逐步兑现。',
                $support,
                $resistance
            );
        }

        $fallback = trim((string) ($overview['insight']['action_advice'] ?? ''));
        return $fallback !== '' ? $fallback : '等待更清晰的趋势与量能信号后再执行。';
    }

    private function resolveTrend(?float $changePct): string
    {
        if ($changePct === null) {
            return '';
        }
        if ($changePct >= 2.0) {
            return 'strong_up';
        }
        if ($changePct >= 0.5) {
            return 'up_bias';
        }
        if ($changePct <= -2.0) {
            return 'strong_down';
        }
        if ($changePct <= -0.5) {
            return 'down_bias';
        }
        return 'range';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadQuoteSeries(string $symbol, string $market, int $limit): array
    {
        if ($symbol === '') {
            return [];
        }

        $stmt = Database::connection()->prepare(
            'SELECT symbol, market, name, sector_name, trend_direction, price, change_pct, volume, turnover, quote_time, source
             FROM market_quotes
             WHERE symbol = :symbol AND market = :market
             ORDER BY quote_time DESC, id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':symbol', $symbol);
        $stmt->bindValue(':market', $market);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        return array_reverse($rows);
    }

    /**
     * @param array<string, mixed> $overview
     * @return array<string, mixed>
     */
    private function loadSectorContext(array $overview, int $userId): array
    {
        $sectorName = trim((string) ($overview['sector_name'] ?? ''));
        $symbol = strtoupper(trim((string) ($overview['symbol'] ?? '')));
        $market = (string) ($overview['market'] ?? 'A_STOCK_MAIN');

        $sectorLatest = null;
        $leaderSymbols = [];

        if ($sectorName !== '') {
            $keyword = mb_substr($sectorName, 0, max(2, min(6, mb_strlen($sectorName))));

            $stmt = Database::connection()->prepare(
                'SELECT sector_name, strength_score, change_pct, leading_symbol, active_count, sample_time, source
                 FROM sector_strength
                 WHERE sector_name = :sector_name OR sector_name LIKE :keyword
                 ORDER BY sample_time DESC, strength_score DESC, id DESC
                 LIMIT 30'
            );
            $stmt->execute([
                'sector_name' => $sectorName,
                'keyword' => '%' . $keyword . '%',
            ]);
            $rows = $stmt->fetchAll() ?: [];

            foreach ($rows as $row) {
                if ($sectorLatest === null) {
                    $sectorLatest = $row;
                }

                $lead = strtoupper(trim((string) ($row['leading_symbol'] ?? '')));
                if ($lead !== '' && preg_match('/^\d{6}$/', $lead) === 1) {
                    $leaderSymbols[$lead] = true;
                }
            }
        }

        $leaderList = [];
        $leaderMap = $this->loadLatestQuoteMap(array_keys($leaderSymbols), $market);
        foreach (array_keys($leaderSymbols) as $leadSymbol) {
            $quote = $leaderMap[$leadSymbol] ?? null;
            $leaderList[] = [
                'symbol' => $leadSymbol,
                'name' => (string) ($quote['name'] ?? ''),
                'price' => $quote['price'] ?? null,
                'change_pct' => $quote['change_pct'] ?? null,
                'trend_direction' => (string) ($quote['trend_direction'] ?? ''),
                'quote_time' => (string) ($quote['quote_time'] ?? ''),
            ];
        }

        return [
            'sector' => [
                'name' => $sectorName,
                'strength_score' => $sectorLatest['strength_score'] ?? null,
                'change_pct' => $sectorLatest['change_pct'] ?? null,
                'leading_symbol' => $sectorLatest['leading_symbol'] ?? null,
                'active_count' => $sectorLatest['active_count'] ?? null,
                'sample_time' => $sectorLatest['sample_time'] ?? null,
                'source' => $sectorLatest['source'] ?? null,
            ],
            'leaders' => $leaderList,
            'recommended' => $this->loadSectorWatchlistPicks($userId, $sectorName, $symbol, 8),
            'momentum' => $this->loadSectorMomentumStocks($sectorName, $symbol, $market, 10),
        ];
    }

    /**
     * @param array<int, string> $symbols
     * @return array<string, array<string, mixed>>
     */
    private function loadLatestQuoteMap(array $symbols, string $market): array
    {
        $symbols = array_values(array_filter(array_map(
            static fn(string $s): string => strtoupper(trim($s)),
            $symbols
        ), static fn(string $s): bool => $s !== '' && preg_match('/^\d{6}$/', $s) === 1));

        if ($symbols === []) {
            return [];
        }

        $placeholders = [];
        $bindings = ['market' => $market];
        foreach ($symbols as $idx => $sym) {
            $key = 's' . $idx;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $sym;
        }

        $sql = sprintf(
            'SELECT symbol, name, price, change_pct, volume, quote_time, trend_direction
             FROM market_quotes_latest
             WHERE market = :market AND symbol IN (%s)',
            implode(', ', $placeholders)
        );

        $stmt = Database::connection()->prepare($sql);
        foreach ($bindings as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $map = [];
        foreach ($rows as $row) {
            $sym = strtoupper(trim((string) ($row['symbol'] ?? '')));
            if ($sym !== '') {
                $map[$sym] = $row;
            }
        }

        return $map;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadSectorWatchlistPicks(int $userId, string $sectorName, string $excludeSymbol, int $limit): array
    {
        if ($this->hasWatchlistInsightsLatestTable()) {
            $sql = 'SELECT w.*,
                           wi.current_price,
                           wi.support_price,
                           wi.resistance_price,
                           wi.stop_loss_price,
                           wi.take_profit_price,
                           wi.position_zone,
                           wi.action_advice,
                           wi.confidence,
                           wi.openclaw_note,
                           wi.analyzed_at,
                           wi.next_review_at,
                           q.price AS latest_price,
                           q.change_pct,
                           q.volume AS latest_volume,
                           q.quote_time
                    FROM watchlist w
                    LEFT JOIN watchlist_insights_latest wi ON wi.watchlist_id = w.id AND wi.user_id = :user_id_insight
                    LEFT JOIN market_quotes_latest q ON q.symbol = w.symbol AND q.market = w.market
                    WHERE w.user_id = :user_id AND w.status = \'active\'';

            $bindings = [
                'user_id_insight' => $userId,
                'user_id' => $userId,
            ];
        } else {
            $sql = 'SELECT w.*,
                           wi.current_price,
                           wi.support_price,
                           wi.resistance_price,
                           wi.stop_loss_price,
                           wi.take_profit_price,
                           wi.position_zone,
                           wi.action_advice,
                           wi.confidence,
                           wi.openclaw_note,
                           wi.analyzed_at,
                           wi.next_review_at,
                           q.price AS latest_price,
                           q.change_pct,
                           q.volume AS latest_volume,
                           q.quote_time
                    FROM watchlist w
                    LEFT JOIN (
                       SELECT x.*
                       FROM watchlist_insights x
                       INNER JOIN (
                           SELECT watchlist_id, MAX(id) AS max_id
                           FROM watchlist_insights
                           WHERE user_id = :user_id_latest
                           GROUP BY watchlist_id
                       ) latest ON latest.max_id = x.id
                       WHERE x.user_id = :user_id_insight
                    ) wi ON wi.watchlist_id = w.id
                    LEFT JOIN market_quotes_latest q ON q.symbol = w.symbol AND q.market = w.market
                    WHERE w.user_id = :user_id AND w.status = \'active\'';

            $bindings = [
                'user_id_latest' => $userId,
                'user_id_insight' => $userId,
                'user_id' => $userId,
            ];
        }

        if ($sectorName !== '') {
            $keyword = mb_substr($sectorName, 0, max(2, min(6, mb_strlen($sectorName))));
            $sql .= ' AND (w.sector_name = :sector_name OR w.sector_name LIKE :sector_keyword)';
            $bindings['sector_name'] = $sectorName;
            $bindings['sector_keyword'] = '%' . $keyword . '%';
        }

        $sql .= ' ORDER BY w.priority ASC, w.updated_at DESC LIMIT 120';

        $stmt = Database::connection()->prepare($sql);
        foreach ($bindings as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $rows = $this->ranking->enrichRows($rows);

        $rows = array_values(array_filter(
            $rows,
            static function (array $row) use ($excludeSymbol): bool {
                $sym = strtoupper(trim((string) ($row['symbol'] ?? '')));
                return $sym !== '' && $sym !== $excludeSymbol;
            }
        ));

        usort(
            $rows,
            static function (array $a, array $b): int {
                $sa = (float) ($a['ranking_score'] ?? 0);
                $sb = (float) ($b['ranking_score'] ?? 0);
                if ($sa !== $sb) {
                    return $sb <=> $sa;
                }
                return ((int) ($a['priority'] ?? 999)) <=> ((int) ($b['priority'] ?? 999));
            }
        );

        $rows = array_slice($rows, 0, $limit);

        return array_map(
            static fn(array $row): array => [
                'id' => (int) ($row['id'] ?? 0),
                'symbol' => (string) ($row['symbol'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'sector_name' => (string) ($row['sector_name'] ?? ''),
                'ranking_score' => $row['ranking_score'] ?? null,
                'ranking_grade' => (string) ($row['ranking_grade'] ?? ''),
                'ranking_tag' => (string) ($row['ranking_tag'] ?? ''),
                'trend_direction' => (string) ($row['trend_direction'] ?? ''),
                'position_zone' => (string) ($row['position_zone'] ?? ''),
                'current_price' => $row['current_price'] ?? $row['latest_price'] ?? null,
                'change_pct' => $row['change_pct'] ?? null,
                'volume_ratio' => $row['volume_ratio'] ?? null,
                'ranking_action' => (string) ($row['ranking_action'] ?? ''),
            ],
            $rows
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadSectorMomentumStocks(string $sectorName, string $excludeSymbol, string $market, int $limit): array
    {
        if ($sectorName === '') {
            return [];
        }

        $keyword = mb_substr($sectorName, 0, max(2, min(6, mb_strlen($sectorName))));
        $stmt = Database::connection()->prepare(
            'SELECT symbol, market, name, sector_name, trend_direction, price, change_pct, volume, quote_time
             FROM market_quotes_latest
             WHERE market = :market
               AND (sector_name = :sector_name OR sector_name LIKE :keyword)
             ORDER BY change_pct DESC, volume DESC, symbol DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':market', $market);
        $stmt->bindValue(':sector_name', $sectorName);
        $stmt->bindValue(':keyword', '%' . $keyword . '%');
        $stmt->bindValue(':limit', max(4, min(30, $limit + 6)), \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $rows = array_values(array_filter(
            $rows,
            static function (array $row) use ($excludeSymbol): bool {
                $sym = strtoupper(trim((string) ($row['symbol'] ?? '')));
                return $sym !== '' && $sym !== $excludeSymbol;
            }
        ));

        $rows = array_slice($rows, 0, $limit);
        return array_map(
            static fn(array $row): array => [
                'symbol' => (string) ($row['symbol'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'sector_name' => (string) ($row['sector_name'] ?? ''),
                'trend_direction' => (string) ($row['trend_direction'] ?? ''),
                'price' => $row['price'] ?? null,
                'change_pct' => $row['change_pct'] ?? null,
                'volume' => $row['volume'] ?? null,
                'quote_time' => $row['quote_time'] ?? null,
            ],
            $rows
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRelatedPositions(
        int $userId,
        int $positionId,
        string $sectorName,
        string $market,
        string $excludeSymbol,
        int $limit
    ): array {
        $sql = "SELECT p.id,
                       p.symbol,
                       p.market,
                       p.name,
                       p.status,
                       p.quantity,
                       p.cost_price,
                       p.current_price,
                       q.name AS quote_name,
                       q.sector_name,
                       q.trend_direction,
                       q.price AS latest_price,
                       q.change_pct,
                       q.quote_time
                FROM positions p
                LEFT JOIN market_quotes_latest q ON q.symbol = p.symbol AND q.market = p.market
                WHERE p.user_id = :user_id
                  AND p.id <> :position_id
                  AND p.symbol <> :exclude_symbol
                  AND p.market = :market";

        $bindings = [
            'user_id' => $userId,
            'position_id' => $positionId,
            'exclude_symbol' => $excludeSymbol,
            'market' => $market,
        ];

        if ($sectorName !== '') {
            $keyword = mb_substr($sectorName, 0, max(2, min(6, mb_strlen($sectorName))));
            $sql .= ' AND (q.sector_name = :sector_name OR q.sector_name LIKE :sector_keyword)';
            $bindings['sector_name'] = $sectorName;
            $bindings['sector_keyword'] = '%' . $keyword . '%';
        }

        $sql .= ' ORDER BY p.updated_at DESC, p.id DESC LIMIT :limit';

        $stmt = Database::connection()->prepare($sql);
        foreach ($bindings as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', max(1, min(30, $limit)), \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        return array_map(
            function (array $row): array {
                $qty = $this->toFloat($row['quantity'] ?? null) ?? 0.0;
                $cost = $this->toFloat($row['cost_price'] ?? null) ?? 0.0;
                $current = $this->toFloat($row['latest_price'] ?? null) ?? $this->toFloat($row['current_price'] ?? null) ?? 0.0;
                $pnlPct = 0.0;
                if ($cost > 0 && $qty > 0) {
                    $pnlPct = (($current - $cost) / $cost) * 100;
                }

                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') {
                    $name = trim((string) ($row['quote_name'] ?? ''));
                }

                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'symbol' => (string) ($row['symbol'] ?? ''),
                    'name' => $name,
                    'status' => (string) ($row['status'] ?? ''),
                    'quantity' => round($qty, 4),
                    'cost_price' => round($cost, 4),
                    'current_price' => round($current, 4),
                    'change_pct' => $row['change_pct'] ?? null,
                    'pnl_pct' => round($pnlPct, 4),
                    'trend_direction' => (string) ($row['trend_direction'] ?? ''),
                    'sector_name' => (string) ($row['sector_name'] ?? ''),
                    'quote_time' => (string) ($row['quote_time'] ?? ''),
                ];
            },
            $rows
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRelatedNews(string $symbol, string $name, string $sectorName, int $limit, int $hours): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, title, summary, url, source, category, sentiment, published_at
             FROM news_feed
             WHERE published_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
             ORDER BY published_at DESC, id DESC
             LIMIT 300'
        );
        $stmt->bindValue(':hours', max(1, min(168, $hours)), \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];

        $hits = [];
        foreach ($rows as $row) {
            $title = (string) ($row['title'] ?? '');
            $summary = (string) ($row['summary'] ?? '');
            $score = 0;

            if ($symbol !== '' && $this->containsText($title . ' ' . $summary, $symbol)) {
                $score += 4;
            }
            if ($name !== '' && $this->containsText($title . ' ' . $summary, $name)) {
                $score += 3;
            }
            if ($sectorName !== '' && $this->containsText($title . ' ' . $summary, $sectorName)) {
                $score += 2;
            }

            if ($score > 0) {
                $row['relevance_score'] = $score;
                $hits[] = $row;
            }
        }

        usort(
            $hits,
            static function (array $a, array $b): int {
                $sa = (int) ($a['relevance_score'] ?? 0);
                $sb = (int) ($b['relevance_score'] ?? 0);
                if ($sa !== $sb) {
                    return $sb <=> $sa;
                }
                return strcmp((string) ($b['published_at'] ?? ''), (string) ($a['published_at'] ?? ''));
            }
        );

        return array_slice($hits, 0, $limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRecentSuggestions(int $userId, string $symbol, int $limit): array
    {
        if ($symbol === '') {
            return [];
        }

        $limit = max(1, min(20, $limit));
        $scanLimit = max(40, min(480, $limit * 12));
        $stmt = Database::connection()->prepare(
            'SELECT id, content, confidence, tags_json, suggested_at, status, symbols_json
             FROM openclaw_suggestions
             WHERE user_id = :user_id
             ORDER BY suggested_at DESC, id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $scanLimit, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $hits = [];
        foreach ($rows as $row) {
            if (!$this->suggestionContainsSymbol($row['symbols_json'] ?? null, $symbol)) {
                continue;
            }
            unset($row['symbols_json']);
            $hits[] = $row;
            if (count($hits) >= $limit) {
                break;
            }
        }

        return $hits;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRecentTrades(int $positionId, int $userId, int $limit): array
    {
        if ($positionId <= 0) {
            return [];
        }

        $stmt = Database::connection()->prepare(
            'SELECT id, trade_type, quantity, price, fee, amount, realized_pnl, traded_at, note
             FROM position_trades
             WHERE user_id = :user_id AND position_id = :position_id
             ORDER BY traded_at DESC, id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':position_id', $positionId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, min(100, $limit)), \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    private function containsText(string $haystack, string $needle): bool
    {
        $needle = trim($needle);
        if ($haystack === '' || $needle === '') {
            return false;
        }

        if (function_exists('mb_stripos')) {
            return mb_stripos($haystack, $needle) !== false;
        }

        return stripos($haystack, $needle) !== false;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }

    private function suggestionContainsSymbol(mixed $symbolsJson, string $symbol): bool
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return false;
        }

        if (is_string($symbolsJson)) {
            $trimmed = trim($symbolsJson);
            if ($trimmed !== '') {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $symbolsJson = $decoded;
                } else {
                    return strpos(strtoupper($trimmed), '"' . $symbol . '"') !== false;
                }
            }
        }

        if (!is_array($symbolsJson)) {
            return false;
        }

        foreach ($symbolsJson as $item) {
            if (!is_string($item)) {
                continue;
            }
            if (strtoupper(trim($item)) === $symbol) {
                return true;
            }
        }

        return false;
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
