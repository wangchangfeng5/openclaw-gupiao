<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Services\AuditService;
use App\Services\MarketOpportunityService;
use App\Services\SectorThemeService;

final class MarketController extends BaseController
{
    private SectorThemeService $themeService;
    private MarketOpportunityService $opportunityService;

    public function __construct()
    {
        $this->themeService = new SectorThemeService();
        $this->opportunityService = new MarketOpportunityService();
    }

    public function sectorsStrength(): void
    {
        $limit = max(1, min(100, (int) $this->query('limit', 30)));
        $stmt = Database::connection()->prepare('SELECT * FROM sector_strength ORDER BY sample_time DESC, strength_score DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $this->ok(['sectors' => $stmt->fetchAll() ?: []]);
    }

    public function hotTopSectors(): void
    {
        $limit = max(1, min(20, (int) $this->query('limit', 10)));
        $pdo = Database::connection();

        $latestSampleTime = (string) ($pdo->query('SELECT MAX(sample_time) FROM sector_strength')->fetchColumn() ?: '');
        if ($latestSampleTime === '') {
            $this->ok([
                'top' => [],
                'sample_time' => null,
                'generated_at' => now_sql(),
            ]);
            return;
        }

        $stmt = $pdo->prepare(
            'SELECT sector_name, strength_score, change_pct, leading_symbol, active_count, sample_time, source
             FROM sector_strength
             WHERE sample_time = :sample_time
             ORDER BY strength_score DESC, change_pct DESC, active_count DESC, sector_name ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':sample_time', $latestSampleTime);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $leadersSnapshot = $this->loadMainboardLeadersSnapshot(6000, 12, 3);
        $ranked = [];
        foreach ($rows as $idx => $row) {
            $sectorName = trim((string) ($row['sector_name'] ?? ''));
            $sectorLeaders = $this->resolveSectorLeaders(
                $sectorName,
                $leadersSnapshot['by_sector'] ?? [],
                3,
                (string) ($row['leading_symbol'] ?? ''),
                $leadersSnapshot['by_symbol'] ?? [],
                $leadersSnapshot['global'] ?? []
            );
            $ranked[] = array_merge($row, [
                'rank' => $idx + 1,
                'sector_mainboard_leaders' => $sectorLeaders,
            ]);
        }

        $this->ok([
            'top' => $ranked,
            'sample_time' => $latestSampleTime,
            'leaders_trade_date' => $leadersSnapshot['trade_date'],
            'mainboard_leaders' => $leadersSnapshot['global'],
            'generated_at' => now_sql(),
        ]);
    }

    public function watchlistCandidates(): void
    {
        $pdo = Database::connection();
        $userId = $this->userId();

        $watchStmt = $pdo->prepare(
            "SELECT symbol, market, name, thesis, priority, status
             FROM watchlist
             WHERE user_id = :user_id AND status = 'active'
             ORDER BY priority ASC, updated_at DESC
             LIMIT 30"
        );
        $watchStmt->execute(['user_id' => $userId]);
        $watch = $watchStmt->fetchAll() ?: [];
        $leaders = $pdo->query('SELECT sector_name, leading_symbol, strength_score, change_pct, sample_time FROM sector_strength ORDER BY sample_time DESC, strength_score DESC LIMIT 20')->fetchAll() ?: [];

        $this->ok([
            'watchlist' => $watch,
            'leaders' => $leaders,
        ]);
    }

    public function closeRankings(): void
    {
        $type = strtolower(trim((string) $this->query('type', 'strong')));
        if (!in_array($type, ['strong', 'moneyflow'], true)) {
            $type = 'strong';
        }

        $limit = max(1, min(100, (int) $this->query('limit', 100)));
        $tradeDate = trim((string) $this->query('trade_date', ''));

        $pdo = Database::connection();
        if ($tradeDate === '') {
            $dateStmt = $pdo->prepare('SELECT MAX(trade_date) FROM market_close_rankings WHERE rank_type = :rank_type');
            $dateStmt->execute(['rank_type' => $type]);
            $tradeDate = (string) ($dateStmt->fetchColumn() ?: '');
        }

        if ($tradeDate === '') {
            $this->ok([
                'type' => $type,
                'trade_date' => null,
                'snapshot_time' => null,
                'rankings' => [],
            ]);
            return;
        }

        $metaStmt = $pdo->prepare(
            'SELECT MAX(snapshot_time) AS snapshot_time
             FROM market_close_rankings
             WHERE rank_type = :rank_type AND trade_date = :trade_date'
        );
        $metaStmt->execute([
            'rank_type' => $type,
            'trade_date' => $tradeDate,
        ]);
        $snapshotTime = (string) (($metaStmt->fetch()['snapshot_time'] ?? '') ?: '');

        $stmt = $pdo->prepare(
            'SELECT
                rank_no, symbol, market, name, sector_name,
                change_1d_pct, change_3d_pct, change_5d_pct, change_10d_pct,
                net_main_inflow, net_main_inflow_pct, flow_direction,
                trade_date, snapshot_time
             FROM market_close_rankings
             WHERE rank_type = :rank_type AND trade_date = :trade_date
             ORDER BY rank_no ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':rank_type', $type);
        $stmt->bindValue(':trade_date', $tradeDate);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $this->ok([
            'type' => $type,
            'trade_date' => $tradeDate,
            'snapshot_time' => $snapshotTime !== '' ? $snapshotTime : null,
            'rankings' => $stmt->fetchAll() ?: [],
        ]);
    }

    public function opportunities(): void
    {
        $limit = max(1, min(30, (int) $this->query('limit', 30)));
        $payload = $this->opportunityService->build($this->userId(), $limit);
        $this->ok($payload);
    }

    public function heatAlert(): void
    {
        $pdo = Database::connection();

        $marketAgg = $pdo->query(
            "SELECT
                COUNT(*) AS total_count,
                SUM(CASE WHEN x.change_pct > 0 THEN 1 ELSE 0 END) AS up_count,
                SUM(CASE WHEN x.change_pct < 0 THEN 1 ELSE 0 END) AS down_count,
                SUM(CASE WHEN x.change_pct = 0 THEN 1 ELSE 0 END) AS flat_count,
                AVG(x.change_pct) AS avg_change_pct,
                SUM(CASE WHEN x.change_pct >= 5 THEN 1 ELSE 0 END) AS strong_up_count,
                SUM(CASE WHEN x.change_pct >= 7 THEN 1 ELSE 0 END) AS extreme_up_count,
                SUM(CASE WHEN x.change_pct <= -5 THEN 1 ELSE 0 END) AS strong_down_count,
                SUM(CASE WHEN x.change_pct <= -7 THEN 1 ELSE 0 END) AS extreme_down_count,
                MAX(x.quote_time) AS asof_time
             FROM (
                SELECT symbol, change_pct, quote_time
                FROM market_quotes_latest
                WHERE market = 'A_STOCK_MAIN'
                  AND symbol REGEXP '^(000|001|002|003|600|601|603|605)[0-9]{3}$'
             ) x"
        )->fetch() ?: [];

        $totalCount = max(0, (int) ($marketAgg['total_count'] ?? 0));
        $upCount = max(0, (int) ($marketAgg['up_count'] ?? 0));
        $downCount = max(0, (int) ($marketAgg['down_count'] ?? 0));
        $flatCount = max(0, (int) ($marketAgg['flat_count'] ?? 0));
        $avgChangePct = $this->toFloat($marketAgg['avg_change_pct'] ?? null) ?? 0.0;
        $strongUpCount = max(0, (int) ($marketAgg['strong_up_count'] ?? 0));
        $extremeUpCount = max(0, (int) ($marketAgg['extreme_up_count'] ?? 0));
        $strongDownCount = max(0, (int) ($marketAgg['strong_down_count'] ?? 0));
        $extremeDownCount = max(0, (int) ($marketAgg['extreme_down_count'] ?? 0));
        $asofTime = (string) ($marketAgg['asof_time'] ?? '');

        $upRatio = $totalCount > 0 ? $upCount / $totalCount : 0.0;
        $downRatio = $totalCount > 0 ? $downCount / $totalCount : 0.0;

        $latestTradeDate = (string) ($pdo->query('SELECT MAX(trade_date) FROM market_close_rankings')->fetchColumn() ?: '');
        $runStats = [
            'trade_date' => $latestTradeDate !== '' ? $latestTradeDate : null,
            'sample_count' => 0,
            'short_runup_count' => 0,
            'short_drawdown_count' => 0,
        ];

        if ($latestTradeDate !== '') {
            $runStmt = $pdo->prepare(
                "SELECT
                    COUNT(*) AS sample_count,
                    SUM(CASE WHEN COALESCE(change_3d_pct, 0) >= 20 OR COALESCE(change_5d_pct, 0) >= 30 THEN 1 ELSE 0 END) AS short_runup_count,
                    SUM(CASE WHEN COALESCE(change_3d_pct, 0) <= -15 OR COALESCE(change_5d_pct, 0) <= -22 THEN 1 ELSE 0 END) AS short_drawdown_count
                 FROM market_close_rankings
                 WHERE trade_date = :trade_date AND rank_type = 'strong'"
            );
            $runStmt->execute(['trade_date' => $latestTradeDate]);
            $runRow = $runStmt->fetch() ?: [];
            $runStats['sample_count'] = max(0, (int) ($runRow['sample_count'] ?? 0));
            $runStats['short_runup_count'] = max(0, (int) ($runRow['short_runup_count'] ?? 0));
            $runStats['short_drawdown_count'] = max(0, (int) ($runRow['short_drawdown_count'] ?? 0));
        }

        $latestSectorTime = (string) ($pdo->query('SELECT MAX(sample_time) FROM sector_strength')->fetchColumn() ?: '');
        $sectorHotRows = [];
        $sectorWeakRows = [];
        $sectorOverheatCount = 0;
        $sectorOversoldCount = 0;
        if ($latestSectorTime !== '') {
            $hotStmt = $pdo->prepare(
                "SELECT sector_name, strength_score, change_pct, active_count
                 FROM sector_strength
                 WHERE sample_time = :sample_time
                 ORDER BY change_pct DESC, strength_score DESC
                 LIMIT 6"
            );
            $hotStmt->execute(['sample_time' => $latestSectorTime]);
            $sectorHotRows = $hotStmt->fetchAll() ?: [];

            $weakStmt = $pdo->prepare(
                "SELECT sector_name, strength_score, change_pct, active_count
                 FROM sector_strength
                 WHERE sample_time = :sample_time
                 ORDER BY change_pct ASC, strength_score ASC
                 LIMIT 6"
            );
            $weakStmt->execute(['sample_time' => $latestSectorTime]);
            $sectorWeakRows = $weakStmt->fetchAll() ?: [];

            $countStmt = $pdo->prepare(
                "SELECT
                    SUM(CASE WHEN COALESCE(change_pct, 0) >= 5 THEN 1 ELSE 0 END) AS overheat_count,
                    SUM(CASE WHEN COALESCE(change_pct, 0) <= -4 THEN 1 ELSE 0 END) AS oversold_count
                 FROM sector_strength
                 WHERE sample_time = :sample_time"
            );
            $countStmt->execute(['sample_time' => $latestSectorTime]);
            $countRow = $countStmt->fetch() ?: [];
            $sectorOverheatCount = max(0, (int) ($countRow['overheat_count'] ?? 0));
            $sectorOversoldCount = max(0, (int) ($countRow['oversold_count'] ?? 0));
        }

        $isOverheat = ($upRatio >= 0.74 && $avgChangePct >= 1.2)
            || $strongUpCount >= 20
            || $extremeUpCount >= 8
            || ((int) $runStats['short_runup_count'] >= 24);
        $isPanicRisk = ($downRatio >= 0.72 && $avgChangePct <= -1.1)
            || $strongDownCount >= 20
            || $extremeDownCount >= 8;
        $isOversold = ($downRatio >= 0.82 && $avgChangePct <= -1.8)
            || $extremeDownCount >= 12
            || ((int) $runStats['short_drawdown_count'] >= 20)
            || $sectorOversoldCount >= 10;

        $mode = 'neutral';
        if ($isOverheat) {
            $mode = 'overheat';
        } elseif ($isOversold) {
            $mode = 'oversold';
        } elseif ($isPanicRisk) {
            $mode = 'risk';
        }

        $title = '市场热度中性';
        $summary = '情绪暂时均衡，保持节奏执行即可。';
        $actionTip = '遵守定时策略，不追涨不恐慌。';
        if ($mode === 'overheat') {
            $title = '短期过热提醒';
            $summary = '短线涨幅与强势数量偏高，追高性价比下降。';
            $actionTip = '保持冷静，优先分批兑现与控制仓位。';
        } elseif ($mode === 'oversold') {
            $title = '超跌信号提醒';
            $summary = '短线下跌范围与幅度偏大，情绪接近冰点。';
            $actionTip = '避免情绪化割肉，等待分时企稳后按策略分批应对。';
        } elseif ($mode === 'risk') {
            $title = '风险扩散提醒';
            $summary = '下跌家数明显占优，盘面承接偏弱。';
            $actionTip = '先守纪律和仓位，等待市场确认再出手。';
        }

        $heatScore = $this->clamp(50.0 + $avgChangePct * 8.0 + ($upRatio - 0.5) * 70.0, 0.0, 100.0);

        $alerts = [];
        if ($isOverheat) {
            $alerts[] = [
                'level' => 'risk',
                'title' => '短线过热',
                'message' => '大涨家数偏多，短线波动放大，避免追高。',
            ];
        }
        if ($isPanicRisk) {
            $alerts[] = [
                'level' => 'risk',
                'title' => '风险扩散',
                'message' => '下跌家数占优，盘中回撤可能反复。',
            ];
        }
        if ($isOversold) {
            $alerts[] = [
                'level' => 'oversold',
                'title' => '超跌观察',
                'message' => '短线情绪偏冷，关注企稳反抽信号，避免情绪化操作。',
            ];
        }
        if ($alerts === []) {
            $alerts[] = [
                'level' => 'info',
                'title' => '节奏正常',
                'message' => '按既定计划执行，优先等待明确信号。',
            ];
        }

        $this->ok([
            'generated_at' => now_sql(),
            'mode' => $mode,
            'title' => $title,
            'summary' => $summary,
            'action_tip' => $actionTip,
            'heat_score' => round($heatScore, 2),
            'market' => [
                'asof_time' => $asofTime !== '' ? $asofTime : null,
                'total_count' => $totalCount,
                'up_count' => $upCount,
                'down_count' => $downCount,
                'flat_count' => $flatCount,
                'up_ratio' => round($upRatio, 4),
                'down_ratio' => round($downRatio, 4),
                'avg_change_pct' => round($avgChangePct, 4),
                'strong_up_count' => $strongUpCount,
                'extreme_up_count' => $extremeUpCount,
                'strong_down_count' => $strongDownCount,
                'extreme_down_count' => $extremeDownCount,
            ],
            'short_term' => $runStats,
            'sectors' => [
                'sample_time' => $latestSectorTime !== '' ? $latestSectorTime : null,
                'overheat_count' => $sectorOverheatCount,
                'oversold_count' => $sectorOversoldCount,
                'hottest' => $sectorHotRows,
                'weakest' => $sectorWeakRows,
            ],
            'alerts' => $alerts,
        ]);
    }

    public function news(): void
    {
        $limit = max(1, min(200, (int) $this->query('limit', 50)));
        $stmt = Database::connection()->prepare('SELECT id, title, summary, url, source, category, sentiment, published_at FROM news_feed ORDER BY published_at DESC, id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $this->ok(['news' => $stmt->fetchAll() ?: []]);
    }

    public function themesOverview(): void
    {
        $sectorLimit = max(1, min(40, (int) $this->query('sector_limit', 12)));
        $stockLimit = max(1, min(20, (int) $this->query('stock_limit', 8)));

        $payload = $this->themeService->overview($this->userId(), $sectorLimit, $stockLimit);
        $this->ok($payload);
    }

    public function storeTheme(): void
    {
        try {
            $id = $this->themeService->createTheme($this->userId(), $this->body());
            AuditService::log('market.theme.create', 'market_theme', (string) $id, $this->body());
            $this->ok(['id' => $id], 201);
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->fail('create theme failed', 500, ['reason' => $e->getMessage()]);
        }
    }

    public function updateTheme(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            $this->fail('invalid theme id', 422);
            return;
        }

        try {
            $ok = $this->themeService->updateTheme($this->userId(), $id, $this->body());
            if (!$ok) {
                $this->fail('theme not found', 404);
                return;
            }

            AuditService::log('market.theme.update', 'market_theme', (string) $id, $this->body());
            $this->ok(['id' => $id]);
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->fail('update theme failed', 500, ['reason' => $e->getMessage()]);
        }
    }

    public function destroyTheme(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            $this->fail('invalid theme id', 422);
            return;
        }

        $ok = $this->themeService->deleteTheme($this->userId(), $id);
        if (!$ok) {
            $this->fail('theme not found', 404);
            return;
        }

        AuditService::log('market.theme.delete', 'market_theme', (string) $id, []);
        $this->ok(['id' => $id]);
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }

    /**
     * @return array{
     *   trade_date:?string,
     *   global:array<int, array<string, mixed>>,
     *   by_sector:array<string, array<int, array<string, mixed>>>,
     *   by_symbol:array<string, array<string, mixed>>
     * }
     */
    private function loadMainboardLeadersSnapshot(int $scanLimit = 1200, int $globalLimit = 12, int $sectorLimit = 3): array
    {
        $pdo = Database::connection();
        $tradeDate = (string) ($pdo->query("SELECT MAX(trade_date) FROM market_close_rankings WHERE rank_type = 'strong'")->fetchColumn() ?: '');
        if ($tradeDate === '') {
            return [
                'trade_date' => null,
                'global' => [],
                'by_sector' => [],
                'by_symbol' => [],
            ];
        }

        $stmt = $pdo->prepare(
            "SELECT
                r.symbol,
                r.market,
                r.name,
                COALESCE(NULLIF(TRIM(r.sector_name), ''), q.sector_name, '') AS sector_name,
                r.rank_no,
                r.change_1d_pct,
                r.change_3d_pct,
                r.change_5d_pct,
                r.snapshot_time
             FROM market_close_rankings r
             LEFT JOIN market_quotes_latest q
                ON q.symbol = r.symbol AND q.market = 'A_STOCK_MAIN'
             WHERE r.rank_type = 'strong'
               AND r.trade_date = :trade_date
               AND r.symbol REGEXP '^(000|001|002|003|600|601|603|605)[0-9]{3}$'
             ORDER BY COALESCE(r.change_3d_pct, -999) DESC, COALESCE(r.change_1d_pct, -999) DESC, r.rank_no ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':trade_date', $tradeDate);
        $stmt->bindValue(':limit', max(100, min(5000, $scanLimit)), \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];

        $mapped = array_map(
            static fn(array $row): array => [
                'symbol' => (string) ($row['symbol'] ?? ''),
                'market' => (string) ($row['market'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'sector_name' => (string) ($row['sector_name'] ?? ''),
                'rank_no' => $row['rank_no'] ?? null,
                'change_1d_pct' => $row['change_1d_pct'] ?? null,
                'change_3d_pct' => $row['change_3d_pct'] ?? null,
                'change_5d_pct' => $row['change_5d_pct'] ?? null,
                'snapshot_time' => $row['snapshot_time'] ?? null,
            ],
            $rows
        );

        $global = array_slice($mapped, 0, max(1, min(30, $globalLimit)));

        $bySector = [];
        $bySymbol = [];
        foreach ($mapped as $row) {
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
            if ($symbol !== '' && !isset($bySymbol[$symbol])) {
                $bySymbol[$symbol] = $row;
            }
            $sectorName = trim((string) ($row['sector_name'] ?? ''));
            if ($sectorName === '') {
                continue;
            }
            if (!isset($bySector[$sectorName])) {
                $bySector[$sectorName] = [];
            }
            if (count($bySector[$sectorName]) >= max(1, min(10, $sectorLimit))) {
                continue;
            }
            $bySector[$sectorName][] = $row;
        }

        return [
            'trade_date' => $tradeDate,
            'global' => array_values($global),
            'by_sector' => $bySector,
            'by_symbol' => $bySymbol,
        ];
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $bySector
     * @param array<string, array<string, mixed>> $bySymbol
     * @param array<int, array<string, mixed>> $globalFallback
     * @return array<int, array<string, mixed>>
     */
    private function resolveSectorLeaders(
        string $sectorName,
        array $bySector,
        int $limit = 3,
        string $fallbackSymbol = '',
        array $bySymbol = [],
        array $globalFallback = []
    ): array
    {
        $name = $this->normalizeSectorName($sectorName);
        if ($name !== '' && $bySector !== []) {
            if (isset($bySector[$sectorName])) {
                return array_slice($bySector[$sectorName], 0, $limit);
            }

            foreach ($bySector as $candidateName => $leaders) {
                $candidate = $this->normalizeSectorName((string) $candidateName);
                if ($candidate === '') {
                    continue;
                }
                if (str_contains($candidate, $name) || str_contains($name, $candidate)) {
                    return array_slice($leaders, 0, $limit);
                }
            }
        }

        $picked = [];
        $seen = [];

        $symbol = strtoupper(trim($fallbackSymbol));
        if ($symbol !== '' && isset($bySymbol[$symbol])) {
            $picked[] = $bySymbol[$symbol];
            $seen[$symbol] = true;
        }

        foreach ($globalFallback as $row) {
            $s = strtoupper(trim((string) ($row['symbol'] ?? '')));
            if ($s === '' || isset($seen[$s])) {
                continue;
            }
            $picked[] = $row;
            $seen[$s] = true;
            if (count($picked) >= $limit) {
                break;
            }
        }

        return array_slice($picked, 0, $limit);
    }

    private function normalizeSectorName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        return (string) preg_replace('/\s+/u', '', $name);
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
