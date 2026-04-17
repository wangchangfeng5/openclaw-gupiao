<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class QuoteSyncService
{
    private const CHANGE_EPSILON = 0.0001;

    private SymbolInsightService $insightService;

    public function __construct()
    {
        $this->insightService = new SymbolInsightService();
    }

    /**
     * @param array<int, array<string, mixed>> $quotes
     * @return array<string, mixed>
     */
    public function sync(array $quotes): array
    {
        $summary = [
            'quotes' => 0,
            'positions_updated' => 0,
            'notes_created' => 0,
            'watchlist_insights' => 0,
            'watchlist_profile_updated' => 0,
            'affected_users' => [],
        ];

        $affectedUsers = [];
        $userStats = [];

        foreach ($quotes as $quote) {
            if (!is_array($quote)) {
                continue;
            }

            $symbol = strtoupper(trim((string) ($quote['symbol'] ?? '')));
            $market = (string) ($quote['market'] ?? 'A_STOCK_MAIN');
            $price = isset($quote['price']) ? (float) $quote['price'] : 0.0;
            $changePct = isset($quote['change_pct']) ? (float) $quote['change_pct'] : null;
            $name = trim((string) ($quote['name'] ?? ''));
            $sectorName = trim((string) ($quote['sector_name'] ?? ''));
            $trendDirection = trim((string) ($quote['trend_direction'] ?? ''));

            if ($symbol === '' || $price <= 0) {
                continue;
            }

            $summary['quotes']++;
            $result = $this->syncOneSymbol($symbol, $market, $price, $changePct, $name, $sectorName, $trendDirection);

            $summary['positions_updated'] += $result['positions_updated'];
            $summary['notes_created'] += $result['notes_created'];
            $summary['watchlist_insights'] += $result['watchlist_insights'];
            $summary['watchlist_profile_updated'] += $result['watchlist_profile_updated'];

            foreach ($result['user_stats'] as $uid => $stats) {
                $id = (int) $uid;
                if ($id <= 0) {
                    continue;
                }

                $affectedUsers[$id] = true;
                if (!isset($userStats[$id])) {
                    $userStats[$id] = [
                        'positions_updated' => 0,
                        'watchlist_insights' => 0,
                    ];
                }

                $userStats[$id]['positions_updated'] += (int) ($stats['positions_updated'] ?? 0);
                $userStats[$id]['watchlist_insights'] += (int) ($stats['watchlist_insights'] ?? 0);
            }
        }

        $userIds = array_map('intval', array_keys($affectedUsers));
        $summary['affected_users'] = $userIds;

        foreach ($userIds as $uid) {
            $stats = $userStats[$uid] ?? ['positions_updated' => 0, 'watchlist_insights' => 0];
            $this->emitBatchAlert(
                $uid,
                (int) ($stats['positions_updated'] ?? 0),
                (int) ($stats['watchlist_insights'] ?? 0)
            );
        }

        return $summary;
    }

    /**
     * @return array{
     *   positions_updated:int,
     *   notes_created:int,
     *   watchlist_insights:int,
     *   watchlist_profile_updated:int,
     *   user_stats:array<int, array{positions_updated:int,watchlist_insights:int}>
     * }
     */
    private function syncOneSymbol(
        string $symbol,
        string $market,
        float $price,
        ?float $changePct = null,
        string $quoteName = '',
        string $sectorName = '',
        string $trendDirection = ''
    ): array
    {
        $pdo = Database::connection();
        $result = [
            'positions_updated' => 0,
            'notes_created' => 0,
            'watchlist_insights' => 0,
            'watchlist_profile_updated' => 0,
            'user_stats' => [],
        ];

        $posStmt = $pdo->prepare(
            "SELECT *
             FROM positions
             WHERE symbol = :symbol
               AND market = :market
               AND status IN ('holding','watching')"
        );
        $posStmt->execute([
            'symbol' => $symbol,
            'market' => $market,
        ]);
        $positions = $posStmt->fetchAll() ?: [];

        $updatePos = $pdo->prepare(
            'UPDATE positions
             SET name = :name,
                 current_price = :current_price,
                 stop_loss_price = :stop_loss_price,
                 take_profit_price = :take_profit_price,
                 operation_advice = :operation_advice,
                 updated_at = NOW()
             WHERE id = :id AND user_id = :user_id'
        );

        $insertNote = $pdo->prepare(
            'INSERT INTO position_notes (
                user_id, position_id, note_type, content, action_result, review_score, created_at
             ) VALUES (
                :user_id, :position_id, :note_type, :content, :action_result, :review_score, NOW()
             )'
        );

        foreach ($positions as $position) {
            $userId = (int) ($position['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            if (!isset($result['user_stats'][$userId])) {
                $result['user_stats'][$userId] = [
                    'positions_updated' => 0,
                    'watchlist_insights' => 0,
                ];
            }

            $analysis = $this->insightService->analyze(
                $symbol,
                $market,
                $price,
                $position['stop_loss_price'] !== null ? (float) $position['stop_loss_price'] : null,
                $position['take_profit_price'] !== null ? (float) $position['take_profit_price'] : null,
                'position',
                $userId
            );

            $newStop = $analysis['stop_loss_price'] ?? null;
            $newTake = $analysis['take_profit_price'] ?? null;
            $newAdvice = (string) ($analysis['action_advice'] ?? '');

            $oldPrice = $position['current_price'] !== null ? (float) $position['current_price'] : null;
            $oldStop = $position['stop_loss_price'] !== null ? (float) $position['stop_loss_price'] : null;
            $oldTake = $position['take_profit_price'] !== null ? (float) $position['take_profit_price'] : null;
            $resolvedPositionName = $quoteName !== ''
                ? $quoteName
                : trim((string) ($position['name'] ?? ''));

            $updatePos->execute([
                'name' => $resolvedPositionName,
                'current_price' => $price,
                'stop_loss_price' => $newStop,
                'take_profit_price' => $newTake,
                'operation_advice' => $newAdvice,
                'id' => (int) $position['id'],
                'user_id' => $userId,
            ]);

            $result['positions_updated']++;
            $result['user_stats'][$userId]['positions_updated']++;

            if ($this->hasMeaningfulChange($oldPrice, $price)
                || $this->hasMeaningfulChange($oldStop, $newStop)
                || $this->hasMeaningfulChange($oldTake, $newTake)) {
                $content = sprintf(
                    'Auto sync %s: price %.4f, SL %s, TP %s',
                    $symbol,
                    $price,
                    $newStop !== null ? number_format((float) $newStop, 4, '.', '') : '-',
                    $newTake !== null ? number_format((float) $newTake, 4, '.', '') : '-'
                );

                $insertNote->execute([
                    'user_id' => $userId,
                    'position_id' => (int) $position['id'],
                    'note_type' => 'auto_sync',
                    'content' => $content,
                    'action_result' => '',
                    'review_score' => null,
                ]);
                $result['notes_created']++;
            }
        }

        $watchStmt = $pdo->prepare(
            "SELECT id, user_id, name, sector_name, trend_direction
             FROM watchlist
             WHERE symbol = :symbol
               AND market = :market
               AND status = 'active'"
        );
        $watchStmt->execute([
            'symbol' => $symbol,
            'market' => $market,
        ]);
        $watchRows = $watchStmt->fetchAll() ?: [];
        $updateWatch = $pdo->prepare(
            'UPDATE watchlist
             SET name = :name,
                 sector_name = :sector_name,
                 trend_direction = :trend_direction,
                 updated_from_quote_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id AND user_id = :user_id'
        );

        foreach ($watchRows as $watchRow) {
            $watchId = (int) ($watchRow['id'] ?? 0);
            $userId = (int) ($watchRow['user_id'] ?? 0);
            if ($watchId <= 0 || $userId <= 0) {
                continue;
            }

            if (!isset($result['user_stats'][$userId])) {
                $result['user_stats'][$userId] = [
                    'positions_updated' => 0,
                    'watchlist_insights' => 0,
                ];
            }

            $analysis = $this->insightService->analyze(
                $symbol,
                $market,
                $price,
                null,
                null,
                'watchlist',
                $userId
            );
            $this->insightService->saveWatchlistInsight($watchId, $analysis, $userId);

            $result['watchlist_insights']++;
            $result['user_stats'][$userId]['watchlist_insights']++;

            $resolvedName = $quoteName !== '' ? $quoteName : trim((string) ($watchRow['name'] ?? ''));
            $resolvedSector = $sectorName !== '' ? $sectorName : trim((string) ($watchRow['sector_name'] ?? ''));
            $resolvedTrend = $trendDirection !== ''
                ? $trendDirection
                : $this->resolveTrendDirection($changePct, trim((string) ($watchRow['trend_direction'] ?? '')));

            $updateWatch->execute([
                'name' => $resolvedName,
                'sector_name' => $resolvedSector,
                'trend_direction' => $resolvedTrend,
                'id' => $watchId,
                'user_id' => $userId,
            ]);
            $result['watchlist_profile_updated'] += $updateWatch->rowCount();
        }

        return $result;
    }

    private function emitBatchAlert(int $userId, int $positionUpdates, int $watchlistInsights): void
    {
        if ($userId <= 0 || ($positionUpdates <= 0 && $watchlistInsights <= 0)) {
            return;
        }

        $check = Database::connection()->prepare(
            "SELECT id
             FROM alerts
             WHERE user_id = :user_id
               AND alert_type = 'quote_sync'
               AND triggered_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
             LIMIT 1"
        );
        $check->execute(['user_id' => $userId]);
        if ($check->fetch()) {
            return;
        }

        $msg = sprintf(
            'Quote sync done: positions %d, watchlist insights %d',
            $positionUpdates,
            $watchlistInsights
        );

        AlertService::create(
            'quote_sync',
            '行情同步完成',
            $msg,
            'info',
            'sync',
            null,
            $userId
        );
    }

    private function hasMeaningfulChange(?float $old, ?float $new): bool
    {
        if ($old === null && $new === null) {
            return false;
        }
        if ($old === null || $new === null) {
            return true;
        }
        return abs($old - $new) >= self::CHANGE_EPSILON;
    }

    private function resolveTrendDirection(?float $changePct, string $fallback): string
    {
        if ($changePct === null) {
            return $fallback;
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
}
