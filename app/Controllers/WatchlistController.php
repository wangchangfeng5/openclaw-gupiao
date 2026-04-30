<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Services\AuditService;
use App\Services\SymbolInsightService;
use App\Services\WatchlistRankingService;
use App\Services\WatchlistReviewService;

final class WatchlistController extends BaseController
{
    private SymbolInsightService $insight;
    private WatchlistRankingService $ranking;
    private WatchlistReviewService $review;
    private ?bool $hasInsightsLatestTable = null;

    public function __construct()
    {
        $this->insight = new SymbolInsightService();
        $this->ranking = new WatchlistRankingService();
        $this->review = new WatchlistReviewService();
    }

    public function index(): void
    {
        $userId = $this->userId();
        if ($this->hasWatchlistInsightsLatestTable()) {
            $stmt = Database::connection()->prepare(
                'SELECT w.*,
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
                 WHERE w.user_id = :user_id
                 ORDER BY w.priority ASC, w.updated_at DESC'
            );
            $stmt->execute([
                'user_id_insight' => $userId,
                'user_id' => $userId,
            ]);
        } else {
            $stmt = Database::connection()->prepare(
                'SELECT w.*,
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
                 WHERE w.user_id = :user_id
                 ORDER BY w.priority ASC, w.updated_at DESC'
            );
            $stmt->execute([
                'user_id_latest' => $userId,
                'user_id_insight' => $userId,
                'user_id' => $userId,
            ]);
        }
        $rows = $stmt->fetchAll() ?: [];
        $rows = $this->ranking->enrichRows($rows);
        $rows = $this->review->enrichRows($rows, $userId);

        $this->ok(['watchlist' => $rows]);
    }

    public function store(): void
    {
        $userId = $this->userId();
        $body = $this->body();
        $symbol = strtoupper(trim((string) ($body['symbol'] ?? '')));
        if ($symbol === '') {
            $this->fail('symbol is required', 422);
            return;
        }

        $market = (string) ($body['market'] ?? 'A_STOCK_MAIN');
        $name = (string) ($body['name'] ?? '');
        $thesis = (string) ($body['thesis'] ?? '');
        $priority = (int) ($body['priority'] ?? 50);
        $status = (string) ($body['status'] ?? 'active');

        $stmt = Database::connection()->prepare(
            'INSERT INTO watchlist (user_id, symbol, market, name, thesis, priority, status, created_at, updated_at)
             VALUES (:user_id, :symbol, :market, :name, :thesis, :priority, :status, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                thesis = VALUES(thesis),
                priority = VALUES(priority),
                status = VALUES(status),
                updated_at = NOW()'
        );

        $stmt->execute([
            'user_id' => $userId,
            'symbol' => $symbol,
            'market' => $market,
            'name' => $name,
            'thesis' => $thesis,
            'priority' => $priority,
            'status' => $status,
        ]);

        $id = (int) Database::connection()->lastInsertId();
        if ($id <= 0) {
            $idStmt = Database::connection()->prepare('SELECT id FROM watchlist WHERE user_id = :user_id AND symbol = :symbol AND market = :market LIMIT 1');
            $idStmt->execute([
                'user_id' => $userId,
                'symbol' => $symbol,
                'market' => $market,
            ]);
            $id = (int) ($idStmt->fetchColumn() ?: 0);
        }

        AuditService::log('watchlist.store', 'watchlist', (string) $id, $body);

        $this->ok(['id' => $id, 'symbol' => $symbol], 201);
    }

    public function update(array $params): void
    {
        $userId = $this->userId();
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            $this->fail('invalid watchlist id', 422);
            return;
        }

        $stmt = Database::connection()->prepare('SELECT * FROM watchlist WHERE id = :id AND user_id = :user_id LIMIT 1');
        $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
        ]);
        $row = $stmt->fetch();

        if (!$row) {
            $this->fail('watchlist not found', 404);
            return;
        }

        $body = $this->body();
        $symbol = strtoupper(trim((string) ($body['symbol'] ?? $row['symbol'])));
        if ($symbol === '') {
            $this->fail('symbol is required', 422);
            return;
        }

        $update = Database::connection()->prepare(
            'UPDATE watchlist
             SET symbol = :symbol,
                 market = :market,
                 name = :name,
                 thesis = :thesis,
                 priority = :priority,
                 status = :status,
                 updated_at = NOW()
             WHERE id = :id AND user_id = :user_id'
        );

        $update->execute([
            'id' => $id,
            'user_id' => $userId,
            'symbol' => $symbol,
            'market' => (string) ($body['market'] ?? $row['market']),
            'name' => (string) ($body['name'] ?? $row['name']),
            'thesis' => (string) ($body['thesis'] ?? $row['thesis']),
            'priority' => (int) ($body['priority'] ?? $row['priority']),
            'status' => (string) ($body['status'] ?? $row['status']),
        ]);

        AuditService::log('watchlist.update', 'watchlist', (string) $id, $body);
        $this->ok(['id' => $id]);
    }

    public function destroy(array $params): void
    {
        $userId = $this->userId();
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            $this->fail('invalid watchlist id', 422);
            return;
        }

        $stmt = Database::connection()->prepare('DELETE FROM watchlist WHERE id = :id AND user_id = :user_id');
        $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
        ]);

        AuditService::log('watchlist.delete', 'watchlist', (string) $id, []);
        $this->ok(['id' => $id]);
    }

    public function analyze(array $params): void
    {
        $userId = $this->userId();
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            $this->fail('invalid watchlist id', 422);
            return;
        }

        $item = $this->findWatchlist($id, $userId);
        if ($item === null) {
            $this->fail('watchlist not found', 404);
            return;
        }

        $analysis = $this->insight->analyze(
            (string) $item['symbol'],
            (string) $item['market'],
            null,
            null,
            null,
            'watchlist',
            $userId
        );
        $analysisId = $this->insight->saveWatchlistInsight($id, $analysis, $userId);

        AuditService::log('watchlist.analyze', 'watchlist', (string) $id, [
            'analysis_id' => $analysisId,
        ]);

        $reviewStats = $this->review->syncForWatchlist($id, $userId, 42);

        $this->ok([
            'id' => $id,
            'analysis_id' => $analysisId,
            'analysis' => $analysis,
            'review' => $reviewStats,
        ]);
    }

    public function analyzeAll(): void
    {
        $userId = $this->userId();
        $body = $this->body();
        $cursor = max(0, (int) ($body['cursor'] ?? $this->query('cursor', 0)));
        $limit = max(5, min(80, (int) ($body['limit'] ?? $this->query('limit', 30))));

        $totalStmt = Database::connection()->prepare(
            "SELECT COUNT(*)
             FROM watchlist
             WHERE user_id = :user_id AND status = 'active'"
        );
        $totalStmt->execute(['user_id' => $userId]);
        $totalActive = (int) ($totalStmt->fetchColumn() ?: 0);

        $stmt = Database::connection()->prepare(
            "SELECT id, symbol, market
             FROM watchlist
             WHERE user_id = :user_id
               AND status = 'active'
               AND id > :cursor
             ORDER BY id ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':cursor', $cursor, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];

        $ok = 0;
        $errors = [];
        $nextCursor = $cursor;
        $processedWatchlistIds = [];

        foreach ($rows as $row) {
            try {
                $analysis = $this->insight->analyze(
                    (string) $row['symbol'],
                    (string) $row['market'],
                    null,
                    null,
                    null,
                    'watchlist',
                    $userId
                );
                $this->insight->saveWatchlistInsight((int) $row['id'], $analysis, $userId);
                $ok++;
                $nextCursor = max($nextCursor, (int) ($row['id'] ?? 0));
                $processedWatchlistIds[] = (int) ($row['id'] ?? 0);
            } catch (\Throwable $e) {
                $errors[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'symbol' => (string) ($row['symbol'] ?? ''),
                    'error' => $e->getMessage(),
                ];
                $nextCursor = max($nextCursor, (int) ($row['id'] ?? 0));
            }
        }

        $remainStmt = Database::connection()->prepare(
            "SELECT COUNT(*)
             FROM watchlist
             WHERE user_id = :user_id
               AND status = 'active'
               AND id > :cursor"
        );
        $remainStmt->execute([
            'user_id' => $userId,
            'cursor' => $nextCursor,
        ]);
        $remaining = (int) ($remainStmt->fetchColumn() ?: 0);
        $hasMore = $remaining > 0;

        AuditService::log('watchlist.analyze_all', 'watchlist', null, [
            'cursor' => $cursor,
            'limit' => $limit,
            'batch_total' => count($rows),
            'success' => $ok,
            'errors' => count($errors),
            'remaining' => $remaining,
        ]);

        $reviewStats = ['watchlists' => 0, 'insights' => 0, 'upserts' => 0];
        if ($processedWatchlistIds !== []) {
            $reviewStats = $this->review->syncForWatchlists(array_values(array_unique($processedWatchlistIds)), $userId, 28);
        }

        $this->ok([
            'total_active' => $totalActive,
            'batch_total' => count($rows),
            'success' => $ok,
            'failed' => count($errors),
            'cursor' => $cursor,
            'next_cursor' => $nextCursor,
            'remaining' => $remaining,
            'has_more' => $hasMore,
            'errors' => array_slice($errors, 0, 30),
            'review' => $reviewStats,
        ]);
    }

    public function insights(array $params): void
    {
        $userId = $this->userId();
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            $this->fail('invalid watchlist id', 422);
            return;
        }

        if ($this->findWatchlist($id, $userId) === null) {
            $this->fail('watchlist not found', 404);
            return;
        }

        $limit = max(1, min(200, (int) $this->query('limit', 30)));
        $stmt = Database::connection()->prepare(
            'SELECT *
             FROM watchlist_insights
             WHERE user_id = :user_id AND watchlist_id = :watchlist_id
             ORDER BY analyzed_at DESC, id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':watchlist_id', $id, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $this->ok([
            'watchlist_id' => $id,
            'insights' => $stmt->fetchAll() ?: [],
        ]);
    }

    public function detail(array $params): void
    {
        $userId = $this->userId();
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            $this->fail('invalid watchlist id', 422);
            return;
        }

        $row = $this->fetchWatchlistRow($id, $userId);
        if ($row === null) {
            $this->fail('watchlist not found', 404);
            return;
        }

        $enrichedRows = $this->ranking->enrichRows([$row]);
        $enrichedRows = $this->review->enrichRows($enrichedRows, $userId, 18);
        $overview = $enrichedRows[0] ?? $row;

        $symbol = strtoupper(trim((string) ($overview['symbol'] ?? '')));
        $market = (string) ($overview['market'] ?? 'A_STOCK_MAIN');
        $name = trim((string) ($overview['name'] ?? ''));
        $sectorName = trim((string) ($overview['sector_name'] ?? ''));

        $limit = max(30, min(600, (int) $this->query('limit', 180)));
        $quoteSeries = $this->loadQuoteSeries($symbol, $market, $limit);
        $sectorContext = $this->loadSectorContext($overview, $userId);
        $relatedNews = $this->loadRelatedNews($symbol, $name, $sectorName, 16, 72);
        $recentSuggestions = $this->loadRecentSuggestions($userId, $symbol, 6);
        $reviewHistory = $this->review->history($id, $userId, 16);
        $reviewOverview = $this->review->overview($id, $userId);

        $this->ok([
            'overview' => $overview,
            'quote_series' => $quoteSeries,
            'sector_context' => $sectorContext,
            'related_news' => $relatedNews,
            'recent_suggestions' => $recentSuggestions,
            'review_overview' => $reviewOverview,
            'review_history' => $reviewHistory,
            'generated_at' => now_sql(),
        ]);
    }

    public function review(array $params): void
    {
        $userId = $this->userId();
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            $this->fail('invalid watchlist id', 422);
            return;
        }

        if ($this->findWatchlist($id, $userId) === null) {
            $this->fail('watchlist not found', 404);
            return;
        }

        $summary = $this->review->syncForWatchlist($id, $userId, 56);
        $snapshot = $this->review->snapshotForUser($userId, 'manual', 360);
        $overview = $this->review->overview($id, $userId);
        $history = $this->review->history($id, $userId, 20);

        AuditService::log('watchlist.review', 'watchlist', (string) $id, [
            'summary' => $summary,
            'snapshot' => $snapshot,
        ]);

        $this->ok([
            'id' => $id,
            'summary' => $summary,
            'snapshot' => $snapshot,
            'overview' => $overview,
            'history' => $history,
        ]);
    }

    public function reviewAll(): void
    {
        $userId = $this->userId();
        $body = $this->body();
        $watchlistLimit = max(20, min(500, (int) ($body['watchlist_limit'] ?? $this->query('watchlist_limit', 220))));
        $insightLimit = max(8, min(120, (int) ($body['insight_limit'] ?? $this->query('insight_limit', 28))));

        $summary = $this->review->syncForAllActive($userId, $watchlistLimit, $insightLimit);
        $snapshot = $this->review->snapshotForUser($userId, 'manual', max(240, $watchlistLimit));

        AuditService::log('watchlist.review_all', 'watchlist', null, [
            'watchlist_limit' => $watchlistLimit,
            'insight_limit' => $insightLimit,
            'summary' => $summary,
            'snapshot' => $snapshot,
        ]);

        $this->ok([
            'summary' => $summary,
            'snapshot' => $snapshot,
        ]);
    }

    public function reviews(array $params): void
    {
        $userId = $this->userId();
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            $this->fail('invalid watchlist id', 422);
            return;
        }
        if ($this->findWatchlist($id, $userId) === null) {
            $this->fail('watchlist not found', 404);
            return;
        }

        $limit = max(1, min(120, (int) $this->query('limit', 20)));
        $history = $this->review->history($id, $userId, $limit);
        $overview = $this->review->overview($id, $userId);

        $this->ok([
            'watchlist_id' => $id,
            'overview' => $overview,
            'reviews' => $history,
        ]);
    }

    public function snapshot(array $params = []): void
    {
        $userId = $this->userId();
        $body = $this->body();
        $slot = (string) ($body['slot'] ?? $this->query('slot', 'manual'));
        $watchlistLimit = max(40, min(600, (int) ($body['watchlist_limit'] ?? $this->query('watchlist_limit', 320))));
        $insightLimit = max(8, min(120, (int) ($body['insight_limit'] ?? $this->query('insight_limit', 36))));

        $summary = $this->review->syncForAllActive($userId, $watchlistLimit, $insightLimit);
        $snapshot = $this->review->snapshotForUser($userId, $slot, $watchlistLimit);
        $latest = $this->review->latestGlobalSnapshot($userId);

        AuditService::log('watchlist.review_snapshot', 'watchlist', null, [
            'slot' => $slot,
            'summary' => $summary,
            'snapshot' => $snapshot,
        ]);

        $this->ok([
            'slot' => $slot,
            'summary' => $summary,
            'snapshot' => $snapshot,
            'global_latest' => $latest,
        ]);
    }

    public function globalSnapshots(): void
    {
        $userId = $this->userId();
        $days = max(3, min(180, (int) $this->query('days', 30)));
        $series = $this->review->globalSnapshots($userId, $days);
        $latest = $this->review->latestGlobalSnapshot($userId);

        $this->ok([
            'days' => $days,
            'latest' => $latest,
            'series' => $series,
        ]);
    }

    public function symbolSnapshots(array $params): void
    {
        $userId = $this->userId();
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            $this->fail('invalid watchlist id', 422);
            return;
        }
        if ($this->findWatchlist($id, $userId) === null) {
            $this->fail('watchlist not found', 404);
            return;
        }

        $days = max(3, min(180, (int) $this->query('days', 30)));
        $series = $this->review->watchlistSnapshotSeries($userId, $id, $days);

        $this->ok([
            'watchlist_id' => $id,
            'days' => $days,
            'series' => $series,
        ]);
    }

    private function findWatchlist(int $id, int $userId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM watchlist WHERE id = :id AND user_id = :user_id LIMIT 1');
        $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
        ]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function fetchWatchlistRow(int $id, int $userId): ?array
    {
        if ($this->hasWatchlistInsightsLatestTable()) {
            $stmt = Database::connection()->prepare(
                'SELECT w.*,
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
                 WHERE w.id = :id AND w.user_id = :user_id
                 LIMIT 1'
            );
            $stmt->execute([
                'user_id_insight' => $userId,
                'id' => $id,
                'user_id' => $userId,
            ]);
        } else {
            $stmt = Database::connection()->prepare(
                'SELECT w.*,
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
                 WHERE w.id = :id AND w.user_id = :user_id
                 LIMIT 1'
            );
            $stmt->execute([
                'user_id_latest' => $userId,
                'user_id_insight' => $userId,
                'id' => $id,
                'user_id' => $userId,
            ]);
        }

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
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

        $recommended = $this->loadSectorWatchlistPicks($userId, $sectorName, $symbol, 8);

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
            'recommended' => $recommended,
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
