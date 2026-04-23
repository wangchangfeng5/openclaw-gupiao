<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class MarketOpportunityService
{
    private WatchlistRankingService $rankingService;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $recentNews = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $newsCache = [];

    public function __construct()
    {
        $this->rankingService = new WatchlistRankingService();
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $userId, int $limit = 30): array
    {
        $limit = max(1, min(30, $limit));

        $positions = $this->loadPositions($userId);
        $watchlist = $this->rankingService->enrichRows($this->loadWatchlistRows($userId));
        $quotes = $this->loadLatestMainBoardQuotes();
        $quoteMap = $this->indexBySymbol($quotes);
        $closeMap = $this->loadCloseRankingMap();

        $symbols = $this->seedSymbols($positions, $watchlist, $quotes, $closeMap);
        $seriesMap = $this->loadRecentQuoteSeries($symbols);
        $this->recentNews = $this->loadRecentNews();
        $this->newsCache = [];

        $positionRows = $this->buildPositionRows($positions, $quoteMap, $seriesMap, $closeMap);
        $watchlistRows = $this->buildWatchlistRows($watchlist, $quoteMap, $seriesMap, $closeMap);
        $opportunities = $this->buildOpportunityRows($symbols, $quoteMap, $seriesMap, $closeMap, $limit);

        return [
            'summary' => [
                'position_count' => count($positions),
                'watchlist_count' => count($watchlist),
                'opportunity_count' => count($opportunities),
                'strong_support_count' => count(array_filter($opportunities, static fn(array $r): bool => (bool) ($r['near_support'] ?? false))),
                'rise_pullback_count' => count(array_filter($opportunities, static fn(array $r): bool => (bool) ($r['rise_pullback'] ?? false))),
                'volume_surge_count' => count(array_filter($opportunities, static fn(array $r): bool => (bool) ($r['volume_surge'] ?? false))),
                'market_open_now' => $this->isTradingSessionNow(),
            ],
            'positions' => $positionRows,
            'watchlist' => $watchlistRows,
            'opportunities' => $opportunities,
            'generated_at' => now_sql(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadPositions(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $stmt = Database::connection()->prepare(
            "SELECT id, symbol, market, name, quantity, cost_price, current_price, status, updated_at
             FROM positions
             WHERE user_id = :user_id AND status IN ('holding', 'watching')
             ORDER BY updated_at DESC, id DESC
             LIMIT 120"
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadWatchlistRows(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $stmt = Database::connection()->prepare(
            "SELECT w.id, w.symbol, w.market, w.name, w.priority, w.status, w.sector_name,
                    wi.support_price, wi.resistance_price, wi.position_zone, wi.action_advice,
                    q.price AS latest_price, q.change_pct, q.volume AS latest_volume, q.turnover AS latest_turnover, q.quote_time
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
             LEFT JOIN (
                SELECT mq1.symbol, mq1.market, mq1.price, mq1.change_pct, mq1.volume, mq1.turnover, mq1.quote_time
                FROM market_quotes mq1
                INNER JOIN (
                    SELECT symbol, market, MAX(id) AS max_id
                    FROM market_quotes
                    GROUP BY symbol, market
                ) latest_q ON latest_q.max_id = mq1.id
             ) q ON q.symbol = w.symbol AND q.market = w.market
             WHERE w.user_id = :user_id AND w.status = 'active'
             ORDER BY w.priority ASC, w.updated_at DESC
             LIMIT 200"
        );
        $stmt->execute([
            'user_id_latest' => $userId,
            'user_id_insight' => $userId,
            'user_id' => $userId,
        ]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadLatestMainBoardQuotes(): array
    {
        $stmt = Database::connection()->query(
            "SELECT mq.symbol, mq.market, mq.name, mq.sector_name, mq.trend_direction, mq.price, mq.change_pct, mq.volume, mq.turnover, mq.quote_time
             FROM market_quotes mq
             INNER JOIN (
                SELECT symbol, market, MAX(id) AS max_id
                FROM market_quotes
                WHERE market = 'A_STOCK_MAIN'
                GROUP BY symbol, market
             ) latest ON latest.max_id = mq.id
             WHERE mq.market = 'A_STOCK_MAIN'
               AND mq.symbol REGEXP '^(000|001|002|003|600|601|603|605)[0-9]{3}$'
             ORDER BY mq.turnover DESC, mq.volume DESC, mq.id DESC
             LIMIT 260"
        );
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexBySymbol(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
            if ($symbol !== '') {
                $out[$this->symbolKey($symbol)] = $row;
            }
        }
        return $out;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadCloseRankingMap(): array
    {
        $tradeDate = (string) (Database::connection()->query('SELECT MAX(trade_date) FROM market_close_rankings')->fetchColumn() ?: '');
        if ($tradeDate === '') {
            return [];
        }

        $stmt = Database::connection()->prepare(
            "SELECT rank_type, rank_no, symbol, change_1d_pct, change_3d_pct, change_5d_pct, net_main_inflow, net_main_inflow_pct, flow_direction
             FROM market_close_rankings
             WHERE trade_date = :trade_date AND rank_type IN ('strong', 'moneyflow')
             ORDER BY rank_no ASC"
        );
        $stmt->execute(['trade_date' => $tradeDate]);
        $rows = $stmt->fetchAll() ?: [];

        $map = [];
        foreach ($rows as $row) {
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
            if ($symbol === '') {
                continue;
            }
            $k = $this->symbolKey($symbol);
            if (!isset($map[$k])) {
                $map[$k] = [
                    'symbol' => $symbol,
                    'strong_rank' => 0,
                    'money_rank' => 0,
                    'change_1d_pct' => null,
                    'change_3d_pct' => null,
                    'change_5d_pct' => null,
                    'net_main_inflow' => null,
                    'net_main_inflow_pct' => null,
                    'flow_direction' => '',
                ];
            }
            if (($row['rank_type'] ?? '') === 'strong') {
                $map[$k]['strong_rank'] = (int) ($row['rank_no'] ?? 0);
            }
            if (($row['rank_type'] ?? '') === 'moneyflow') {
                $map[$k]['money_rank'] = (int) ($row['rank_no'] ?? 0);
            }
            foreach (['change_1d_pct', 'change_3d_pct', 'change_5d_pct', 'net_main_inflow', 'net_main_inflow_pct'] as $k) {
                if ($map[$this->symbolKey($symbol)][$k] === null && isset($row[$k]) && $row[$k] !== null) {
                    $map[$this->symbolKey($symbol)][$k] = (float) $row[$k];
                }
            }
            if ($map[$this->symbolKey($symbol)]['flow_direction'] === '' && trim((string) ($row['flow_direction'] ?? '')) !== '') {
                $map[$this->symbolKey($symbol)]['flow_direction'] = (string) $row['flow_direction'];
            }
        }
        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $positions
     * @param array<int, array<string, mixed>> $watchlist
     * @param array<int, array<string, mixed>> $quotes
     * @param array<string, array<string, mixed>> $closeMap
     * @return array<int, string>
     */
    private function seedSymbols(array $positions, array $watchlist, array $quotes, array $closeMap): array
    {
        $set = [];
        foreach ($positions as $row) {
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
            if (preg_match('/^\d{6}$/', $symbol) === 1) {
                $set[$this->symbolKey($symbol)] = $symbol;
            }
        }
        foreach ($watchlist as $row) {
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
            if (preg_match('/^\d{6}$/', $symbol) === 1) {
                $set[$this->symbolKey($symbol)] = $symbol;
            }
        }
        foreach (array_slice($quotes, 0, 220) as $row) {
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
            if (preg_match('/^\d{6}$/', $symbol) === 1) {
                $set[$this->symbolKey($symbol)] = $symbol;
            }
        }
        foreach ($closeMap as $meta) {
            $symbol = strtoupper(trim((string) ($meta['symbol'] ?? '')));
            if ($symbol === '') {
                continue;
            }
            $strongRank = (int) ($meta['strong_rank'] ?? 0);
            $moneyRank = (int) ($meta['money_rank'] ?? 0);
            if (($strongRank > 0 && $strongRank <= 120) || ($moneyRank > 0 && $moneyRank <= 120)) {
                $set[$this->symbolKey($symbol)] = $symbol;
            }
        }
        return array_values($set);
    }

    /**
     * @param array<int, string> $symbols
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function loadRecentQuoteSeries(array $symbols): array
    {
        $symbols = array_values(array_unique(array_filter(array_map(
            static fn(string $s): string => strtoupper(trim($s)),
            $symbols
        ), static fn(string $s): bool => preg_match('/^\d{6}$/', $s) === 1)));
        if ($symbols === []) {
            return [];
        }

        $placeholders = [];
        $bindings = [];
        foreach ($symbols as $i => $symbol) {
            $k = 's' . $i;
            $placeholders[] = ':' . $k;
            $bindings[$k] = $symbol;
        }
        $sql = sprintf(
            "SELECT symbol, price, volume, quote_time
             FROM market_quotes
             WHERE market = 'A_STOCK_MAIN'
               AND symbol IN (%s)
               AND quote_time >= DATE_SUB(NOW(), INTERVAL 8 DAY)
               AND price IS NOT NULL
               AND price > 0
             ORDER BY symbol ASC, quote_time ASC, id ASC",
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
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
            if ($symbol === '') {
                continue;
            }
            $k = $this->symbolKey($symbol);
            if (!isset($map[$k])) {
                $map[$k] = [];
            }
            $map[$k][] = [
                'price' => (float) ($row['price'] ?? 0),
                'volume' => $this->toFloat($row['volume'] ?? null) ?? 0.0,
                'quote_time' => (string) ($row['quote_time'] ?? ''),
            ];
        }
        foreach ($map as $symbolKey => $series) {
            if (count($series) > 180) {
                $map[$symbolKey] = array_slice($series, -180);
            }
        }
        return $map;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRecentNews(): array
    {
        $stmt = Database::connection()->query(
            "SELECT title, summary, source, category, sentiment, published_at
             FROM news_feed
             WHERE published_at >= DATE_SUB(NOW(), INTERVAL 72 HOUR)
             ORDER BY published_at DESC, id DESC
             LIMIT 540"
        );
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @param array<int, array<string, mixed>> $positions
     * @param array<string, array<string, mixed>> $quoteMap
     * @param array<string, array<int, array<string, mixed>>> $seriesMap
     * @param array<string, array<string, mixed>> $closeMap
     * @return array<int, array<string, mixed>>
     */
    private function buildPositionRows(array $positions, array $quoteMap, array $seriesMap, array $closeMap): array
    {
        $rows = [];
        foreach ($positions as $row) {
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
            if ($symbol === '') {
                continue;
            }

            $key = $this->symbolKey($symbol);
            $quote = $quoteMap[$key] ?? null;
            $metrics = $this->buildMetrics(
                $symbol,
                (string) ($quote['name'] ?? ($row['name'] ?? '')),
                (string) ($quote['sector_name'] ?? ''),
                $quote,
                $seriesMap[$key] ?? [],
                $closeMap[$key] ?? []
            );

            $cost = $this->toFloat($row['cost_price'] ?? null) ?? 0.0;
            $current = $this->toFloat($metrics['price'] ?? null) ?? ($this->toFloat($row['current_price'] ?? null) ?? 0.0);
            $pnlPct = $cost > 0 && $current > 0 ? (($current - $cost) / $cost * 100.0) : null;

            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'symbol' => $symbol,
                'name' => (string) ($quote['name'] ?? ($row['name'] ?? '')),
                'status' => (string) ($row['status'] ?? ''),
                'quantity' => $this->toFloat($row['quantity'] ?? null),
                'cost_price' => $cost,
                'current_price' => $current > 0 ? round($current, 4) : null,
                'pnl_pct' => $pnlPct !== null ? round($pnlPct, 4) : null,
                'score' => $metrics['total_score'],
                'signal_level' => $metrics['signal_level'],
                'distance_to_support_pct' => $metrics['distance_to_support_pct'],
                'volume_ratio' => $metrics['volume_ratio'],
                'action_advice' => $metrics['action_advice'],
                'dimension_scores' => $metrics['dimension_scores'],
                'quote_time' => $metrics['quote_time'],
            ];
        }

        usort($rows, static fn(array $a, array $b): int => (float) ($b['score'] ?? 0) <=> (float) ($a['score'] ?? 0));
        return array_slice($rows, 0, 30);
    }

    /**
     * @param array<int, array<string, mixed>> $watchlist
     * @param array<string, array<string, mixed>> $quoteMap
     * @param array<string, array<int, array<string, mixed>>> $seriesMap
     * @param array<string, array<string, mixed>> $closeMap
     * @return array<int, array<string, mixed>>
     */
    private function buildWatchlistRows(array $watchlist, array $quoteMap, array $seriesMap, array $closeMap): array
    {
        $rows = [];
        foreach ($watchlist as $row) {
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
            if ($symbol === '') {
                continue;
            }
            $key = $this->symbolKey($symbol);
            $quote = $quoteMap[$key] ?? null;
            $metrics = $this->buildMetrics(
                $symbol,
                (string) ($quote['name'] ?? ($row['name'] ?? '')),
                (string) ($quote['sector_name'] ?? ($row['sector_name'] ?? '')),
                $quote,
                $seriesMap[$key] ?? [],
                $closeMap[$key] ?? [],
                $this->toFloat($row['support_price'] ?? null),
                $this->toFloat($row['resistance_price'] ?? null)
            );

            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'symbol' => $symbol,
                'name' => (string) ($quote['name'] ?? ($row['name'] ?? '')),
                'priority' => (int) ($row['priority'] ?? 0),
                'ranking_score' => $this->toFloat($row['ranking_score'] ?? null),
                'ranking_grade' => (string) ($row['ranking_grade'] ?? ''),
                'score' => $metrics['total_score'],
                'near_support' => $metrics['near_support'],
                'rise_pullback' => $metrics['rise_pullback'],
                'volume_surge' => $metrics['volume_surge'],
                'action_advice' => $metrics['action_advice'],
                'dimension_scores' => $metrics['dimension_scores'],
                'quote_time' => $metrics['quote_time'],
            ];
        }

        usort(
            $rows,
            static function (array $a, array $b): int {
                $rank = (float) ($b['ranking_score'] ?? 0) <=> (float) ($a['ranking_score'] ?? 0);
                return $rank !== 0 ? $rank : ((float) ($b['score'] ?? 0) <=> (float) ($a['score'] ?? 0));
            }
        );
        return array_slice($rows, 0, 36);
    }

    /**
     * @param array<int, string> $symbols
     * @param array<string, array<string, mixed>> $quoteMap
     * @param array<string, array<int, array<string, mixed>>> $seriesMap
     * @param array<string, array<string, mixed>> $closeMap
     * @return array<int, array<string, mixed>>
     */
    private function buildOpportunityRows(array $symbols, array $quoteMap, array $seriesMap, array $closeMap, int $limit): array
    {
        $allRows = [];
        foreach ($symbols as $symbol) {
            $symbol = strtoupper(trim((string) $symbol));
            $key = $this->symbolKey($symbol);
            $quote = $quoteMap[$key] ?? null;
            if (!$quote) {
                continue;
            }

            $metrics = $this->buildMetrics(
                $symbol,
                (string) ($quote['name'] ?? ''),
                (string) ($quote['sector_name'] ?? ''),
                $quote,
                $seriesMap[$key] ?? [],
                $closeMap[$key] ?? []
            );

            $allRows[] = [
                'symbol' => $symbol,
                'name' => (string) ($quote['name'] ?? ''),
                'sector_name' => (string) ($quote['sector_name'] ?? ''),
                'trend_direction' => (string) ($quote['trend_direction'] ?? ''),
                'price' => $metrics['price'],
                'change_pct' => $metrics['change_pct'],
                'volume' => $metrics['volume'],
                'turnover' => $metrics['turnover'],
                'quote_time' => $metrics['quote_time'],
                'near_support' => $metrics['near_support'],
                'rise_pullback' => $metrics['rise_pullback'],
                'volume_surge' => $metrics['volume_surge'],
                'signal_level' => $metrics['signal_level'],
                'support_price' => $metrics['support_price'],
                'resistance_price' => $metrics['resistance_price'],
                'distance_to_support_pct' => $metrics['distance_to_support_pct'],
                'pullback_pct' => $metrics['pullback_pct'],
                'rise_3d_pct' => $metrics['rise_3d_pct'],
                'rise_5d_pct' => $metrics['rise_5d_pct'],
                'volume_ratio' => $metrics['volume_ratio'],
                'close_rank' => $metrics['close_rank'],
                'money_rank' => $metrics['money_rank'],
                'net_main_inflow' => $metrics['net_main_inflow'],
                'net_main_inflow_pct' => $metrics['net_main_inflow_pct'],
                'flow_direction' => $metrics['flow_direction'],
                'news_hits_72h' => $metrics['news_hits_72h'],
                'news_latest_title' => $metrics['news_latest_title'],
                'news_latest_time' => $metrics['news_latest_time'],
                'policy_tag' => $metrics['policy_tag'],
                'reasons' => $metrics['reasons'],
                'technical_score' => $metrics['technical_score'],
                'funds_score' => $metrics['funds_score'],
                'news_score' => $metrics['news_score'],
                'policy_score' => $metrics['policy_score'],
                'dimension_scores' => $metrics['dimension_scores'],
                'total_score' => $metrics['total_score'],
                'action_advice' => $metrics['action_advice'],
                'sparkline_prices' => $metrics['sparkline_prices'],
            ];
        }

        usort(
            $allRows,
            static fn(array $a, array $b): int =>
                ((float) ($b['total_score'] ?? 0) <=> (float) ($a['total_score'] ?? 0))
                ?: ((float) ($b['turnover'] ?? 0) <=> (float) ($a['turnover'] ?? 0))
        );

        $picked = [];
        $seen = [];
        $targetMin = min(10, $limit);

        foreach ($allRows as $row) {
            $strict = (((bool) ($row['near_support'] ?? false)) || ((bool) ($row['rise_pullback'] ?? false)))
                && ((float) ($row['volume_ratio'] ?? 0) >= 1.2);
            if (!$strict) {
                continue;
            }
            $k = $this->symbolKey((string) ($row['symbol'] ?? ''));
            $seen[$k] = true;
            $picked[] = $row;
            if (count($picked) >= $limit) {
                return array_slice($picked, 0, $limit);
            }
        }

        if (count($picked) >= $targetMin) {
            return array_slice($picked, 0, $limit);
        }

        foreach ($allRows as $row) {
            $k = $this->symbolKey((string) ($row['symbol'] ?? ''));
            if (isset($seen[$k])) {
                continue;
            }

            $midRelaxed = ((float) ($row['volume_ratio'] ?? 0) >= 1.0)
                && ((float) ($row['total_score'] ?? 0) >= 50.0)
                && (
                    ((int) ($row['close_rank'] ?? 0) > 0 && (int) ($row['close_rank'] ?? 0) <= 120)
                    || ((int) ($row['money_rank'] ?? 0) > 0 && (int) ($row['money_rank'] ?? 0) <= 120)
                    || ((float) ($row['rise_3d_pct'] ?? 0) >= 1.8)
                    || ((float) ($row['change_pct'] ?? 0) >= 1.0)
                );
            if (!$midRelaxed) {
                continue;
            }

            $seen[$k] = true;
            $picked[] = $row;
            if (count($picked) >= $limit) {
                break;
            }
        }

        if (count($picked) >= $targetMin) {
            return array_slice($picked, 0, $limit);
        }

        foreach ($allRows as $row) {
            $k = $this->symbolKey((string) ($row['symbol'] ?? ''));
            if (isset($seen[$k])) {
                continue;
            }

            $relaxed = ((float) ($row['volume_ratio'] ?? 0) >= 0.9)
                && ((float) ($row['total_score'] ?? 0) >= 43.0)
                && (
                    ((int) ($row['close_rank'] ?? 0) > 0 && (int) ($row['close_rank'] ?? 0) <= 180)
                    || ((int) ($row['money_rank'] ?? 0) > 0 && (int) ($row['money_rank'] ?? 0) <= 180)
                    || ((float) ($row['rise_3d_pct'] ?? 0) >= 0.8)
                    || ((float) ($row['change_pct'] ?? 0) >= 0.3)
                );
            if (!$relaxed) {
                continue;
            }

            $seen[$k] = true;
            $picked[] = $row;
            if (count($picked) >= $limit) {
                break;
            }
        }

        if (count($picked) >= $targetMin) {
            return array_slice($picked, 0, $limit);
        }

        foreach ($allRows as $row) {
            $k = $this->symbolKey((string) ($row['symbol'] ?? ''));
            if (isset($seen[$k])) {
                continue;
            }
            $safetyFill = ((float) ($row['total_score'] ?? 0) >= 38.0)
                && ((float) ($row['turnover'] ?? 0) > 0);
            if (!$safetyFill) {
                continue;
            }
            $seen[$k] = true;
            $picked[] = $row;
            if (count($picked) >= $targetMin || count($picked) >= $limit) {
                break;
            }
        }

        return array_slice($picked, 0, $limit);
    }

    /**
     * @param array<string, mixed>|null $quote
     * @param array<int, array<string, mixed>> $series
     * @param array<string, mixed> $closeMeta
     * @return array<string, mixed>
     */
    private function buildMetrics(
        string $symbol,
        string $name,
        string $sectorName,
        ?array $quote,
        array $series,
        array $closeMeta,
        ?float $supportHint = null,
        ?float $resistanceHint = null
    ): array {
        $price = $this->toFloat($quote['price'] ?? null);
        $changePct = $this->toFloat($quote['change_pct'] ?? null);
        $volume = $this->toFloat($quote['volume'] ?? null);
        $turnover = $this->toFloat($quote['turnover'] ?? null);
        $quoteTime = (string) ($quote['quote_time'] ?? '');
        $trend = strtolower(trim((string) ($quote['trend_direction'] ?? '')));
        if ($trend === '') {
            $trend = $this->inferTrend($changePct);
        }

        $prices = [];
        $volumes = [];
        foreach ($series as $row) {
            $p = $this->toFloat($row['price'] ?? null);
            if ($p !== null && $p > 0) {
                $prices[] = $p;
            }
            $v = $this->toFloat($row['volume'] ?? null);
            if ($v !== null && $v > 0) {
                $volumes[] = $v;
            }
        }

        $support = $supportHint ?? ($prices ? min(array_slice($prices, -32)) : null);
        $resistance = $resistanceHint ?? ($prices ? max(array_slice($prices, -32)) : null);

        $distanceSupport = null;
        if ($price !== null && $price > 0 && $support !== null && $support > 0) {
            $distanceSupport = (($price - $support) / $price) * 100.0;
        }

        $latestTs = $this->toTs($quoteTime);
        if ($latestTs <= 0 && $series) {
            $latestTs = $this->toTs((string) ($series[count($series) - 1]['quote_time'] ?? ''));
        }
        $rise3d = $this->calcRiseByDays($series, 3, $price, $latestTs);
        $rise5d = $this->calcRiseByDays($series, 5, $price, $latestTs);
        $pullback = $this->calcPullback($series, $price, $latestTs, 3);
        $volumeRatio = $this->calcVolumeRatio($volume, $volumes);

        $nearSupport = $distanceSupport !== null && $distanceSupport >= 0 && $distanceSupport <= 3.2;
        $risePullback = $rise3d !== null && $rise3d >= 4.5 && $pullback !== null && $pullback >= 0.6 && $pullback <= 4.8;
        $volumeSurge = $volumeRatio !== null && $volumeRatio >= 1.6;

        $technicalScore = $this->calcTechnicalScore($nearSupport, $risePullback, $volumeSurge, $rise3d, $pullback, $trend);
        $fundsScore = $this->calcFundsScore($closeMeta, $changePct);
        $newsMeta = $this->newsPolicyScore($symbol, $name, $sectorName);
        $newsScore = (float) ($newsMeta['news_score'] ?? 0.0);
        $policyScore = (float) ($newsMeta['policy_score'] ?? 0.0);
        $totalScore = round($technicalScore * 0.4 + $fundsScore * 0.25 + $newsScore * 0.2 + $policyScore * 0.15, 2);

        $reasons = [];
        if ($nearSupport) {
            $reasons[] = '强支撑附近';
        }
        if ($risePullback) {
            $reasons[] = '短涨后小回调';
        }
        if ($volumeSurge) {
            $reasons[] = '短期放量';
        }
        if ((int) ($closeMeta['strong_rank'] ?? 0) > 0 && (int) ($closeMeta['strong_rank'] ?? 0) <= 30) {
            $reasons[] = '强势榜';
        }
        if ((int) ($closeMeta['money_rank'] ?? 0) > 0 && (int) ($closeMeta['money_rank'] ?? 0) <= 30) {
            $reasons[] = '资金榜';
        }
        if ((int) ($newsMeta['news_hits'] ?? 0) >= 3) {
            $reasons[] = '消息热度高';
        }

        return [
            'price' => $price !== null ? round($price, 4) : null,
            'change_pct' => $changePct !== null ? round($changePct, 4) : null,
            'volume' => $volume !== null ? round($volume, 2) : null,
            'turnover' => $turnover !== null ? round($turnover, 2) : null,
            'quote_time' => $quoteTime,
            'support_price' => $support !== null ? round($support, 4) : null,
            'resistance_price' => $resistance !== null ? round($resistance, 4) : null,
            'distance_to_support_pct' => $distanceSupport !== null ? round($distanceSupport, 4) : null,
            'rise_3d_pct' => $rise3d !== null ? round($rise3d, 4) : null,
            'rise_5d_pct' => $rise5d !== null ? round($rise5d, 4) : null,
            'pullback_pct' => $pullback !== null ? round($pullback, 4) : null,
            'volume_ratio' => $volumeRatio !== null ? round($volumeRatio, 4) : null,
            'near_support' => $nearSupport,
            'rise_pullback' => $risePullback,
            'volume_surge' => $volumeSurge,
            'close_rank' => (int) ($closeMeta['strong_rank'] ?? 0),
            'money_rank' => (int) ($closeMeta['money_rank'] ?? 0),
            'net_main_inflow' => $this->toFloat($closeMeta['net_main_inflow'] ?? null),
            'net_main_inflow_pct' => $this->toFloat($closeMeta['net_main_inflow_pct'] ?? null),
            'flow_direction' => (string) ($closeMeta['flow_direction'] ?? ''),
            'news_hits_72h' => (int) ($newsMeta['news_hits'] ?? 0),
            'news_latest_title' => (string) ($newsMeta['latest_title'] ?? ''),
            'news_latest_time' => (string) ($newsMeta['latest_time'] ?? ''),
            'policy_tag' => (string) ($newsMeta['policy_tag'] ?? '中性'),
            'technical_score' => $technicalScore,
            'funds_score' => $fundsScore,
            'news_score' => $newsScore,
            'policy_score' => $policyScore,
            'dimension_scores' => [
                'technical' => $technicalScore,
                'news' => $newsScore,
                'policy' => $policyScore,
                'funds' => $fundsScore,
            ],
            'total_score' => $totalScore,
            'signal_level' => $totalScore >= 75 ? 'A' : ($totalScore >= 62 ? 'B' : ($totalScore >= 50 ? 'C' : 'D')),
            'action_advice' => $this->buildAdvice($nearSupport, $risePullback, $volumeSurge, $technicalScore, $fundsScore, (string) ($newsMeta['policy_tag'] ?? '中性')),
            'reasons' => $reasons,
            'sparkline_prices' => array_map(static fn(float $v): float => round($v, 4), array_slice($prices, -36)),
        ];
    }

    private function calcTechnicalScore(bool $nearSupport, bool $risePullback, bool $volumeSurge, ?float $rise3d, ?float $pullback, string $trend): float
    {
        $score = 34.0;
        if ($nearSupport) {
            $score += 20.0;
        }
        if ($risePullback) {
            $score += 18.0;
        }
        if ($volumeSurge) {
            $score += 14.0;
        }
        if ($rise3d !== null) {
            $score += max(-8.0, min(14.0, $rise3d * 1.4));
        }
        if ($pullback !== null) {
            if ($pullback > 6.5) {
                $score -= 7.0;
            } elseif ($pullback >= 1.0 && $pullback <= 4.5) {
                $score += 4.0;
            }
        }
        $score += match ($trend) {
            'strong_up' => 9.0,
            'up_bias' => 5.0,
            'range' => 2.0,
            'down_bias' => -5.0,
            'strong_down' => -9.0,
            default => 0.0,
        };
        return round(max(0.0, min(100.0, $score)), 2);
    }

    /**
     * @param array<string, mixed> $closeMeta
     */
    private function calcFundsScore(array $closeMeta, ?float $changePct): float
    {
        $score = 38.0;
        $strong = (int) ($closeMeta['strong_rank'] ?? 0);
        $money = (int) ($closeMeta['money_rank'] ?? 0);
        if ($strong > 0) {
            $score += $strong <= 10 ? 18.0 : ($strong <= 30 ? 12.0 : ($strong <= 80 ? 6.0 : 0.0));
        }
        if ($money > 0) {
            $score += $money <= 10 ? 20.0 : ($money <= 30 ? 14.0 : ($money <= 80 ? 7.0 : 0.0));
        }
        $flow = strtolower(trim((string) ($closeMeta['flow_direction'] ?? '')));
        if ($flow === 'inflow') {
            $score += 10.0;
        } elseif ($flow === 'outflow') {
            $score -= 8.0;
        }
        $flowPct = $this->toFloat($closeMeta['net_main_inflow_pct'] ?? null);
        if ($flowPct !== null) {
            $score += max(-8.0, min(10.0, $flowPct * 2.0));
        }
        if ($changePct !== null && $changePct > 0) {
            $score += min(7.0, $changePct * 0.9);
        }
        return round(max(0.0, min(100.0, $score)), 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function newsPolicyScore(string $symbol, string $name, string $sectorName): array
    {
        $key = $symbol . '|' . $name . '|' . $sectorName;
        if (isset($this->newsCache[$key])) {
            return $this->newsCache[$key];
        }

        $sectorKeywords = $this->sectorKeywords($sectorName);
        $policyGood = ['政策', '国务院', '发改委', '财政部', '央行', '证监会', '工信部', '规划', '指导意见', '补贴', '试点', '支持'];
        $policyBad = ['处罚', '调查', '监管趋严', '减持', '问询', '风险提示', '制裁'];

        $hits = 0;
        $good = 0;
        $bad = 0;
        $latestTitle = '';
        $latestTime = '';

        foreach ($this->recentNews as $news) {
            $title = (string) ($news['title'] ?? '');
            $summary = (string) ($news['summary'] ?? '');
            $text = $title . ' ' . $summary;
            $matched = false;
            if ($symbol !== '' && $this->contains($text, $symbol)) {
                $matched = true;
            }
            if (!$matched && $name !== '' && $this->contains($text, $name)) {
                $matched = true;
            }
            if (!$matched) {
                foreach ($sectorKeywords as $kw) {
                    if ($this->contains($text, $kw)) {
                        $matched = true;
                        break;
                    }
                }
            }
            if (!$matched) {
                continue;
            }

            $hits++;
            $time = (string) ($news['published_at'] ?? '');
            if ($latestTime === '' || strcmp($time, $latestTime) > 0) {
                $latestTime = $time;
                $latestTitle = $title;
            }
            $good += $this->keywordHits($text, $policyGood);
            $bad += $this->keywordHits($text, $policyBad);
        }

        $newsScore = round(max(0.0, min(100.0, 34.0 + min(52.0, $hits * 9.5))), 2);
        $policyScore = round(max(0.0, min(100.0, 42.0 + $good * 11.0 - $bad * 10.0)), 2);
        $policyTag = $bad >= 2 ? '政策扰动' : ($good >= 2 ? '政策催化' : ($good >= 1 ? '政策偏暖' : '中性'));

        $meta = [
            'news_score' => $newsScore,
            'policy_score' => $policyScore,
            'policy_tag' => $policyTag,
            'news_hits' => $hits,
            'latest_title' => $latestTitle,
            'latest_time' => $latestTime,
        ];
        $this->newsCache[$key] = $meta;
        return $meta;
    }

    private function buildAdvice(bool $nearSupport, bool $risePullback, bool $volumeSurge, float $technicalScore, float $fundsScore, string $policyTag): string
    {
        if ($nearSupport && $volumeSurge && $fundsScore >= 60) {
            return '支撑位附近且放量，资金面偏强，可分批观察低吸，跌破支撑位及时风控。';
        }
        if ($risePullback && $volumeSurge && $technicalScore >= 70) {
            return '短线拉升后小回调并放量，优先等回踩承接确认，不宜追高。';
        }
        if ($policyTag === '政策催化') {
            return '政策与消息共振，关注板块联动和放量持续性。';
        }
        if ($technicalScore < 50 || $fundsScore < 45) {
            return '信号不完整，先跟踪量价与资金变化，等待更清晰入场点。';
        }
        return '维持跟踪，观察量价配合与支撑位有效性。';
    }

    /**
     * @param array<int, array<string, mixed>> $series
     */
    private function calcRiseByDays(array $series, int $days, ?float $latestPrice, int $latestTs): ?float
    {
        if ($latestPrice === null || $latestPrice <= 0 || $latestTs <= 0 || $series === []) {
            return null;
        }
        $target = $latestTs - $days * 86400;
        $base = null;
        foreach ($series as $row) {
            $ts = $this->toTs((string) ($row['quote_time'] ?? ''));
            $p = $this->toFloat($row['price'] ?? null);
            if ($ts <= 0 || $p === null || $p <= 0) {
                continue;
            }
            if ($ts >= $target) {
                $base = $p;
                break;
            }
            $base = $p;
        }
        if ($base === null || $base <= 0) {
            return null;
        }
        return (($latestPrice - $base) / $base) * 100.0;
    }

    /**
     * @param array<int, array<string, mixed>> $series
     */
    private function calcPullback(array $series, ?float $latestPrice, int $latestTs, int $windowDays): ?float
    {
        if ($latestPrice === null || $latestPrice <= 0 || $latestTs <= 0 || $series === []) {
            return null;
        }
        $start = $latestTs - $windowDays * 86400;
        $peak = null;
        foreach ($series as $row) {
            $ts = $this->toTs((string) ($row['quote_time'] ?? ''));
            if ($ts < $start) {
                continue;
            }
            $p = $this->toFloat($row['price'] ?? null);
            if ($p === null || $p <= 0) {
                continue;
            }
            $peak = $peak === null ? $p : max($peak, $p);
        }
        if ($peak === null || $peak <= 0 || $peak < $latestPrice) {
            return 0.0;
        }
        return (($peak - $latestPrice) / $peak) * 100.0;
    }

    /**
     * @param array<int, float> $volumes
     */
    private function calcVolumeRatio(?float $latestVolume, array $volumes): ?float
    {
        if ($latestVolume === null || $latestVolume <= 0 || $volumes === []) {
            return null;
        }
        $slice = array_slice($volumes, -20);
        if ($slice === []) {
            return null;
        }
        $avg = array_sum($slice) / count($slice);
        if ($avg <= 0) {
            return null;
        }
        return $latestVolume / $avg;
    }

    private function inferTrend(?float $changePct): string
    {
        if ($changePct === null) {
            return 'range';
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

    private function isTradingSessionNow(): bool
    {
        $weekday = (int) date('N');
        if ($weekday >= 6) {
            return false;
        }
        $minute = (int) date('G') * 60 + (int) date('i');
        $am = $minute >= 570 && $minute <= 690; // 09:30-11:30
        $pm = $minute >= 780 && $minute <= 900; // 13:00-15:00
        return $am || $pm;
    }

    /**
     * @return array<int, string>
     */
    private function sectorKeywords(string $sector): array
    {
        $sector = trim($sector);
        if ($sector === '') {
            return [];
        }
        $set = [$sector];
        $len = function_exists('mb_strlen') ? mb_strlen($sector) : strlen($sector);
        if ($len >= 4) {
            $set[] = function_exists('mb_substr') ? mb_substr($sector, 0, 4) : substr($sector, 0, 4);
        }
        if ($len >= 2) {
            $set[] = function_exists('mb_substr') ? mb_substr($sector, 0, 2) : substr($sector, 0, 2);
        }
        return array_values(array_unique(array_filter(array_map('trim', $set), static fn(string $x): bool => $x !== '')));
    }

    private function keywordHits(string $text, array $keywords): int
    {
        $count = 0;
        foreach ($keywords as $kw) {
            if ($kw !== '' && $this->contains($text, $kw)) {
                $count++;
            }
        }
        return $count;
    }

    private function contains(string $text, string $needle): bool
    {
        if ($text === '' || trim($needle) === '') {
            return false;
        }
        if (function_exists('mb_stripos')) {
            return mb_stripos($text, $needle) !== false;
        }
        return stripos($text, $needle) !== false;
    }

    private function symbolKey(string $symbol): string
    {
        return 's:' . strtoupper(trim($symbol));
    }

    private function toTs(string $timeText): int
    {
        $timeText = trim($timeText);
        if ($timeText === '') {
            return 0;
        }
        return (int) (strtotime($timeText) ?: 0);
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }
}
