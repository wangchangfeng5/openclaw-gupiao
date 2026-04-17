<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Services\AuditService;
use App\Services\PositionDetailService;
use App\Services\SymbolInsightService;

final class PositionsController extends BaseController
{
    private SymbolInsightService $insight;
    private PositionDetailService $detailService;

    public function __construct()
    {
        $this->insight = new SymbolInsightService();
        $this->detailService = new PositionDetailService($this->insight);
    }

    public function index(): void
    {
        $stmt = Database::connection()->prepare('SELECT * FROM positions WHERE user_id = :user_id ORDER BY updated_at DESC, id DESC');
        $stmt->execute(['user_id' => $this->userId()]);
        $rows = $stmt->fetchAll() ?: [];
        $this->ok(['positions' => $rows]);
    }

    public function analysis(): void
    {
        $userId = $this->userId();
        $sql = "SELECT p.*,
                       lt.trade_type AS last_trade_type,
                       lt.traded_at AS last_traded_at,
                       COALESCE(tp.realized_total, 0) AS realized_pnl_total,
                       COALESCE(tp.trade_count, 0) AS trade_count
                FROM positions p
                LEFT JOIN (
                    SELECT t.position_id,
                           SUM(COALESCE(t.realized_pnl, 0)) AS realized_total,
                           COUNT(*) AS trade_count
                    FROM position_trades t
                    WHERE t.user_id = :user_id_tp
                    GROUP BY t.position_id
                ) tp ON tp.position_id = p.id
                LEFT JOIN (
                    SELECT x.position_id, x.trade_type, x.traded_at
                    FROM position_trades x
                    INNER JOIN (
                        SELECT position_id, MAX(id) AS max_id
                        FROM position_trades
                        WHERE user_id = :user_id_latest
                        GROUP BY position_id
                    ) latest ON latest.max_id = x.id
                    WHERE x.user_id = :user_id_lt
                ) lt ON lt.position_id = p.id
                WHERE p.user_id = :user_id
                ORDER BY FIELD(p.status, 'holding', 'watching', 'closed'), p.updated_at DESC";

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([
            'user_id_tp' => $userId,
            'user_id_latest' => $userId,
            'user_id_lt' => $userId,
            'user_id' => $userId,
        ]);
        $rows = $stmt->fetchAll() ?: [];

        $items = [];
        $totalCost = 0.0;
        $totalMarket = 0.0;
        $totalRealized = 0.0;

        foreach ($rows as $row) {
            $qty = (float) ($row['quantity'] ?? 0);
            $cost = (float) ($row['cost_price'] ?? 0);
            $current = $row['current_price'] !== null ? (float) $row['current_price'] : null;
            $stopLoss = $row['stop_loss_price'] !== null ? (float) $row['stop_loss_price'] : null;
            $takeProfit = $row['take_profit_price'] !== null ? (float) $row['take_profit_price'] : null;

            $insight = $this->insight->analyze(
                (string) ($row['symbol'] ?? ''),
                (string) ($row['market'] ?? 'A_STOCK_MAIN'),
                $current,
                $stopLoss,
                $takeProfit,
                'position',
                $userId
            );

            $currentPrice = (float) ($insight['current_price'] ?? 0);
            $costValue = $qty * $cost;
            $marketValue = $qty * $currentPrice;
            $pnl = $marketValue - $costValue;
            $pnlPct = $costValue > 0 ? ($pnl / $costValue) * 100 : 0;

            $distanceToStop = null;
            if ($stopLoss !== null && $stopLoss > 0 && $currentPrice > 0) {
                $distanceToStop = (($currentPrice - $stopLoss) / $currentPrice) * 100;
            }

            $distanceToTake = null;
            if ($takeProfit !== null && $takeProfit > 0 && $currentPrice > 0) {
                $distanceToTake = (($takeProfit - $currentPrice) / $currentPrice) * 100;
            }

            $realized = (float) ($row['realized_pnl_total'] ?? 0);

            $totalCost += $costValue;
            $totalMarket += $marketValue;
            $totalRealized += $realized;

            $items[] = array_merge($row, [
                'insight' => $insight,
                'cost_value' => round($costValue, 4),
                'market_value' => round($marketValue, 4),
                'pnl' => round($pnl, 4),
                'pnl_pct' => round($pnlPct, 4),
                'distance_to_stop_pct' => $distanceToStop !== null ? round($distanceToStop, 4) : null,
                'distance_to_take_pct' => $distanceToTake !== null ? round($distanceToTake, 4) : null,
                'realized_pnl_total' => round($realized, 4),
            ]);
        }

        $this->ok([
            'positions' => $items,
            'summary' => [
                'count' => count($items),
                'total_cost' => round($totalCost, 4),
                'total_market_value' => round($totalMarket, 4),
                'total_pnl' => round($totalMarket - $totalCost, 4),
                'total_pnl_pct' => $totalCost > 0 ? round((($totalMarket - $totalCost) / $totalCost) * 100, 4) : 0,
                'total_realized_pnl' => round($totalRealized, 4),
            ],
            'generated_at' => now_sql(),
        ]);
    }

    public function detail(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            $this->fail('invalid position id', 422);
            return;
        }

        $payload = $this->detailService->build($this->userId(), $id, (int) $this->query('limit', 220));
        if ($payload === null) {
            $this->fail('position not found', 404);
            return;
        }

        $this->ok($payload);
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

        $stmt = Database::connection()->prepare(
            'INSERT INTO positions (
                user_id, symbol, market, name, quantity, cost_price, current_price, stop_loss_price,
                take_profit_price, status, strategy, operation_advice, created_at, updated_at
            ) VALUES (
                :user_id, :symbol, :market, :name, :quantity, :cost_price, :current_price, :stop_loss_price,
                :take_profit_price, :status, :strategy, :operation_advice, NOW(), NOW()
            )'
        );

        $stmt->execute([
            'user_id' => $userId,
            'symbol' => $symbol,
            'market' => (string) ($body['market'] ?? 'A_STOCK_MAIN'),
            'name' => (string) ($body['name'] ?? ''),
            'quantity' => (float) ($body['quantity'] ?? 0),
            'cost_price' => (float) ($body['cost_price'] ?? 0),
            'current_price' => $body['current_price'] ?? null,
            'stop_loss_price' => $body['stop_loss_price'] ?? null,
            'take_profit_price' => $body['take_profit_price'] ?? null,
            'status' => (string) ($body['status'] ?? 'holding'),
            'strategy' => (string) ($body['strategy'] ?? ''),
            'operation_advice' => (string) ($body['operation_advice'] ?? ''),
        ]);

        $id = (int) Database::connection()->lastInsertId();
        AuditService::log('positions.create', 'position', (string) $id, $body);
        $this->ok(['id' => $id], 201);
    }

    public function update(): void
    {
        $userId = $this->userId();
        $body = $this->body();
        $id = (int) ($body['id'] ?? 0);

        if ($id <= 0) {
            $this->fail('id is required', 422);
            return;
        }

        $stmt = Database::connection()->prepare(
            'UPDATE positions SET
                symbol = :symbol,
                market = :market,
                name = :name,
                quantity = :quantity,
                cost_price = :cost_price,
                current_price = :current_price,
                stop_loss_price = :stop_loss_price,
                take_profit_price = :take_profit_price,
                status = :status,
                strategy = :strategy,
                operation_advice = :operation_advice,
                updated_at = NOW()
            WHERE id = :id AND user_id = :user_id'
        );

        $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
            'symbol' => strtoupper(trim((string) ($body['symbol'] ?? ''))),
            'market' => (string) ($body['market'] ?? 'A_STOCK_MAIN'),
            'name' => (string) ($body['name'] ?? ''),
            'quantity' => (float) ($body['quantity'] ?? 0),
            'cost_price' => (float) ($body['cost_price'] ?? 0),
            'current_price' => $body['current_price'] ?? null,
            'stop_loss_price' => $body['stop_loss_price'] ?? null,
            'take_profit_price' => $body['take_profit_price'] ?? null,
            'status' => (string) ($body['status'] ?? 'holding'),
            'strategy' => (string) ($body['strategy'] ?? ''),
            'operation_advice' => (string) ($body['operation_advice'] ?? ''),
        ]);

        AuditService::log('positions.update', 'position', (string) $id, $body);
        $this->ok(['id' => $id]);
    }

    public function destroy(array $params): void
    {
        $userId = $this->userId();
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            $this->fail('invalid position id', 422);
            return;
        }

        $stmt = Database::connection()->prepare('DELETE FROM positions WHERE id = :id AND user_id = :user_id');
        $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
        ]);

        AuditService::log('positions.delete', 'position', (string) $id, []);
        $this->ok(['id' => $id]);
    }

    public function trades(array $params): void
    {
        $userId = $this->userId();
        $positionId = (int) ($params['id'] ?? 0);
        if ($positionId <= 0) {
            $this->fail('invalid position id', 422);
            return;
        }

        if (!$this->positionOwned($positionId, $userId)) {
            $this->fail('position not found', 404);
            return;
        }

        $limit = max(1, min(200, (int) $this->query('limit', 50)));
        $stmt = Database::connection()->prepare(
            'SELECT * FROM position_trades WHERE user_id = :user_id AND position_id = :position_id ORDER BY traded_at DESC, id DESC LIMIT :limit'
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':position_id', $positionId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $this->ok([
            'position_id' => $positionId,
            'trades' => $stmt->fetchAll() ?: [],
        ]);
    }

    public function recordTrade(array $params): void
    {
        $userId = $this->userId();
        $positionId = (int) ($params['id'] ?? 0);
        if ($positionId <= 0) {
            $this->fail('invalid position id', 422);
            return;
        }

        $body = $this->body();
        $tradeType = strtolower(trim((string) ($body['trade_type'] ?? '')));
        if (!in_array($tradeType, ['add', 'reduce', 'clear'], true)) {
            $this->fail('trade_type must be add/reduce/clear', 422);
            return;
        }

        $price = (float) ($body['price'] ?? 0);
        if ($price <= 0) {
            $this->fail('price must be > 0', 422);
            return;
        }

        $fee = (float) ($body['fee'] ?? 0);
        if ($fee < 0) {
            $this->fail('fee must be >= 0', 422);
            return;
        }

        $quantityInput = (float) ($body['quantity'] ?? 0);
        $note = trim((string) ($body['note'] ?? ''));
        $tradedAt = trim((string) ($body['traded_at'] ?? ''));
        $tradedAt = $tradedAt !== '' ? $tradedAt : now_sql();

        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $position = $this->loadPositionForUpdate($positionId, $userId);
            if ($position === null) {
                throw new \RuntimeException('position not found');
            }

            $beforeQty = (float) ($position['quantity'] ?? 0);
            $beforeCost = (float) ($position['cost_price'] ?? 0);
            $beforeStatus = (string) ($position['status'] ?? 'holding');

            $quantity = $quantityInput;
            if ($tradeType === 'clear') {
                $quantity = $beforeQty;
            }

            if ($quantity <= 0) {
                throw new \RuntimeException('quantity must be > 0');
            }

            if ($tradeType !== 'add' && $quantity > $beforeQty) {
                throw new \RuntimeException('reduce/clear quantity exceeds position quantity');
            }

            // 减仓数量等于当前仓位时，自动按清仓处理，避免二次减仓失败。
            if ($tradeType === 'reduce' && $quantity >= $beforeQty) {
                $tradeType = 'clear';
                $quantity = $beforeQty;
            }

            $amount = $quantity * $price;
            $realizedPnl = null;
            $afterQty = $beforeQty;
            $afterCost = $beforeCost;
            $afterStatus = $beforeStatus;

            if ($tradeType === 'add') {
                $totalCostBefore = $beforeQty * $beforeCost;
                $totalCostAfter = $totalCostBefore + $amount + $fee;
                $afterQty = $beforeQty + $quantity;
                $afterCost = $afterQty > 0 ? $totalCostAfter / $afterQty : 0;
                $afterStatus = 'holding';
            } else {
                $afterQty = max(0, $beforeQty - $quantity);
                $realizedPnl = $amount - ($quantity * $beforeCost) - $fee;
                $afterCost = $afterQty > 0 ? $beforeCost : 0;
                $afterStatus = $afterQty > 0 ? 'holding' : 'closed';
            }

            $update = $pdo->prepare(
                'UPDATE positions
                 SET quantity = :quantity,
                     cost_price = :cost_price,
                     current_price = :current_price,
                     status = :status,
                     updated_at = NOW()
                 WHERE id = :id AND user_id = :user_id'
            );

            $update->execute([
                'id' => $positionId,
                'user_id' => $userId,
                'quantity' => round($afterQty, 4),
                'cost_price' => round($afterCost, 4),
                'current_price' => $price,
                'status' => $afterStatus,
            ]);

            $insert = $pdo->prepare(
                'INSERT INTO position_trades (
                    user_id, position_id, trade_type, quantity, price, fee, amount, realized_pnl,
                    before_quantity, before_cost_price, after_quantity, after_cost_price,
                    before_status, after_status, note, traded_at, created_at
                 ) VALUES (
                    :user_id, :position_id, :trade_type, :quantity, :price, :fee, :amount, :realized_pnl,
                    :before_quantity, :before_cost_price, :after_quantity, :after_cost_price,
                    :before_status, :after_status, :note, :traded_at, NOW()
                 )'
            );

            $insert->execute([
                'user_id' => $userId,
                'position_id' => $positionId,
                'trade_type' => $tradeType,
                'quantity' => round($quantity, 4),
                'price' => round($price, 4),
                'fee' => round($fee, 4),
                'amount' => round($amount, 4),
                'realized_pnl' => $realizedPnl !== null ? round($realizedPnl, 4) : null,
                'before_quantity' => round($beforeQty, 4),
                'before_cost_price' => round($beforeCost, 4),
                'after_quantity' => round($afterQty, 4),
                'after_cost_price' => round($afterCost, 4),
                'before_status' => $beforeStatus,
                'after_status' => $afterStatus,
                'note' => $note,
                'traded_at' => $tradedAt,
            ]);

            $tradeId = (int) $pdo->lastInsertId();

            AuditService::log('positions.trade', 'position', (string) $positionId, [
                'trade_id' => $tradeId,
                'trade_type' => $tradeType,
                'quantity' => $quantity,
                'price' => $price,
                'fee' => $fee,
                'note' => $note,
            ]);

            $pdo->commit();

            $this->ok([
                'trade_id' => $tradeId,
                'position_id' => $positionId,
                'trade_type' => $tradeType,
                'after_quantity' => round($afterQty, 4),
                'after_cost_price' => round($afterCost, 4),
                'after_status' => $afterStatus,
                'realized_pnl' => $realizedPnl !== null ? round($realizedPnl, 4) : null,
            ], 201);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->fail('trade record failed', 500, ['reason' => $e->getMessage()]);
        }
    }

    public function notes(array $params): void
    {
        $userId = $this->userId();
        $positionId = (int) ($params['id'] ?? 0);
        if ($positionId <= 0) {
            $this->fail('invalid position id', 422);
            return;
        }

        if (!$this->positionOwned($positionId, $userId)) {
            $this->fail('position not found', 404);
            return;
        }

        $limit = max(1, min(200, (int) $this->query('limit', 50)));
        $stmt = Database::connection()->prepare(
            'SELECT *
             FROM position_notes
             WHERE user_id = :user_id AND position_id = :position_id
             ORDER BY created_at DESC, id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':position_id', $positionId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $this->ok([
            'position_id' => $positionId,
            'notes' => $stmt->fetchAll() ?: [],
        ]);
    }

    public function addNote(array $params): void
    {
        $userId = $this->userId();
        $positionId = (int) ($params['id'] ?? 0);
        if ($positionId <= 0) {
            $this->fail('invalid position id', 422);
            return;
        }

        if (!$this->positionOwned($positionId, $userId)) {
            $this->fail('position not found', 404);
            return;
        }

        $body = $this->body();
        $content = trim((string) ($body['content'] ?? ''));
        if ($content === '') {
            $this->fail('content is required', 422);
            return;
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO position_notes (user_id, position_id, note_type, content, action_result, review_score, created_at)
             VALUES (:user_id, :position_id, :note_type, :content, :action_result, :review_score, NOW())'
        );

        $stmt->execute([
            'user_id' => $userId,
            'position_id' => $positionId,
            'note_type' => (string) ($body['note_type'] ?? 'advice'),
            'content' => $content,
            'action_result' => (string) ($body['action_result'] ?? ''),
            'review_score' => $body['review_score'] ?? null,
        ]);

        AuditService::log('positions.add_note', 'position', (string) $positionId, $body);
        $this->ok(['note_id' => (int) Database::connection()->lastInsertId()], 201);
    }

    public function undoTrade(array $params): void
    {
        $userId = $this->userId();
        $positionId = (int) ($params['id'] ?? 0);
        $tradeId = (int) ($params['tradeId'] ?? 0);

        if ($positionId <= 0 || $tradeId <= 0) {
            $this->fail('invalid position/trade id', 422);
            return;
        }

        if (!$this->positionOwned($positionId, $userId)) {
            $this->fail('position not found', 404);
            return;
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $tradeStmt = $pdo->prepare(
                'SELECT *
                 FROM position_trades
                 WHERE id = :id AND user_id = :user_id AND position_id = :position_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $tradeStmt->execute([
                'id' => $tradeId,
                'user_id' => $userId,
                'position_id' => $positionId,
            ]);
            $trade = $tradeStmt->fetch();
            if (!is_array($trade)) {
                throw new \RuntimeException('trade not found');
            }

            $latestStmt = $pdo->prepare(
                'SELECT id
                 FROM position_trades
                 WHERE user_id = :user_id AND position_id = :position_id
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $latestStmt->execute([
                'user_id' => $userId,
                'position_id' => $positionId,
            ]);
            $latestId = (int) ($latestStmt->fetchColumn() ?: 0);
            if ($latestId !== $tradeId) {
                throw new \RuntimeException('only the latest trade can be undone');
            }

            $restore = $pdo->prepare(
                'UPDATE positions
                 SET quantity = :quantity,
                     cost_price = :cost_price,
                     status = :status,
                     updated_at = NOW()
                 WHERE id = :id AND user_id = :user_id'
            );
            $restore->execute([
                'quantity' => (float) ($trade['before_quantity'] ?? 0),
                'cost_price' => (float) ($trade['before_cost_price'] ?? 0),
                'status' => (string) ($trade['before_status'] ?? 'holding'),
                'id' => $positionId,
                'user_id' => $userId,
            ]);

            $delete = $pdo->prepare(
                'DELETE FROM position_trades
                 WHERE id = :id AND user_id = :user_id AND position_id = :position_id'
            );
            $delete->execute([
                'id' => $tradeId,
                'user_id' => $userId,
                'position_id' => $positionId,
            ]);

            AuditService::log('positions.trade.undo', 'position', (string) $positionId, [
                'trade_id' => $tradeId,
                'trade_type' => (string) ($trade['trade_type'] ?? ''),
            ]);

            $pdo->commit();

            $this->ok([
                'position_id' => $positionId,
                'trade_id' => $tradeId,
                'restored_quantity' => (float) ($trade['before_quantity'] ?? 0),
                'restored_cost_price' => (float) ($trade['before_cost_price'] ?? 0),
                'restored_status' => (string) ($trade['before_status'] ?? 'holding'),
            ]);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->fail('undo trade failed', 500, ['reason' => $e->getMessage()]);
        }
    }

    private function loadPositionForUpdate(int $positionId, int $userId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM positions WHERE id = :id AND user_id = :user_id LIMIT 1 FOR UPDATE');
        $stmt->execute([
            'id' => $positionId,
            'user_id' => $userId,
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function positionOwned(int $positionId, int $userId): bool
    {
        $stmt = Database::connection()->prepare('SELECT id FROM positions WHERE id = :id AND user_id = :user_id LIMIT 1');
        $stmt->execute([
            'id' => $positionId,
            'user_id' => $userId,
        ]);

        return (bool) $stmt->fetchColumn();
    }
}
