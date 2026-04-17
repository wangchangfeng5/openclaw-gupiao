<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class SuggestionSyncService
{
    /**
     * @param array<int, mixed> $symbols
     * @return array{positions_updated:int,notes_created:int,watchlist_touched:int}
     */
    public function applySuggestion(?int $userId, string $content, array $symbols, int $suggestionId = 0): array
    {
        $result = [
            'positions_updated' => 0,
            'notes_created' => 0,
            'watchlist_touched' => 0,
        ];

        if ($userId === null || $userId <= 0) {
            return $result;
        }

        $normalizedSymbols = $this->normalizeSymbols($symbols);
        if ($normalizedSymbols === []) {
            return $result;
        }

        $stopSpec = $this->extractPriceSpec($content, true);
        $takeSpec = $this->extractPriceSpec($content, false);
        $advice = $this->buildAdviceText($content);

        $pdo = Database::connection();
        $updatePos = $pdo->prepare(
            'UPDATE positions
             SET stop_loss_price = :stop_loss_price,
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

        $touchWatch = $pdo->prepare(
            "UPDATE watchlist
             SET thesis = CASE
                WHEN thesis IS NULL OR thesis = '' THEN :thesis
                ELSE CONCAT(:thesis_prefix, thesis)
             END,
                 updated_at = NOW()
             WHERE user_id = :user_id
               AND symbol = :symbol
               AND status = 'active'"
        );

        foreach ($normalizedSymbols as $symbol) {
            $posStmt = $pdo->prepare(
                "SELECT id, user_id, symbol, market, current_price, stop_loss_price, take_profit_price
                 FROM positions
                 WHERE user_id = :user_id
                   AND symbol = :symbol
                   AND status IN ('holding', 'watching')
                 ORDER BY id DESC"
            );
            $posStmt->execute([
                'user_id' => $userId,
                'symbol' => $symbol,
            ]);
            $rows = $posStmt->fetchAll() ?: [];

            foreach ($rows as $row) {
                $positionId = (int) ($row['id'] ?? 0);
                if ($positionId <= 0) {
                    continue;
                }

                $currentPrice = $this->pickCurrentPrice(
                    $row['current_price'] !== null ? (float) $row['current_price'] : null,
                    $symbol,
                    (string) ($row['market'] ?? 'A_STOCK_MAIN')
                );

                $oldStop = $row['stop_loss_price'] !== null ? (float) $row['stop_loss_price'] : null;
                $oldTake = $row['take_profit_price'] !== null ? (float) $row['take_profit_price'] : null;
                $newStop = $this->resolveSpecPrice($stopSpec, $currentPrice, true) ?? $oldStop;
                $newTake = $this->resolveSpecPrice($takeSpec, $currentPrice, false) ?? $oldTake;

                $updatePos->execute([
                    'stop_loss_price' => $newStop,
                    'take_profit_price' => $newTake,
                    'operation_advice' => $advice,
                    'id' => $positionId,
                    'user_id' => $userId,
                ]);

                $result['positions_updated']++;

                $note = sprintf(
                    'OpenClaw sync #%d %s: stop %s, take %s',
                    $suggestionId,
                    $symbol,
                    $newStop !== null ? number_format((float) $newStop, 4, '.', '') : '-',
                    $newTake !== null ? number_format((float) $newTake, 4, '.', '') : '-'
                );

                $insertNote->execute([
                    'user_id' => $userId,
                    'position_id' => $positionId,
                    'note_type' => 'openclaw_sync',
                    'content' => $note,
                    'action_result' => '',
                    'review_score' => null,
                ]);
                $result['notes_created']++;
            }

            $touchWatch->execute([
                'thesis' => $advice,
                'thesis_prefix' => '[OpenClaw] ',
                'user_id' => $userId,
                'symbol' => $symbol,
            ]);
            $result['watchlist_touched'] += $touchWatch->rowCount();
        }

        if ($result['positions_updated'] > 0 || $result['watchlist_touched'] > 0) {
            AlertService::create(
                'suggestion_sync',
                'Suggestion Sync Completed',
                sprintf(
                    'Synced: positions %d, notes %d, watchlist %d',
                    $result['positions_updated'],
                    $result['notes_created'],
                    $result['watchlist_touched']
                ),
                'info',
                'suggestion',
                $suggestionId > 0 ? (string) $suggestionId : null,
                $userId
            );
        }

        return $result;
    }

    /**
     * @param array<int, mixed> $symbols
     * @return array<int, string>
     */
    private function normalizeSymbols(array $symbols): array
    {
        $seen = [];
        foreach ($symbols as $item) {
            $symbol = strtoupper(trim((string) $item));
            if (!$this->isLikelyAStockSymbol($symbol)) {
                continue;
            }
            $seen['S:' . $symbol] = $symbol;
        }
        return array_values($seen);
    }

    /**
     * @return array{type:string,value:float}|null
     */
    private function extractPriceSpec(string $content, bool $isStop): ?array
    {
        $stopCn = '\\x{6B62}\\x{635F}(?:\\x{4F4D}|\\x{4EF7})?';
        $takeCn = '(?:\\x{6B62}\\x{76C8}(?:\\x{4F4D}|\\x{4EF7})?|\\x{76EE}\\x{6807}(?:\\x{4F4D}|\\x{4EF7})?)';

        $keywords = $isStop
            ? '(?:' . $stopCn . '|stop\\s*loss|\\bsl\\b)'
            : '(?:' . $takeCn . '|take\\s*profit|\\btp\\b|target)';

        $pattern = '/'
            . $keywords
            . '\\s*(?::|\\x{FF1A})?\\s*([0-9]+(?:\\.[0-9]+)?)\\s*([%\\x{FF05}]?)/iu';

        if (preg_match($pattern, $content, $m) !== 1) {
            return null;
        }

        $value = (float) ($m[1] ?? 0);
        if ($value <= 0) {
            return null;
        }

        $suffix = (string) ($m[2] ?? '');
        $isPct = preg_match('/[%\\x{FF05}]/u', $suffix) === 1;

        return [
            'type' => $isPct ? 'pct' : 'price',
            'value' => $value,
        ];
    }

    private function resolveSpecPrice(?array $spec, ?float $currentPrice, bool $isStop): ?float
    {
        if ($spec === null) {
            return null;
        }

        $type = (string) ($spec['type'] ?? 'price');
        $value = (float) ($spec['value'] ?? 0);
        if ($value <= 0) {
            return null;
        }

        if ($type === 'price') {
            return round($value, 4);
        }

        if ($currentPrice === null || $currentPrice <= 0) {
            return null;
        }

        $pct = min(95.0, max(0.1, $value)) / 100;
        $price = $isStop
            ? $currentPrice * (1 - $pct)
            : $currentPrice * (1 + $pct);

        return round($price, 4);
    }

    private function pickCurrentPrice(?float $positionPrice, string $symbol, string $market): ?float
    {
        if ($positionPrice !== null && $positionPrice > 0) {
            return $positionPrice;
        }

        $stmt = Database::connection()->prepare(
            'SELECT price
             FROM market_quotes
             WHERE symbol = :symbol AND market = :market AND price IS NOT NULL
             ORDER BY quote_time DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'symbol' => $symbol,
            'market' => $market,
        ]);
        $price = (float) ($stmt->fetchColumn() ?: 0);
        return $price > 0 ? $price : null;
    }

    private function buildAdviceText(string $content): string
    {
        $flat = preg_replace('/\s+/u', ' ', trim($content)) ?? '';
        $excerpt = mb_substr($flat, 0, 180);
        return 'OpenClaw suggestion: ' . $excerpt;
    }

    private function isLikelyAStockSymbol(string $symbol): bool
    {
        if (preg_match('/^\d{6}$/', $symbol) !== 1) {
            return false;
        }

        return preg_match('/^(000|001|002|003|300|301|600|601|603|605|688|689|900)/', $symbol) === 1;
    }
}
