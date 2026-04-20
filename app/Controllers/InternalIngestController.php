<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Services\AlertService;
use App\Services\DataQualityService;
use App\Services\IngestTargetService;
use App\Services\QuoteSyncService;
use App\Services\SuggestionSyncService;

final class InternalIngestController extends BaseController
{
    public function ingestSuggestions(): void
    {
        $body = $this->body();
        $ingestUserId = $this->resolveIngestUserId($body);
        $items = $body['items'] ?? [];
        if (!is_array($items)) {
            $this->fail('items must be array', 422);
            return;
        }

        $pdo = Database::connection();
        $insert = $pdo->prepare(
            'INSERT INTO openclaw_suggestions (
                user_id, session_id, message_id, source_file, suggested_at, content,
                symbols_json, tags_json, confidence, ingest_source, status, created_at, updated_at
            ) VALUES (
                :user_id, :session_id, :message_id, :source_file, :suggested_at, :content,
                :symbols_json, :tags_json, :confidence, :ingest_source, :status, NOW(), NOW()
            )'
        );

        $added = 0;
        $watchlistAutoAdded = 0;
        $positionsUpdated = 0;
        $syncNotesCreated = 0;
        $watchlistTouched = 0;
        $suggestionSync = new SuggestionSyncService();
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $messageId = (string) ($item['message_id'] ?? '');
            $content = trim((string) ($item['content'] ?? ''));
            if ($messageId === '' || $content === '') {
                continue;
            }

            $exists = $pdo->prepare('SELECT id FROM openclaw_suggestions WHERE user_id <=> :user_id AND message_id = :message_id LIMIT 1');
            $exists->execute([
                'user_id' => $ingestUserId,
                'message_id' => $messageId,
            ]);
            if ($exists->fetch()) {
                continue;
            }

            $insert->execute([
                'user_id' => $ingestUserId,
                'session_id' => (string) ($item['session_id'] ?? ''),
                'message_id' => $messageId,
                'source_file' => (string) ($item['source_file'] ?? ''),
                'suggested_at' => (string) ($item['suggested_at'] ?? now_sql()),
                'content' => $content,
                'symbols_json' => json_encode($item['symbols'] ?? [], JSON_UNESCAPED_UNICODE),
                'tags_json' => json_encode($item['tags'] ?? ['openclaw'], JSON_UNESCAPED_UNICODE),
                'confidence' => (float) ($item['confidence'] ?? 70),
                'ingest_source' => (string) ($item['ingest_source'] ?? 'auto'),
                'status' => 'new',
            ]);

            $suggestionId = (int) $pdo->lastInsertId();
            AlertService::create('new_suggestion', 'OpenClaw new suggestion', mb_substr($content, 0, 100), 'info', 'suggestion', (string) $suggestionId, $ingestUserId);

            $symbols = $item['symbols'] ?? [];
            if (is_array($symbols)) {
                $watchlistAutoAdded += $this->upsertWatchlistFromSuggestion($ingestUserId, $symbols, $content);

                $syncResult = $suggestionSync->applySuggestion($ingestUserId, $content, $symbols, $suggestionId);
                $positionsUpdated += (int) ($syncResult['positions_updated'] ?? 0);
                $syncNotesCreated += (int) ($syncResult['notes_created'] ?? 0);
                $watchlistTouched += (int) ($syncResult['watchlist_touched'] ?? 0);
            }
            $added++;
        }

        $consumer = (string) ($body['consumer_name'] ?? 'advice_ingestor');
        $this->upsertOffset($consumer, (string) ($body['last_file'] ?? ''), (int) ($body['last_offset'] ?? 0), [
            'session_id' => $body['session_id'] ?? null,
            'user_id' => $ingestUserId,
        ]);

        $this->ok([
            'added' => $added,
            'watchlist_auto_added' => $watchlistAutoAdded,
            'positions_updated' => $positionsUpdated,
            'sync_notes_created' => $syncNotesCreated,
            'watchlist_touched' => $watchlistTouched,
        ]);
    }

    public function ingestSectors(): void
    {
        $body = $this->body();
        $items = $body['items'] ?? [];
        if (!is_array($items)) {
            $this->fail('items must be array', 422);
            return;
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO sector_strength (
                sector_code, sector_name, strength_score, change_pct, leading_symbol,
                active_count, sample_time, source, raw_json, created_at
            ) VALUES (
                :sector_code, :sector_name, :strength_score, :change_pct, :leading_symbol,
                :active_count, :sample_time, :source, :raw_json, NOW()
            )'
        );

        $added = 0;
        foreach ($items as $item) {
            if (!is_array($item) || trim((string) ($item['sector_name'] ?? '')) === '') {
                continue;
            }

            $stmt->execute([
                'sector_code' => (string) ($item['sector_code'] ?? ''),
                'sector_name' => (string) $item['sector_name'],
                'strength_score' => (float) ($item['strength_score'] ?? 0),
                'change_pct' => $item['change_pct'] ?? null,
                'leading_symbol' => (string) ($item['leading_symbol'] ?? ''),
                'active_count' => $item['active_count'] ?? null,
                'sample_time' => (string) ($item['sample_time'] ?? now_sql()),
                'source' => (string) ($item['source'] ?? 'market_collector'),
                'raw_json' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $added++;
        }

        $this->ok(['added' => $added]);
    }

    public function ingestNews(): void
    {
        $body = $this->body();
        $items = $body['items'] ?? [];
        if (!is_array($items)) {
            $this->fail('items must be array', 422);
            return;
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO news_feed (
                title, summary, url, source, category, sentiment,
                tags_json, published_at, dedupe_hash, raw_json, created_at
            ) VALUES (
                :title, :summary, :url, :source, :category, :sentiment,
                :tags_json, :published_at, :dedupe_hash, :raw_json, NOW()
            )'
        );

        $added = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $hash = (string) ($item['dedupe_hash'] ?? hash('sha256', $title . '|' . (string) ($item['url'] ?? '')));

            $exists = Database::connection()->prepare('SELECT id FROM news_feed WHERE dedupe_hash = :dedupe_hash LIMIT 1');
            $exists->execute(['dedupe_hash' => $hash]);
            if ($exists->fetch()) {
                continue;
            }

            $stmt->execute([
                'title' => $title,
                'summary' => (string) ($item['summary'] ?? ''),
                'url' => (string) ($item['url'] ?? ''),
                'source' => (string) ($item['source'] ?? 'market_collector'),
                'category' => (string) ($item['category'] ?? 'general'),
                'sentiment' => (string) ($item['sentiment'] ?? 'neutral'),
                'tags_json' => json_encode($item['tags'] ?? [], JSON_UNESCAPED_UNICODE),
                'published_at' => (string) ($item['published_at'] ?? now_sql()),
                'dedupe_hash' => $hash,
                'raw_json' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $added++;
        }

        $this->ok(['added' => $added]);
    }

    public function ingestQuotes(): void
    {
        $body = $this->body();
        $items = $body['items'] ?? [];
        if (!is_array($items)) {
            $this->fail('items must be array', 422);
            return;
        }
        $validQuotes = [];

        $stmt = Database::connection()->prepare(
            'INSERT INTO market_quotes (
                symbol, market, name, sector_name, trend_direction, price, change_pct, volume, turnover, quote_time, source, raw_json, created_at
            ) VALUES (
                :symbol, :market, :name, :sector_name, :trend_direction, :price, :change_pct, :volume, :turnover, :quote_time, :source, :raw_json, NOW()
            )'
        );

        $added = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $symbol = trim((string) ($item['symbol'] ?? ''));
            if ($symbol === '') {
                continue;
            }

            $stmt->execute([
                'symbol' => $symbol,
                'market' => (string) ($item['market'] ?? 'A_STOCK_MAIN'),
                'name' => trim((string) ($item['name'] ?? '')),
                'sector_name' => trim((string) ($item['sector_name'] ?? '')),
                'trend_direction' => trim((string) ($item['trend_direction'] ?? '')),
                'price' => $item['price'] ?? null,
                'change_pct' => $item['change_pct'] ?? null,
                'volume' => $item['volume'] ?? null,
                'turnover' => $item['turnover'] ?? null,
                'quote_time' => (string) ($item['quote_time'] ?? now_sql()),
                'source' => (string) ($item['source'] ?? 'market_collector'),
                'raw_json' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $added++;
            $validQuotes[] = [
                'symbol' => strtoupper($symbol),
                'market' => (string) ($item['market'] ?? 'A_STOCK_MAIN'),
                'name' => trim((string) ($item['name'] ?? '')),
                'sector_name' => trim((string) ($item['sector_name'] ?? '')),
                'trend_direction' => trim((string) ($item['trend_direction'] ?? '')),
                'change_pct' => $item['change_pct'] ?? null,
                'price' => $item['price'] ?? null,
            ];
        }

        $syncSummary = (new QuoteSyncService())->sync($validQuotes);

        $this->ok([
            'added' => $added,
            'sync' => $syncSummary,
        ]);
    }

    public function ingestCloseRankings(): void
    {
        $body = $this->body();
        $items = $body['items'] ?? [];
        if (!is_array($items)) {
            $this->fail('items must be array', 422);
            return;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO market_close_rankings (
                trade_date, rank_type, rank_no, symbol, market, name, sector_name,
                change_1d_pct, change_3d_pct, change_5d_pct, change_10d_pct,
                net_main_inflow, net_main_inflow_pct, flow_direction, source, snapshot_time, raw_json, created_at, updated_at
            ) VALUES (
                :trade_date, :rank_type, :rank_no, :symbol, :market, :name, :sector_name,
                :change_1d_pct, :change_3d_pct, :change_5d_pct, :change_10d_pct,
                :net_main_inflow, :net_main_inflow_pct, :flow_direction, :source, :snapshot_time, :raw_json, NOW(), NOW()
            )
            ON DUPLICATE KEY UPDATE
                rank_no = VALUES(rank_no),
                market = VALUES(market),
                name = VALUES(name),
                sector_name = VALUES(sector_name),
                change_1d_pct = VALUES(change_1d_pct),
                change_3d_pct = VALUES(change_3d_pct),
                change_5d_pct = VALUES(change_5d_pct),
                change_10d_pct = VALUES(change_10d_pct),
                net_main_inflow = VALUES(net_main_inflow),
                net_main_inflow_pct = VALUES(net_main_inflow_pct),
                flow_direction = VALUES(flow_direction),
                source = VALUES(source),
                snapshot_time = VALUES(snapshot_time),
                raw_json = VALUES(raw_json),
                updated_at = NOW()'
        );

        $upserted = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $rankType = strtolower(trim((string) ($item['rank_type'] ?? '')));
            if (!in_array($rankType, ['strong', 'moneyflow'], true)) {
                continue;
            }

            $symbol = strtoupper(trim((string) ($item['symbol'] ?? '')));
            if ($symbol === '') {
                continue;
            }

            $tradeDate = trim((string) ($item['trade_date'] ?? ''));
            if ($tradeDate === '') {
                $tradeDate = date('Y-m-d');
            }

            $stmt->execute([
                'trade_date' => $tradeDate,
                'rank_type' => $rankType,
                'rank_no' => max(1, (int) ($item['rank_no'] ?? 0)),
                'symbol' => $symbol,
                'market' => (string) ($item['market'] ?? 'A_STOCK_MAIN'),
                'name' => trim((string) ($item['name'] ?? '')),
                'sector_name' => trim((string) ($item['sector_name'] ?? '')),
                'change_1d_pct' => $item['change_1d_pct'] ?? null,
                'change_3d_pct' => $item['change_3d_pct'] ?? null,
                'change_5d_pct' => $item['change_5d_pct'] ?? null,
                'change_10d_pct' => $item['change_10d_pct'] ?? null,
                'net_main_inflow' => $item['net_main_inflow'] ?? null,
                'net_main_inflow_pct' => $item['net_main_inflow_pct'] ?? null,
                'flow_direction' => trim((string) ($item['flow_direction'] ?? '')),
                'source' => (string) ($item['source'] ?? 'market_collector'),
                'snapshot_time' => (string) ($item['snapshot_time'] ?? now_sql()),
                'raw_json' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $upserted++;
        }

        $this->ok(['upserted' => $upserted]);
    }

    public function qualityLog(): void
    {
        $body = $this->body();
        DataQualityService::log(
            (string) ($body['job_name'] ?? 'unknown'),
            (string) ($body['status'] ?? 'info'),
            (string) ($body['message'] ?? ''),
            (int) ($body['retry_count'] ?? 0),
            is_array($body['context'] ?? null) ? $body['context'] : []
        );

        $this->ok(['logged' => true]);
    }

    public function marketSymbols(): void
    {
        $limit = max(10, min(500, (int) $this->query('limit', 180)));
        $maxSuggestionRows = max(20, min(1000, (int) $this->query('suggestions', 300)));

        $pool = [];

        $this->collectFromQuery(
            "SELECT symbol, market, updated_at
             FROM positions
             WHERE status IN ('holding', 'watching')
             ORDER BY updated_at DESC",
            'position',
            3,
            $pool
        );

        $this->collectFromQuery(
            "SELECT symbol, market, updated_at
             FROM watchlist
             WHERE status = 'active'
             ORDER BY priority ASC, updated_at DESC",
            'watchlist',
            2,
            $pool
        );

        $suggestionStmt = Database::connection()->prepare(
            'SELECT symbols_json, suggested_at
             FROM openclaw_suggestions
             ORDER BY suggested_at DESC, id DESC
             LIMIT :limit'
        );
        $suggestionStmt->bindValue(':limit', $maxSuggestionRows, \PDO::PARAM_INT);
        $suggestionStmt->execute();
        $suggestions = $suggestionStmt->fetchAll() ?: [];

        foreach ($suggestions as $row) {
            $symbolsRaw = $row['symbols_json'] ?? null;
            $symbols = [];
            if (is_string($symbolsRaw) && $symbolsRaw !== '') {
                $decoded = json_decode($symbolsRaw, true);
                if (is_array($decoded)) {
                    $symbols = $decoded;
                }
            } elseif (is_array($symbolsRaw)) {
                $symbols = $symbolsRaw;
            }

            foreach ($symbols as $item) {
                $symbol = strtoupper(trim((string) $item));
                if (!$this->isLikelyAStockSymbol($symbol)) {
                    continue;
                }
                $this->touchPool($pool, $symbol, 'A_STOCK_MAIN', 'suggestion', 1, (string) ($row['suggested_at'] ?? now_sql()));
            }
        }

        $rows = array_values($pool);
        usort(
            $rows,
            static function (array $a, array $b): int {
                $aPriority = (int) ($a['priority'] ?? 0);
                $bPriority = (int) ($b['priority'] ?? 0);
                if ($aPriority !== $bPriority) {
                    return $bPriority <=> $aPriority;
                }
                return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
            }
        );

        $rows = array_slice($rows, 0, $limit);

        $this->ok([
            'symbols' => array_map(
                static fn(array $row): array => [
                    'symbol' => (string) ($row['symbol'] ?? ''),
                    'market' => (string) ($row['market'] ?? 'A_STOCK_MAIN'),
                    'priority' => (int) ($row['priority'] ?? 0),
                    'sources' => array_values(array_unique($row['sources'] ?? [])),
                    'updated_at' => (string) ($row['updated_at'] ?? now_sql()),
                ],
                $rows
            ),
            'total' => count($rows),
        ]);
    }

    private function upsertOffset(string $consumerName, string $lastFile, int $lastOffset, array $extra): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO ingest_offsets (consumer_name, last_file, last_offset, last_checkpoint_at, extra_json, created_at, updated_at)
             VALUES (:consumer_name, :last_file, :last_offset, NOW(), :extra_json, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                last_file = VALUES(last_file),
                last_offset = VALUES(last_offset),
                last_checkpoint_at = NOW(),
                extra_json = VALUES(extra_json),
                updated_at = NOW()'
        );

        $stmt->execute([
            'consumer_name' => $consumerName,
            'last_file' => $lastFile,
            'last_offset' => $lastOffset,
            'extra_json' => json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function resolveIngestUserId(array $body): ?int
    {
        if (isset($body['user_id']) && is_numeric($body['user_id'])) {
            $uid = (int) $body['user_id'];
            if ($uid > 0) {
                return $uid;
            }
        }

        $username = trim((string) ($body['username'] ?? ''));
        if ($username !== '') {
            $byName = $this->findUserIdByUsername($username);
            if ($byName !== null) {
                return $byName;
            }
        }

        $defaultUsername = trim((string) env('INGEST_DEFAULT_USERNAME', (string) env('ADMIN_USERNAME', '')));
        $dynamicTarget = IngestTargetService::getUsername();
        if ($dynamicTarget !== null) {
            $byTarget = $this->findUserIdByUsername($dynamicTarget);
            if ($byTarget !== null) {
                return $byTarget;
            }
        }

        if ($defaultUsername !== '') {
            $byDefaultName = $this->findUserIdByUsername($defaultUsername);
            if ($byDefaultName !== null) {
                return $byDefaultName;
            }
        }

        $stmt = Database::connection()->query('SELECT id FROM users ORDER BY id ASC LIMIT 1');
        $id = $stmt ? (int) ($stmt->fetchColumn() ?: 0) : 0;
        return $id > 0 ? $id : null;
    }

    /**
     * @param array<string, array<string, mixed>> $pool
     */
    private function collectFromQuery(string $sql, string $source, int $priority, array &$pool): void
    {
        $stmt = Database::connection()->query($sql);
        $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
        foreach ($rows as $row) {
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
            $market = (string) ($row['market'] ?? 'A_STOCK_MAIN');
            if (!$this->isSupportedMarketSymbol($symbol, $market)) {
                continue;
            }
            $this->touchPool($pool, $symbol, $market, $source, $priority, (string) ($row['updated_at'] ?? now_sql()));
        }
    }

    /**
     * @param array<string, array<string, mixed>> $pool
     */
    private function touchPool(array &$pool, string $symbol, string $market, string $source, int $priority, string $updatedAt): void
    {
        $key = $symbol . '|' . $market;
        if (!isset($pool[$key])) {
            $pool[$key] = [
                'symbol' => $symbol,
                'market' => $market,
                'priority' => $priority,
                'sources' => [$source],
                'updated_at' => $updatedAt,
            ];
            return;
        }

        $pool[$key]['priority'] = max((int) ($pool[$key]['priority'] ?? 0), $priority);
        $sources = is_array($pool[$key]['sources'] ?? null) ? $pool[$key]['sources'] : [];
        $sources[] = $source;
        $pool[$key]['sources'] = $sources;

        if (strcmp($updatedAt, (string) ($pool[$key]['updated_at'] ?? '')) > 0) {
            $pool[$key]['updated_at'] = $updatedAt;
        }
    }

    private function isSupportedMarketSymbol(string $symbol, string $market): bool
    {
        if ($market === 'A_STOCK_MAIN') {
            return $this->isLikelyAStockSymbol($symbol);
        }

        return false;
    }

    private function findUserIdByUsername(string $username): ?int
    {
        $stmt = Database::connection()->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        return $id > 0 ? $id : null;
    }

    /**
     * @param array<int, mixed> $symbols
     */
    private function upsertWatchlistFromSuggestion(?int $userId, array $symbols, string $content): int
    {
        if ($userId === null || $userId <= 0) {
            return 0;
        }

        $added = 0;
        $stmt = Database::connection()->prepare(
            'INSERT IGNORE INTO watchlist (
                user_id, symbol, market, name, sector_name, trend_direction, thesis, priority, status, created_at, updated_at
             ) VALUES (
                :user_id, :symbol, :market, :name, :sector_name, :trend_direction, :thesis, :priority, :status, NOW(), NOW()
             )
            '
        );

        $excerpt = mb_substr(trim($content), 0, 120);

        foreach ($symbols as $rawSymbol) {
            $symbol = strtoupper(trim((string) $rawSymbol));
            if (!$this->isLikelyAStockSymbol($symbol)) {
                continue;
            }
            $profile = $this->lookupLatestQuoteProfile($symbol, 'A_STOCK_MAIN');

            $stmt->execute([
                'user_id' => $userId,
                'symbol' => $symbol,
                'market' => 'A_STOCK_MAIN',
                'name' => (string) ($profile['name'] ?? ''),
                'sector_name' => (string) ($profile['sector_name'] ?? ''),
                'trend_direction' => (string) ($profile['trend_direction'] ?? ''),
                'thesis' => 'OpenClaw suggestion: ' . $excerpt,
                'priority' => 40,
                'status' => 'active',
            ]);

            if ($stmt->rowCount() > 0) {
                $added++;
            }
        }

        return $added;
    }

    private function isLikelyAStockSymbol(string $symbol): bool
    {
        if (preg_match('/^\d{6}$/', $symbol) !== 1) {
            return false;
        }

        return preg_match('/^(000|001|002|003|300|301|600|601|603|605|688|689|900)/', $symbol) === 1;
    }

    /**
     * @return array{name:string,sector_name:string,trend_direction:string}
     */
    private function lookupLatestQuoteProfile(string $symbol, string $market): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT name, sector_name, trend_direction
             FROM market_quotes
             WHERE symbol = :symbol AND market = :market
             ORDER BY quote_time DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'symbol' => strtoupper($symbol),
            'market' => $market,
        ]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return [
                'name' => '',
                'sector_name' => '',
                'trend_direction' => '',
            ];
        }

        return [
            'name' => trim((string) ($row['name'] ?? '')),
            'sector_name' => trim((string) ($row['sector_name'] ?? '')),
            'trend_direction' => trim((string) ($row['trend_direction'] ?? '')),
        ];
    }
}
