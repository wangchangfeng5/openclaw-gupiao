<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class SectorThemeService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(int $userId, int $sectorLimit = 12, int $stockLimit = 8): array
    {
        $sectorLimit = max(1, min(40, $sectorLimit));
        $stockLimit = max(1, min(20, $stockLimit));

        $quotes = $this->loadLatestQuotes(5000);
        $hotSectors = $this->loadHotSectors($sectorLimit);
        $hotSectors = array_map(
            fn(array $sector): array => $this->enrichSectorWithStocks($sector, $quotes, $stockLimit),
            $hotSectors
        );

        $themes = $this->loadCustomThemes($userId);
        $customThemes = array_map(
            fn(array $theme): array => $this->enrichThemeWithStocks($theme, $quotes, $stockLimit),
            $themes
        );

        return [
            'hot_sectors' => $hotSectors,
            'custom_themes' => $customThemes,
            'generated_at' => now_sql(),
        ];
    }

    public function createTheme(int $userId, array $body): int
    {
        $name = trim((string) ($body['theme_name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('theme_name is required');
        }

        if ($this->themeNameExists($userId, $name)) {
            throw new \RuntimeException('theme already exists');
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO market_themes (user_id, theme_name, keywords, note, priority, status, created_at, updated_at)
             VALUES (:user_id, :theme_name, :keywords, :note, :priority, :status, NOW(), NOW())'
        );

        $stmt->execute([
            'user_id' => $userId,
            'theme_name' => $name,
            'keywords' => $this->normalizeKeywordString((string) ($body['keywords'] ?? '')),
            'note' => trim((string) ($body['note'] ?? '')),
            'priority' => $this->normalizePriority($body['priority'] ?? 50),
            'status' => $this->normalizeStatus((string) ($body['status'] ?? 'active')),
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    public function updateTheme(int $userId, int $id, array $body): bool
    {
        if ($id <= 0) {
            throw new \RuntimeException('invalid theme id');
        }

        $current = $this->findTheme($userId, $id);
        if ($current === null) {
            return false;
        }

        $name = trim((string) ($body['theme_name'] ?? $current['theme_name']));
        if ($name === '') {
            throw new \RuntimeException('theme_name is required');
        }

        if ($name !== (string) $current['theme_name'] && $this->themeNameExists($userId, $name)) {
            throw new \RuntimeException('theme already exists');
        }

        $stmt = Database::connection()->prepare(
            'UPDATE market_themes
             SET theme_name = :theme_name,
                 keywords = :keywords,
                 note = :note,
                 priority = :priority,
                 status = :status,
                 updated_at = NOW()
             WHERE id = :id AND user_id = :user_id'
        );

        $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
            'theme_name' => $name,
            'keywords' => $this->normalizeKeywordString((string) ($body['keywords'] ?? ($current['keywords'] ?? ''))),
            'note' => trim((string) ($body['note'] ?? ($current['note'] ?? ''))),
            'priority' => $this->normalizePriority($body['priority'] ?? ($current['priority'] ?? 50)),
            'status' => $this->normalizeStatus((string) ($body['status'] ?? ($current['status'] ?? 'active'))),
        ]);

        return true;
    }

    public function deleteTheme(int $userId, int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $stmt = Database::connection()->prepare('DELETE FROM market_themes WHERE id = :id AND user_id = :user_id');
        $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadHotSectors(int $limit): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT s.sector_name, s.strength_score, s.change_pct, s.leading_symbol, s.active_count, s.sample_time, s.source
             FROM sector_strength s
             INNER JOIN (
               SELECT sector_name, MAX(id) AS max_id
               FROM sector_strength
               WHERE sector_name IS NOT NULL AND sector_name <> ""
               GROUP BY sector_name
             ) latest ON latest.max_id = s.id
             ORDER BY s.strength_score DESC, s.change_pct DESC, s.sample_time DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadLatestQuotes(int $limit): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT symbol, market, name, sector_name, trend_direction, price, change_pct, volume, quote_time
             FROM market_quotes_latest
             WHERE market = :market
               AND quote_time >= DATE_SUB(NOW(), INTERVAL 15 DAY)
               AND price IS NOT NULL
             ORDER BY change_pct DESC, volume DESC, symbol DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':market', 'A_STOCK_MAIN');
        $stmt->bindValue(':limit', max(100, min(20000, $limit)), \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadCustomThemes(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, theme_name, keywords, note, priority, status, created_at, updated_at
             FROM market_themes
             WHERE user_id = :user_id
             ORDER BY FIELD(status, "active", "paused"), priority ASC, updated_at DESC, id DESC'
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findTheme(int $userId, int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, theme_name, keywords, note, priority, status
             FROM market_themes
             WHERE id = :id AND user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
        ]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function themeNameExists(int $userId, string $themeName): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT id FROM market_themes WHERE user_id = :user_id AND theme_name = :theme_name LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'theme_name' => trim($themeName),
        ]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $sector
     * @param array<int, array<string, mixed>> $quotes
     * @return array<string, mixed>
     */
    private function enrichSectorWithStocks(array $sector, array $quotes, int $stockLimit): array
    {
        $name = trim((string) ($sector['sector_name'] ?? ''));
        $keywords = $this->buildSectorKeywords($name);
        $stocks = $this->pickStocksByKeywords($quotes, $keywords, $stockLimit);

        if ($stocks === []) {
            $leader = strtoupper(trim((string) ($sector['leading_symbol'] ?? '')));
            $leaderStock = $this->fallbackLeaderStock($quotes, $leader);
            if ($leaderStock !== null) {
                $stocks[] = $leaderStock;
            }
        }

        return array_merge($sector, [
            'keywords' => $keywords,
            'strong_stocks' => $stocks,
            'hot_count' => count($stocks),
        ]);
    }

    /**
     * @param array<string, mixed> $theme
     * @param array<int, array<string, mixed>> $quotes
     * @return array<string, mixed>
     */
    private function enrichThemeWithStocks(array $theme, array $quotes, int $stockLimit): array
    {
        $themeName = trim((string) ($theme['theme_name'] ?? ''));
        $keywords = $this->parseKeywords((string) ($theme['keywords'] ?? ''), $themeName);
        $stocks = $this->pickStocksByKeywords($quotes, $keywords, $stockLimit);
        $matchedSectors = $this->collectMatchedSectors($stocks);

        return array_merge($theme, [
            'keywords_list' => $keywords,
            'strong_stocks' => $stocks,
            'matched_sectors' => $matchedSectors,
            'hot_count' => count($stocks),
        ]);
    }

    /**
     * @param array<int, string> $keywords
     * @param array<int, array<string, mixed>> $quotes
     * @return array<int, array<string, mixed>>
     */
    private function pickStocksByKeywords(array $quotes, array $keywords, int $limit): array
    {
        if ($keywords === []) {
            return [];
        }

        $hits = [];
        foreach ($quotes as $row) {
            $sector = (string) ($row['sector_name'] ?? '');
            $name = (string) ($row['name'] ?? '');

            if (!$this->matchesKeywords($sector, $keywords) && !$this->matchesKeywords($name, $keywords)) {
                continue;
            }

            $changePct = $this->toFloat($row['change_pct'] ?? null) ?? 0.0;
            $volume = max(0.0, $this->toFloat($row['volume'] ?? null) ?? 0.0);
            $trend = strtolower(trim((string) ($row['trend_direction'] ?? '')));

            $score = $this->scoreStock($changePct, $trend, $volume);
            $hits[] = $this->buildStockCard($row, $score, $this->actionAdvice($changePct, $trend));
        }

        usort(
            $hits,
            static function (array $a, array $b): int {
                $scoreDiff = (float) ($b['hot_score'] ?? 0) <=> (float) ($a['hot_score'] ?? 0);
                if ($scoreDiff !== 0) {
                    return $scoreDiff;
                }

                $changeDiff = (float) ($b['change_pct'] ?? 0) <=> (float) ($a['change_pct'] ?? 0);
                if ($changeDiff !== 0) {
                    return $changeDiff;
                }

                return strcmp((string) ($b['quote_time'] ?? ''), (string) ($a['quote_time'] ?? ''));
            }
        );

        $hits = array_slice($hits, 0, $limit);
        return array_values($hits);
    }

    /**
     * @param array<int, array<string, mixed>> $quotes
     * @return array<string, mixed>|null
     */
    private function fallbackLeaderStock(array $quotes, string $leaderSymbol): ?array
    {
        if ($leaderSymbol === '') {
            return null;
        }

        foreach ($quotes as $row) {
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
            if ($symbol !== $leaderSymbol) {
                continue;
            }

            $changePct = $this->toFloat($row['change_pct'] ?? null) ?? 0.0;
            $volume = max(0.0, $this->toFloat($row['volume'] ?? null) ?? 0.0);
            $trend = strtolower(trim((string) ($row['trend_direction'] ?? '')));
            $score = max(55.0, $this->scoreStock($changePct, $trend, $volume));

            return $this->buildStockCard($row, $score, '板块龙头跟踪，关注量价持续性。');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function buildStockCard(array $row, float $score, string $advice): array
    {
        return [
            'symbol' => (string) ($row['symbol'] ?? ''),
            'market' => (string) ($row['market'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'sector_name' => (string) ($row['sector_name'] ?? ''),
            'trend_direction' => (string) ($row['trend_direction'] ?? ''),
            'price' => $row['price'] ?? null,
            'change_pct' => $row['change_pct'] ?? null,
            'volume' => $row['volume'] ?? null,
            'quote_time' => $row['quote_time'] ?? null,
            'hot_score' => round($score, 2),
            'action_advice' => $advice,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $stocks
     * @return array<int, string>
     */
    private function collectMatchedSectors(array $stocks): array
    {
        $set = [];
        foreach ($stocks as $stock) {
            $sector = trim((string) ($stock['sector_name'] ?? ''));
            if ($sector !== '') {
                $set[$sector] = true;
            }
        }

        return array_slice(array_keys($set), 0, 8);
    }

    /**
     * @return array<int, string>
     */
    private function buildSectorKeywords(string $sectorName): array
    {
        $sectorName = trim($sectorName);
        if ($sectorName === '') {
            return [];
        }

        $keywords = [$sectorName];
        $len = function_exists('mb_strlen') ? mb_strlen($sectorName) : strlen($sectorName);
        if ($len >= 5) {
            $keywords[] = function_exists('mb_substr') ? mb_substr($sectorName, 0, 4) : substr($sectorName, 0, 4);
        }
        if ($len >= 7) {
            $keywords[] = function_exists('mb_substr') ? mb_substr($sectorName, 0, 6) : substr($sectorName, 0, 6);
        }

        $keywords = array_values(array_unique(array_filter(array_map('trim', $keywords))));
        return $keywords;
    }

    /**
     * @return array<int, string>
     */
    private function parseKeywords(string $rawKeywords, string $fallbackName): array
    {
        $source = trim($rawKeywords);
        if ($source === '') {
            $source = $fallbackName;
        }

        $parts = preg_split('/[,\s，;；|]+/u', $source) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn(string $x): bool => $x !== ''));

        if ($parts === [] && trim($fallbackName) !== '') {
            $parts[] = trim($fallbackName);
        }

        return array_values(array_unique($parts));
    }

    private function normalizeKeywordString(string $rawKeywords): string
    {
        $keywords = $this->parseKeywords($rawKeywords, '');
        return implode(',', $keywords);
    }

    private function normalizePriority(mixed $value): int
    {
        if (!is_numeric($value)) {
            return 50;
        }

        $n = (int) $value;
        return max(1, min(999, $n));
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if (!in_array($status, ['active', 'paused'], true)) {
            return 'active';
        }
        return $status;
    }

    private function matchesKeywords(string $text, array $keywords): bool
    {
        $text = trim($text);
        if ($text === '' || $keywords === []) {
            return false;
        }

        foreach ($keywords as $keyword) {
            if ($keyword === '') {
                continue;
            }

            if (function_exists('mb_stripos')) {
                if (mb_stripos($text, $keyword) !== false) {
                    return true;
                }
            } elseif (stripos($text, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    private function scoreStock(float $changePct, string $trend, float $volume): float
    {
        $trendScore = match ($trend) {
            'strong_up' => 12.0,
            'up_bias' => 6.0,
            'range' => 2.0,
            'down_bias' => -5.0,
            'strong_down' => -10.0,
            default => 0.0,
        };

        $changeScore = $changePct * 8.0;
        $volumeScore = min(12.0, log10(max(1.0, $volume + 1.0)) * 2.0);

        return 50.0 + $trendScore + $changeScore + $volumeScore;
    }

    private function actionAdvice(float $changePct, string $trend): string
    {
        if ($trend === 'strong_up' && $changePct >= 4.0) {
            return '强势放量上行，关注回踩承接，不追高。';
        }
        if (($trend === 'strong_up' || $trend === 'up_bias') && $changePct >= 1.0) {
            return '趋势偏强，可分批跟踪，跌破分时关键位减仓。';
        }
        if (($trend === 'down_bias' || $trend === 'strong_down') && $changePct < 0) {
            return '走势偏弱，先观察，等待止跌放量信号。';
        }
        return '维持观察，结合板块强度和量能再决策。';
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
}
