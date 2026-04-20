<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Services\AuditService;
use App\Services\SectorThemeService;

final class MarketController extends BaseController
{
    private SectorThemeService $themeService;

    public function __construct()
    {
        $this->themeService = new SectorThemeService();
    }

    public function sectorsStrength(): void
    {
        $limit = max(1, min(100, (int) $this->query('limit', 30)));
        $stmt = Database::connection()->prepare('SELECT * FROM sector_strength ORDER BY sample_time DESC, strength_score DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $this->ok(['sectors' => $stmt->fetchAll() ?: []]);
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
}
