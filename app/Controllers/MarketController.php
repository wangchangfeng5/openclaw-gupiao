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
