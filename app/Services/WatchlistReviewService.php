<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class WatchlistReviewService
{
    /**
     * @return array<string, int>
     */
    public function syncForWatchlist(int $watchlistId, int $userId, int $limitInsights = 36): array
    {
        if ($watchlistId <= 0 || $userId <= 0) {
            return ['watchlists' => 0, 'insights' => 0, 'upserts' => 0];
        }

        $watch = $this->findWatchlist($watchlistId, $userId);
        if ($watch === null) {
            return ['watchlists' => 0, 'insights' => 0, 'upserts' => 0];
        }

        $symbol = strtoupper(trim((string) ($watch['symbol'] ?? '')));
        $market = (string) ($watch['market'] ?? 'A_STOCK_MAIN');
        if ($symbol === '') {
            return ['watchlists' => 0, 'insights' => 0, 'upserts' => 0];
        }

        $insights = $this->loadRecentInsights($watchlistId, $userId, $limitInsights);
        if ($insights === []) {
            return ['watchlists' => 1, 'insights' => 0, 'upserts' => 0];
        }

        $minInsightTs = $this->minInsightTimestamp($insights);
        $quotes = $this->loadQuoteSeries($symbol, $market, $minInsightTs);

        $upserts = 0;
        foreach ($insights as $insight) {
            $record = $this->buildRecord($watch, $insight, $quotes, $userId);
            if ($record === null) {
                continue;
            }
            $this->upsertRecord($record);
            $upserts++;
        }

        return [
            'watchlists' => 1,
            'insights' => count($insights),
            'upserts' => $upserts,
        ];
    }

    /**
     * @param array<int, int> $watchlistIds
     * @return array<string, int>
     */
    public function syncForWatchlists(array $watchlistIds, int $userId, int $limitInsights = 24): array
    {
        $watchlistIds = array_values(array_unique(array_filter(array_map(
            static fn(mixed $v): int => (int) $v,
            $watchlistIds
        ), static fn(int $v): bool => $v > 0)));

        if ($watchlistIds === [] || $userId <= 0) {
            return ['watchlists' => 0, 'insights' => 0, 'upserts' => 0];
        }

        $watchlists = $this->loadWatchlistsByIds($watchlistIds, $userId);
        if ($watchlists === []) {
            return ['watchlists' => 0, 'insights' => 0, 'upserts' => 0];
        }

        $insightTotal = 0;
        $upsertTotal = 0;

        foreach ($watchlists as $watch) {
            $watchId = (int) ($watch['id'] ?? 0);
            if ($watchId <= 0) {
                continue;
            }
            $result = $this->syncForWatchlist($watchId, $userId, $limitInsights);
            $insightTotal += (int) ($result['insights'] ?? 0);
            $upsertTotal += (int) ($result['upserts'] ?? 0);
        }

        return [
            'watchlists' => count($watchlists),
            'insights' => $insightTotal,
            'upserts' => $upsertTotal,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function syncForAllActive(int $userId, int $watchlistLimit = 200, int $limitInsights = 24): array
    {
        if ($userId <= 0) {
            return ['watchlists' => 0, 'insights' => 0, 'upserts' => 0];
        }

        $stmt = Database::connection()->prepare(
            "SELECT id
             FROM watchlist
             WHERE user_id = :user_id
               AND status = 'active'
             ORDER BY priority ASC, updated_at DESC, id DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(20, min(500, $watchlistLimit)), \PDO::PARAM_INT);
        $stmt->execute();

        $ids = array_map(
            static fn(array $row): int => (int) ($row['id'] ?? 0),
            $stmt->fetchAll() ?: []
        );

        return $this->syncForWatchlists($ids, $userId, $limitInsights);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function enrichRows(array $rows, int $userId, int $sparkPoints = 12): array
    {
        if ($rows === [] || $userId <= 0) {
            return $rows;
        }

        $watchlistIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int) ($row['id'] ?? 0),
            $rows
        ), static fn(int $id): bool => $id > 0)));

        if ($watchlistIds === []) {
            return $rows;
        }

        $summaryMap = $this->loadReviewSummaryMap($watchlistIds, $userId);
        $sparkMap = $this->loadSparklineMap($watchlistIds, $userId, $sparkPoints);
        $snapshotMap = $this->loadLatestSymbolSnapshotMap($watchlistIds, $userId);

        foreach ($rows as &$row) {
            $id = (int) ($row['id'] ?? 0);
            $summary = $summaryMap[$id] ?? null;
            $spark = $sparkMap[$id] ?? [];

            $row['review_total'] = (int) ($summary['review_total'] ?? 0);
            $row['review_accurate'] = (int) ($summary['review_accurate'] ?? 0);
            $row['review_accuracy_rate'] = $summary['review_accuracy_rate'] ?? null;
            $row['review_avg_return_1d_pct'] = $summary['avg_return_1d_pct'] ?? null;
            $row['review_avg_return_3d_pct'] = $summary['avg_return_3d_pct'] ?? null;
            $row['review_avg_return_5d_pct'] = $summary['avg_return_5d_pct'] ?? null;
            $row['review_win_rate_5d'] = $summary['win_rate_5d'] ?? null;
            $row['review_latest_at'] = (string) ($summary['latest_at'] ?? '');
            $row['review_last_return_pct'] = $spark !== [] ? (float) end($spark) : null;
            $row['review_sparkline_points'] = $spark;

            $snapshot = $snapshotMap[$id] ?? null;
            $row['snapshot_date'] = (string) ($snapshot['snapshot_date'] ?? '');
            $row['snapshot_slot'] = (string) ($snapshot['snapshot_slot'] ?? '');
            $row['snapshot_sample_count'] = (int) ($snapshot['sample_count'] ?? 0);
            $row['snapshot_accuracy_rate'] = $snapshot['accuracy_rate'] ?? null;
            $row['snapshot_avg_return_1d_pct'] = $snapshot['avg_return_1d_pct'] ?? null;
            $row['snapshot_avg_return_3d_pct'] = $snapshot['avg_return_3d_pct'] ?? null;
            $row['snapshot_avg_return_5d_pct'] = $snapshot['avg_return_5d_pct'] ?? null;
            $row['snapshot_win_rate_5d'] = $snapshot['win_rate_5d'] ?? null;
            $row['snapshot_score'] = $snapshot['score'] ?? null;
            $row['snapshot_series_points'] = $this->decodeNumberList($snapshot['series_json'] ?? null, 24);
        }
        unset($row);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(int $watchlistId, int $userId): array
    {
        $map = $this->loadReviewSummaryMap([$watchlistId], $userId);
        return $map[$watchlistId] ?? [
            'review_total' => 0,
            'review_accurate' => 0,
            'review_accuracy_rate' => null,
            'avg_return_1d_pct' => null,
            'avg_return_3d_pct' => null,
            'avg_return_5d_pct' => null,
            'win_rate_5d' => null,
            'latest_at' => '',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function history(int $watchlistId, int $userId, int $limit = 20): array
    {
        if ($watchlistId <= 0 || $userId <= 0) {
            return [];
        }

        $stmt = Database::connection()->prepare(
            'SELECT id, watchlist_id, insight_id, symbol, market, insight_time, base_price,
                    price_1d, price_3d, price_5d,
                    return_1d_pct, return_3d_pct, return_5d_pct,
                    expected_direction, is_accurate, evaluation_note, evaluated_at
             FROM watchlist_review_records
             WHERE user_id = :user_id AND watchlist_id = :watchlist_id
             ORDER BY evaluated_at DESC, id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':watchlist_id', $watchlistId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, min(120, $limit)), \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<string, int>
     */
    public function snapshotForUser(int $userId, string $slot = 'manual', int $watchlistLimit = 360): array
    {
        if ($userId <= 0) {
            return ['symbols' => 0, 'written' => 0];
        }

        $snapshotSlot = $this->normalizeSnapshotSlot($slot);
        $snapshotDate = date('Y-m-d');
        $snapshotTime = now_sql();

        $rows = $this->loadActiveWatchlistRows($userId, $watchlistLimit);
        $rows = $this->enrichRows($rows, $userId, 18);

        $global = $this->buildGlobalSnapshot($rows);
        $written = 0;

        $written += $this->upsertSnapshot([
            'user_id' => $userId,
            'snapshot_date' => $snapshotDate,
            'snapshot_slot' => $snapshotSlot,
            'snapshot_scope' => 'global',
            'watchlist_id' => null,
            'symbol' => '',
            'market' => null,
            'name' => 'GLOBAL',
            'sample_count' => (int) ($global['sample_count'] ?? 0),
            'accuracy_rate' => $global['accuracy_rate'] ?? null,
            'avg_return_1d_pct' => $global['avg_return_1d_pct'] ?? null,
            'avg_return_3d_pct' => $global['avg_return_3d_pct'] ?? null,
            'avg_return_5d_pct' => $global['avg_return_5d_pct'] ?? null,
            'win_rate_5d' => $global['win_rate_5d'] ?? null,
            'score' => $global['score'] ?? null,
            'series_json' => $global['series_points'] ?? [],
            'snapshot_time' => $snapshotTime,
            'raw_json' => [
                'total_symbols' => count($rows),
                'covered_symbols' => (int) ($global['covered_symbols'] ?? 0),
                'source' => 'review_snapshot',
            ],
        ]);

        foreach ($rows as $row) {
            $watchId = (int) ($row['id'] ?? 0);
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
            if ($watchId <= 0 || $symbol === '') {
                continue;
            }

            $sampleCount = (int) ($row['snapshot_sample_count'] ?? $row['review_total'] ?? 0);
            $series = $row['snapshot_series_points'] ?? $row['review_sparkline_points'] ?? [];
            if (!is_array($series)) {
                $series = [];
            }

            $score = $this->toFloat($row['snapshot_score'] ?? null);
            if ($score === null) {
                $score = $this->computeSnapshotScore(
                    $this->toFloat($row['snapshot_accuracy_rate'] ?? $row['review_accuracy_rate'] ?? null),
                    $this->toFloat($row['snapshot_avg_return_5d_pct'] ?? $row['review_avg_return_5d_pct'] ?? null),
                    $this->toFloat($row['snapshot_win_rate_5d'] ?? $row['review_win_rate_5d'] ?? null),
                    $sampleCount
                );
            }

            $written += $this->upsertSnapshot([
                'user_id' => $userId,
                'snapshot_date' => $snapshotDate,
                'snapshot_slot' => $snapshotSlot,
                'snapshot_scope' => 'symbol',
                'watchlist_id' => $watchId,
                'symbol' => $symbol,
                'market' => (string) ($row['market'] ?? 'A_STOCK_MAIN'),
                'name' => (string) ($row['name'] ?? ''),
                'sample_count' => $sampleCount,
                'accuracy_rate' => $row['snapshot_accuracy_rate'] ?? $row['review_accuracy_rate'] ?? null,
                'avg_return_1d_pct' => $row['snapshot_avg_return_1d_pct'] ?? $row['review_avg_return_1d_pct'] ?? null,
                'avg_return_3d_pct' => $row['snapshot_avg_return_3d_pct'] ?? $row['review_avg_return_3d_pct'] ?? null,
                'avg_return_5d_pct' => $row['snapshot_avg_return_5d_pct'] ?? $row['review_avg_return_5d_pct'] ?? null,
                'win_rate_5d' => $row['snapshot_win_rate_5d'] ?? $row['review_win_rate_5d'] ?? null,
                'score' => $score,
                'series_json' => array_slice($this->sanitizeNumberList($series), -24),
                'snapshot_time' => $snapshotTime,
                'raw_json' => [
                    'priority' => (int) ($row['priority'] ?? 0),
                    'status' => (string) ($row['status'] ?? ''),
                    'sector_name' => (string) ($row['sector_name'] ?? ''),
                    'trend_direction' => (string) ($row['trend_direction'] ?? ''),
                ],
            ]);
        }

        return [
            'symbols' => count($rows),
            'written' => $written,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function globalSnapshots(int $userId, int $days = 30): array
    {
        if ($userId <= 0) {
            return [];
        }

        $days = max(3, min(180, $days));
        $stmt = Database::connection()->prepare(
            'SELECT snapshot_date, snapshot_slot, sample_count,
                    accuracy_rate, avg_return_1d_pct, avg_return_3d_pct, avg_return_5d_pct, win_rate_5d, score,
                    snapshot_time
             FROM watchlist_review_snapshots
             WHERE user_id = :user_id
               AND snapshot_scope = \'global\'
               AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
             ORDER BY snapshot_date ASC,
                      CASE snapshot_slot WHEN \'midday\' THEN 1 WHEN \'close\' THEN 2 ELSE 3 END ASC,
                      id ASC'
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':days', $days, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<string, mixed>
     */
    public function latestGlobalSnapshot(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $stmt = Database::connection()->prepare(
            'SELECT snapshot_date, snapshot_slot, sample_count,
                    accuracy_rate, avg_return_1d_pct, avg_return_3d_pct, avg_return_5d_pct, win_rate_5d, score,
                    snapshot_time
             FROM watchlist_review_snapshots
             WHERE user_id = :user_id
               AND snapshot_scope = \'global\'
             ORDER BY snapshot_date DESC,
                      CASE snapshot_slot WHEN \'close\' THEN 1 WHEN \'midday\' THEN 2 ELSE 3 END ASC,
                      id DESC
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function watchlistSnapshotSeries(int $userId, int $watchlistId, int $days = 30): array
    {
        if ($userId <= 0 || $watchlistId <= 0) {
            return [];
        }

        $days = max(3, min(180, $days));
        $stmt = Database::connection()->prepare(
            'SELECT snapshot_date, snapshot_slot, sample_count,
                    accuracy_rate, avg_return_1d_pct, avg_return_3d_pct, avg_return_5d_pct, win_rate_5d, score,
                    snapshot_time
             FROM watchlist_review_snapshots
             WHERE user_id = :user_id
               AND snapshot_scope = \'symbol\'
               AND watchlist_id = :watchlist_id
               AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
             ORDER BY snapshot_date ASC,
                      CASE snapshot_slot WHEN \'midday\' THEN 1 WHEN \'close\' THEN 2 ELSE 3 END ASC,
                      id ASC'
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':watchlist_id', $watchlistId, \PDO::PARAM_INT);
        $stmt->bindValue(':days', $days, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @param array<int, int> $watchlistIds
     * @return array<int, array<string, mixed>>
     */
    private function loadLatestSymbolSnapshotMap(array $watchlistIds, int $userId): array
    {
        if ($userId <= 0 || $watchlistIds === []) {
            return [];
        }

        [$inSql, $bindings] = $this->buildIdBindings($watchlistIds, 'ls');
        $bindings['user_id'] = $userId;

        $sql = sprintf(
            'SELECT s.watchlist_id, s.snapshot_date, s.snapshot_slot, s.sample_count,
                    s.accuracy_rate, s.avg_return_1d_pct, s.avg_return_3d_pct, s.avg_return_5d_pct,
                    s.win_rate_5d, s.score, s.series_json, s.snapshot_time
             FROM watchlist_review_snapshots s
             INNER JOIN (
                SELECT watchlist_id, MAX(id) AS max_id
                FROM watchlist_review_snapshots
                WHERE user_id = :user_id
                  AND snapshot_scope = \'symbol\'
                  AND watchlist_id IN (%s)
                GROUP BY watchlist_id
             ) latest ON latest.max_id = s.id',
            $inSql
        );

        $stmt = Database::connection()->prepare($sql);
        foreach ($bindings as $k => $v) {
            $stmt->bindValue(':' . $k, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];

        $map = [];
        foreach ($rows as $row) {
            $id = (int) ($row['watchlist_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $map[$id] = $row;
        }

        return $map;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadActiveWatchlistRows(int $userId, int $limit): array
    {
        if ($userId <= 0) {
            return [];
        }

        $stmt = Database::connection()->prepare(
            "SELECT id, symbol, market, name, priority, status, sector_name, trend_direction
             FROM watchlist
             WHERE user_id = :user_id
               AND status = 'active'
             ORDER BY priority ASC, updated_at DESC, id DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(20, min(600, $limit)), \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    private function normalizeSnapshotSlot(string $slot): string
    {
        $slot = strtolower(trim($slot));
        if (in_array($slot, ['midday', 'close', 'manual'], true)) {
            return $slot;
        }
        return 'manual';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function buildGlobalSnapshot(array $rows): array
    {
        $covered = array_values(array_filter(
            $rows,
            static fn(array $row): bool => (int) ($row['review_total'] ?? 0) > 0
        ));
        $totalSamples = array_reduce(
            $covered,
            static fn(int $carry, array $row): int => $carry + (int) ($row['review_total'] ?? 0),
            0
        );

        $weighted = static function (array $items, string $metric, int $samples): ?float {
            if ($items === [] || $samples <= 0) {
                return null;
            }
            $sum = 0.0;
            foreach ($items as $row) {
                $w = (int) ($row['review_total'] ?? 0);
                $v = $row[$metric] ?? null;
                if ($w <= 0 || $v === null || $v === '' || !is_numeric($v)) {
                    continue;
                }
                $sum += ((float) $v) * $w;
            }
            if ($sum == 0.0 && $samples <= 0) {
                return null;
            }
            return $sum / max(1, $samples);
        };

        $accuracy = $weighted($covered, 'review_accuracy_rate', $totalSamples);
        $ret1d = $weighted($covered, 'review_avg_return_1d_pct', $totalSamples);
        $ret3d = $weighted($covered, 'review_avg_return_3d_pct', $totalSamples);
        $ret5d = $weighted($covered, 'review_avg_return_5d_pct', $totalSamples);
        $win5d = $weighted($covered, 'review_win_rate_5d', $totalSamples);

        $series = [];
        $maxLen = 0;
        $seriesPool = [];
        foreach ($covered as $row) {
            $s = $this->sanitizeNumberList($row['review_sparkline_points'] ?? []);
            if ($s === []) {
                continue;
            }
            $seriesPool[] = $s;
            $maxLen = max($maxLen, count($s));
        }
        for ($i = 0; $i < $maxLen; $i++) {
            $vals = [];
            foreach ($seriesPool as $s) {
                $idx = count($s) - $maxLen + $i;
                if ($idx >= 0 && isset($s[$idx])) {
                    $vals[] = (float) $s[$idx];
                }
            }
            if ($vals !== []) {
                $series[] = round(array_sum($vals) / count($vals), 4);
            }
        }

        return [
            'covered_symbols' => count($covered),
            'sample_count' => $totalSamples,
            'accuracy_rate' => $this->roundNullable4($accuracy),
            'avg_return_1d_pct' => $this->roundNullable4($ret1d),
            'avg_return_3d_pct' => $this->roundNullable4($ret3d),
            'avg_return_5d_pct' => $this->roundNullable4($ret5d),
            'win_rate_5d' => $this->roundNullable4($win5d),
            'score' => $this->computeSnapshotScore($accuracy, $ret5d, $win5d, $totalSamples),
            'series_points' => array_slice($series, -24),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function upsertSnapshot(array $row): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO watchlist_review_snapshots (
                user_id, snapshot_date, snapshot_slot, snapshot_scope, watchlist_id, symbol, market, name,
                sample_count, accuracy_rate, avg_return_1d_pct, avg_return_3d_pct, avg_return_5d_pct, win_rate_5d, score,
                series_json, source, snapshot_time, raw_json, created_at, updated_at
             ) VALUES (
                :user_id, :snapshot_date, :snapshot_slot, :snapshot_scope, :watchlist_id, :symbol, :market, :name,
                :sample_count, :accuracy_rate, :avg_return_1d_pct, :avg_return_3d_pct, :avg_return_5d_pct, :win_rate_5d, :score,
                :series_json, \'review_service\', :snapshot_time, :raw_json, NOW(), NOW()
             )
             ON DUPLICATE KEY UPDATE
                watchlist_id = VALUES(watchlist_id),
                market = VALUES(market),
                name = VALUES(name),
                sample_count = VALUES(sample_count),
                accuracy_rate = VALUES(accuracy_rate),
                avg_return_1d_pct = VALUES(avg_return_1d_pct),
                avg_return_3d_pct = VALUES(avg_return_3d_pct),
                avg_return_5d_pct = VALUES(avg_return_5d_pct),
                win_rate_5d = VALUES(win_rate_5d),
                score = VALUES(score),
                series_json = VALUES(series_json),
                snapshot_time = VALUES(snapshot_time),
                raw_json = VALUES(raw_json),
                updated_at = NOW()'
        );

        $stmt->execute([
            'user_id' => (int) ($row['user_id'] ?? 0),
            'snapshot_date' => (string) ($row['snapshot_date'] ?? date('Y-m-d')),
            'snapshot_slot' => $this->normalizeSnapshotSlot((string) ($row['snapshot_slot'] ?? 'manual')),
            'snapshot_scope' => (string) ($row['snapshot_scope'] ?? 'symbol'),
            'watchlist_id' => $row['watchlist_id'] ?? null,
            'symbol' => strtoupper(trim((string) ($row['symbol'] ?? ''))),
            'market' => $row['market'] ?? null,
            'name' => $row['name'] ?? null,
            'sample_count' => max(0, (int) ($row['sample_count'] ?? 0)),
            'accuracy_rate' => $row['accuracy_rate'] ?? null,
            'avg_return_1d_pct' => $row['avg_return_1d_pct'] ?? null,
            'avg_return_3d_pct' => $row['avg_return_3d_pct'] ?? null,
            'avg_return_5d_pct' => $row['avg_return_5d_pct'] ?? null,
            'win_rate_5d' => $row['win_rate_5d'] ?? null,
            'score' => $row['score'] ?? null,
            'series_json' => json_encode($this->sanitizeNumberList($row['series_json'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'snapshot_time' => (string) ($row['snapshot_time'] ?? now_sql()),
            'raw_json' => json_encode($row['raw_json'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return 1;
    }

    /**
     * @param mixed $value
     * @return array<int, float>
     */
    private function decodeNumberList(mixed $value, int $maxPoints = 24): array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }
        return array_slice($this->sanitizeNumberList($value), -max(1, min(200, $maxPoints)));
    }

    /**
     * @param mixed $value
     * @return array<int, float>
     */
    private function sanitizeNumberList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $v) {
            if ($v === null || $v === '' || !is_numeric($v)) {
                continue;
            }
            $out[] = round((float) $v, 4);
        }
        return $out;
    }

    private function computeSnapshotScore(?float $accuracyRate, ?float $avgReturn5d, ?float $winRate5d, int $samples): ?float
    {
        if ($accuracyRate === null && $avgReturn5d === null && $winRate5d === null) {
            return null;
        }

        $acc = $accuracyRate ?? 0.0;
        $ret = $avgReturn5d ?? 0.0;
        $win = $winRate5d ?? 0.0;
        $sampleBoost = min(18.0, max(0.0, $samples) * 0.35);

        $score = $acc * 0.45 + $win * 0.28 + $ret * 4.2 + $sampleBoost;
        return round($score, 4);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findWatchlist(int $watchlistId, int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, symbol, market, name
             FROM watchlist
             WHERE id = :id AND user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $watchlistId,
            'user_id' => $userId,
        ]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<int, int> $watchlistIds
     * @return array<int, array<string, mixed>>
     */
    private function loadWatchlistsByIds(array $watchlistIds, int $userId): array
    {
        if ($watchlistIds === []) {
            return [];
        }

        $placeholders = [];
        $bindings = ['user_id' => $userId];
        foreach ($watchlistIds as $idx => $id) {
            $key = 'id' . $idx;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $id;
        }

        $sql = sprintf(
            'SELECT id, user_id, symbol, market, name
             FROM watchlist
             WHERE user_id = :user_id
               AND id IN (%s)',
            implode(', ', $placeholders)
        );

        $stmt = Database::connection()->prepare($sql);
        foreach ($bindings as $k => $v) {
            $stmt->bindValue(':' . $k, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRecentInsights(int $watchlistId, int $userId, int $limit): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, watchlist_id, symbol, current_price, position_zone, action_advice, analyzed_at
             FROM watchlist_insights
             WHERE user_id = :user_id AND watchlist_id = :watchlist_id
             ORDER BY analyzed_at DESC, id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':watchlist_id', $watchlistId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(5, min(120, $limit)), \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    private function minInsightTimestamp(array $insights): string
    {
        $timestamps = [];
        foreach ($insights as $item) {
            $time = (string) ($item['analyzed_at'] ?? '');
            if ($time !== '') {
                $timestamps[] = strtotime($time) ?: 0;
            }
        }
        $timestamps = array_values(array_filter($timestamps, static fn(int $v): bool => $v > 0));

        if ($timestamps === []) {
            return date('Y-m-d H:i:s', time() - 86400 * 10);
        }

        $min = min($timestamps) - 86400;
        return date('Y-m-d H:i:s', max(0, $min));
    }

    /**
     * @return array<int, array{ts:int,price:float,time:string}>
     */
    private function loadQuoteSeries(string $symbol, string $market, string $fromTime): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT quote_time, price
             FROM market_quotes
             WHERE symbol = :symbol
               AND market = :market
               AND quote_time >= :from_time
               AND price IS NOT NULL
               AND price > 0
             ORDER BY quote_time ASC, id ASC'
        );
        $stmt->execute([
            'symbol' => $symbol,
            'market' => $market,
            'from_time' => $fromTime,
        ]);

        $rows = $stmt->fetchAll() ?: [];
        $series = [];

        foreach ($rows as $row) {
            $time = (string) ($row['quote_time'] ?? '');
            $ts = strtotime($time) ?: 0;
            $price = $this->toFloat($row['price'] ?? null);
            if ($ts <= 0 || $price === null || $price <= 0) {
                continue;
            }
            $series[] = [
                'ts' => $ts,
                'price' => $price,
                'time' => $time,
            ];
        }

        return $series;
    }

    /**
     * @param array<string, mixed> $watch
     * @param array<string, mixed> $insight
     * @param array<int, array{ts:int,price:float,time:string}> $quotes
     * @return array<string, mixed>|null
     */
    private function buildRecord(array $watch, array $insight, array $quotes, int $userId): ?array
    {
        $watchlistId = (int) ($watch['id'] ?? 0);
        $insightId = (int) ($insight['id'] ?? 0);
        $symbol = strtoupper(trim((string) ($watch['symbol'] ?? $insight['symbol'] ?? '')));
        $market = (string) ($watch['market'] ?? 'A_STOCK_MAIN');
        $insightTime = (string) ($insight['analyzed_at'] ?? '');
        $insightTs = strtotime($insightTime) ?: 0;

        if ($watchlistId <= 0 || $insightId <= 0 || $symbol === '' || $insightTs <= 0) {
            return null;
        }

        $base = $this->toFloat($insight['current_price'] ?? null);
        if ($base === null || $base <= 0) {
            $base = $this->findNearestPriceAround($quotes, $insightTs, 86400);
        }
        if ($base === null || $base <= 0) {
            return null;
        }

        $price1d = $this->findFirstPriceAtOrAfter($quotes, $insightTs + 86400, 86400 * 2);
        $price3d = $this->findFirstPriceAtOrAfter($quotes, $insightTs + 86400 * 3, 86400 * 2);
        $price5d = $this->findFirstPriceAtOrAfter($quotes, $insightTs + 86400 * 5, 86400 * 3);

        $ret1d = $this->calcReturnPct($base, $price1d);
        $ret3d = $this->calcReturnPct($base, $price3d);
        $ret5d = $this->calcReturnPct($base, $price5d);

        $expectedDirection = $this->inferExpectedDirection(
            (string) ($insight['position_zone'] ?? ''),
            (string) ($insight['action_advice'] ?? '')
        );
        $isAccurate = $this->judgeAccuracy($expectedDirection, $ret1d, $ret3d, $ret5d);
        $note = $this->evaluationNote($expectedDirection, $isAccurate, $ret3d, $ret5d, $ret1d);

        return [
            'user_id' => $userId,
            'watchlist_id' => $watchlistId,
            'insight_id' => $insightId,
            'symbol' => $symbol,
            'market' => $market,
            'insight_time' => $insightTime,
            'base_price' => $this->round4($base),
            'price_1d' => $this->roundNullable4($price1d),
            'price_3d' => $this->roundNullable4($price3d),
            'price_5d' => $this->roundNullable4($price5d),
            'return_1d_pct' => $this->roundNullable4($ret1d),
            'return_3d_pct' => $this->roundNullable4($ret3d),
            'return_5d_pct' => $this->roundNullable4($ret5d),
            'expected_direction' => $expectedDirection,
            'is_accurate' => $isAccurate,
            'evaluation_note' => $note,
            'evaluated_at' => now_sql(),
            'raw_json' => [
                'position_zone' => (string) ($insight['position_zone'] ?? ''),
                'action_advice' => (string) ($insight['action_advice'] ?? ''),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function upsertRecord(array $record): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO watchlist_review_records (
                user_id, watchlist_id, insight_id, symbol, market, insight_time,
                base_price, price_1d, price_3d, price_5d,
                return_1d_pct, return_3d_pct, return_5d_pct,
                expected_direction, is_accurate, evaluation_note, evaluated_at, raw_json,
                created_at, updated_at
             ) VALUES (
                :user_id, :watchlist_id, :insight_id, :symbol, :market, :insight_time,
                :base_price, :price_1d, :price_3d, :price_5d,
                :return_1d_pct, :return_3d_pct, :return_5d_pct,
                :expected_direction, :is_accurate, :evaluation_note, :evaluated_at, :raw_json,
                NOW(), NOW()
             )
             ON DUPLICATE KEY UPDATE
                base_price = VALUES(base_price),
                price_1d = VALUES(price_1d),
                price_3d = VALUES(price_3d),
                price_5d = VALUES(price_5d),
                return_1d_pct = VALUES(return_1d_pct),
                return_3d_pct = VALUES(return_3d_pct),
                return_5d_pct = VALUES(return_5d_pct),
                expected_direction = VALUES(expected_direction),
                is_accurate = VALUES(is_accurate),
                evaluation_note = VALUES(evaluation_note),
                evaluated_at = VALUES(evaluated_at),
                raw_json = VALUES(raw_json),
                updated_at = NOW()'
        );

        $stmt->execute([
            'user_id' => $record['user_id'],
            'watchlist_id' => $record['watchlist_id'],
            'insight_id' => $record['insight_id'],
            'symbol' => $record['symbol'],
            'market' => $record['market'],
            'insight_time' => $record['insight_time'],
            'base_price' => $record['base_price'],
            'price_1d' => $record['price_1d'],
            'price_3d' => $record['price_3d'],
            'price_5d' => $record['price_5d'],
            'return_1d_pct' => $record['return_1d_pct'],
            'return_3d_pct' => $record['return_3d_pct'],
            'return_5d_pct' => $record['return_5d_pct'],
            'expected_direction' => $record['expected_direction'],
            'is_accurate' => $record['is_accurate'],
            'evaluation_note' => $record['evaluation_note'],
            'evaluated_at' => $record['evaluated_at'],
            'raw_json' => json_encode($record['raw_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * @param array<int, int> $watchlistIds
     * @return array<int, array<string, mixed>>
     */
    private function loadReviewSummaryMap(array $watchlistIds, int $userId): array
    {
        if ($watchlistIds === [] || $userId <= 0) {
            return [];
        }

        [$inSql, $bindings] = $this->buildIdBindings($watchlistIds, 'w');
        $bindings['user_id'] = $userId;

        $sql = sprintf(
            'SELECT watchlist_id,
                    COUNT(*) AS review_total,
                    SUM(CASE WHEN is_accurate = 1 THEN 1 ELSE 0 END) AS review_accurate,
                    ROUND(AVG(CASE WHEN is_accurate IS NOT NULL THEN is_accurate * 100 END), 2) AS review_accuracy_rate,
                    ROUND(AVG(return_1d_pct), 4) AS avg_return_1d_pct,
                    ROUND(AVG(return_3d_pct), 4) AS avg_return_3d_pct,
                    ROUND(AVG(return_5d_pct), 4) AS avg_return_5d_pct,
                    ROUND(AVG(CASE WHEN return_5d_pct IS NOT NULL THEN (CASE WHEN return_5d_pct > 0 THEN 100 ELSE 0 END) END), 2) AS win_rate_5d,
                    MAX(evaluated_at) AS latest_at
             FROM watchlist_review_records
             WHERE user_id = :user_id
               AND watchlist_id IN (%s)
             GROUP BY watchlist_id',
            $inSql
        );

        $stmt = Database::connection()->prepare($sql);
        foreach ($bindings as $k => $v) {
            $stmt->bindValue(':' . $k, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $map = [];
        foreach ($rows as $row) {
            $id = (int) ($row['watchlist_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $map[$id] = $row;
        }

        return $map;
    }

    /**
     * @param array<int, int> $watchlistIds
     * @return array<int, array<int, float>>
     */
    private function loadSparklineMap(array $watchlistIds, int $userId, int $points): array
    {
        if ($watchlistIds === [] || $userId <= 0) {
            return [];
        }

        [$inSql, $bindings] = $this->buildIdBindings($watchlistIds, 'sw');
        $bindings['user_id'] = $userId;

        $sql = sprintf(
            'SELECT watchlist_id, return_1d_pct, return_3d_pct, return_5d_pct, evaluated_at, id
             FROM watchlist_review_records
             WHERE user_id = :user_id
               AND watchlist_id IN (%s)
             ORDER BY evaluated_at DESC, id DESC
             LIMIT :limit',
            $inSql
        );

        $stmt = Database::connection()->prepare($sql);
        foreach ($bindings as $k => $v) {
            $stmt->bindValue(':' . $k, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', max(200, min(4000, $points * max(1, count($watchlistIds)) * 3)), \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $bucket = [];

        foreach ($rows as $row) {
            $watchId = (int) ($row['watchlist_id'] ?? 0);
            if ($watchId <= 0) {
                continue;
            }
            if (!isset($bucket[$watchId])) {
                $bucket[$watchId] = [];
            }
            if (count($bucket[$watchId]) >= $points) {
                continue;
            }

            $ret = $this->toFloat($row['return_5d_pct'] ?? null);
            if ($ret === null) {
                $ret = $this->toFloat($row['return_3d_pct'] ?? null);
            }
            if ($ret === null) {
                $ret = $this->toFloat($row['return_1d_pct'] ?? null);
            }
            if ($ret === null) {
                continue;
            }

            $bucket[$watchId][] = round($ret, 4);
        }

        foreach ($bucket as &$series) {
            $series = array_reverse($series);
        }
        unset($series);

        return $bucket;
    }

    /**
     * @param array<int, int> $ids
     * @return array{0:string,1:array<string,mixed>}
     */
    private function buildIdBindings(array $ids, string $prefix): array
    {
        $in = [];
        $bindings = [];
        foreach (array_values($ids) as $idx => $id) {
            $key = $prefix . $idx;
            $in[] = ':' . $key;
            $bindings[$key] = (int) $id;
        }
        return [implode(', ', $in), $bindings];
    }

    private function inferExpectedDirection(string $zone, string $advice): string
    {
        $zone = strtolower(trim($zone));
        if ($zone === 'support_zone') {
            return 'up';
        }
        if ($zone === 'resistance_zone') {
            return 'down';
        }

        $text = trim($advice);
        if ($text === '') {
            return 'neutral';
        }

        $upKeywords = ['低吸', '跟随', '试错', '承接', '买', '加仓', '看多', '关注'];
        $downKeywords = ['观望', '减仓', '回避', '谨慎', '止损', '不追', '高抛', '等待'];

        $upHit = $this->containsAny($text, $upKeywords);
        $downHit = $this->containsAny($text, $downKeywords);

        if ($upHit && !$downHit) {
            return 'up';
        }
        if ($downHit && !$upHit) {
            return 'down';
        }
        return 'neutral';
    }

    private function containsAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $word) {
            if ($word === '') {
                continue;
            }
            if (function_exists('mb_stripos')) {
                if (mb_stripos($text, $word) !== false) {
                    return true;
                }
            } elseif (stripos($text, $word) !== false) {
                return true;
            }
        }
        return false;
    }

    private function judgeAccuracy(string $expectedDirection, ?float $ret1d, ?float $ret3d, ?float $ret5d): ?int
    {
        $anchor = $ret3d ?? $ret5d ?? $ret1d;
        if ($anchor === null) {
            return null;
        }

        return match ($expectedDirection) {
            'up' => $anchor > 0.5 ? 1 : 0,
            'down' => $anchor <= 0.0 ? 1 : 0,
            default => abs($anchor) <= 1.5 ? 1 : 0,
        };
    }

    private function evaluationNote(string $expectedDirection, ?int $isAccurate, ?float $ret3d, ?float $ret5d, ?float $ret1d): string
    {
        $anchor = $ret3d ?? $ret5d ?? $ret1d;
        if ($anchor === null) {
            return '样本不足，等待后续行情';
        }

        $dirText = match ($expectedDirection) {
            'up' => '偏多预期',
            'down' => '偏空预期',
            default => '中性预期',
        };
        $accText = $isAccurate === null ? '暂未判定' : ($isAccurate === 1 ? '判断有效' : '判断偏差');

        return sprintf('%s，%s，阶段收益 %.2f%%', $dirText, $accText, $anchor);
    }

    /**
     * @param array<int, array{ts:int,price:float,time:string}> $quotes
     */
    private function findFirstPriceAtOrAfter(array $quotes, int $targetTs, int $maxLagSec): ?float
    {
        foreach ($quotes as $q) {
            $ts = (int) ($q['ts'] ?? 0);
            if ($ts < $targetTs) {
                continue;
            }
            if (($ts - $targetTs) > $maxLagSec) {
                return null;
            }
            return (float) ($q['price'] ?? 0.0);
        }
        return null;
    }

    /**
     * @param array<int, array{ts:int,price:float,time:string}> $quotes
     */
    private function findNearestPriceAround(array $quotes, int $targetTs, int $windowSec): ?float
    {
        $best = null;
        $bestDiff = PHP_INT_MAX;

        foreach ($quotes as $q) {
            $ts = (int) ($q['ts'] ?? 0);
            $diff = abs($ts - $targetTs);
            if ($diff > $windowSec) {
                continue;
            }
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $best = (float) ($q['price'] ?? 0.0);
            }
        }

        return ($best !== null && $best > 0) ? $best : null;
    }

    private function calcReturnPct(float $base, ?float $target): ?float
    {
        if ($base <= 0 || $target === null || $target <= 0) {
            return null;
        }
        return (($target / $base) - 1.0) * 100.0;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }

    private function round4(float $value): float
    {
        return round($value, 4);
    }

    private function roundNullable4(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }
        return round($value, 4);
    }
}
