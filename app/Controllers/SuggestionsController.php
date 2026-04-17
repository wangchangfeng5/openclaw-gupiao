<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Services\AlertService;
use App\Services\AuditService;

final class SuggestionsController extends BaseController
{
    public function index(): void
    {
        $userId = $this->userId();
        $limit = max(1, min(200, (int) $this->query('limit', 50)));
        $stmt = Database::connection()->prepare('SELECT * FROM openclaw_suggestions WHERE user_id = :user_id ORDER BY suggested_at DESC, id DESC LIMIT :limit');
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $this->ok(['suggestions' => $rows]);
    }

    public function manualStore(): void
    {
        $userId = $this->userId();
        $body = $this->body();
        $content = trim((string) ($body['content'] ?? ''));
        if ($content === '') {
            $this->fail('content is required', 422);
            return;
        }

        $symbols = $body['symbols'] ?? [];
        $tags = $body['tags'] ?? ['manual'];

        $stmt = Database::connection()->prepare(
            'INSERT INTO openclaw_suggestions (
                user_id, session_id, message_id, source_file, suggested_at, content, symbols_json,
                tags_json, confidence, ingest_source, status, created_at, updated_at
            ) VALUES (
                :user_id, :session_id, :message_id, :source_file, :suggested_at, :content, :symbols_json,
                :tags_json, :confidence, :ingest_source, :status, NOW(), NOW()
            )'
        );

        $stmt->execute([
            'user_id' => $userId,
            'session_id' => (string) ($body['session_id'] ?? 'manual'),
            'message_id' => (string) ($body['message_id'] ?? ('manual-' . uniqid('', true))),
            'source_file' => (string) ($body['source_file'] ?? ''),
            'suggested_at' => (string) ($body['suggested_at'] ?? now_sql()),
            'content' => $content,
            'symbols_json' => json_encode(is_array($symbols) ? $symbols : [], JSON_UNESCAPED_UNICODE),
            'tags_json' => json_encode(is_array($tags) ? $tags : ['manual'], JSON_UNESCAPED_UNICODE),
            'confidence' => (float) ($body['confidence'] ?? 70),
            'ingest_source' => 'manual',
            'status' => 'new',
        ]);

        $id = (int) Database::connection()->lastInsertId();

        AlertService::create('new_suggestion', 'New suggestion', mb_substr($content, 0, 80), 'info', 'suggestion', (string) $id, $userId);
        AuditService::log('suggestions.manual_store', 'suggestion', (string) $id, $body);

        $this->ok(['id' => $id], 201);
    }

    public function feedback(array $params): void
    {
        $userId = $this->userId();
        $suggestionId = (int) ($params['id'] ?? 0);
        if ($suggestionId <= 0) {
            $this->fail('invalid suggestion id', 422);
            return;
        }

        if (!$this->suggestionOwned($suggestionId, $userId)) {
            $this->fail('suggestion not found', 404);
            return;
        }

        $body = $this->body();
        $positionId = isset($body['position_id']) ? (int) $body['position_id'] : null;
        if ($positionId !== null && $positionId > 0 && !$this->positionOwned($positionId, $userId)) {
            $this->fail('position not found', 404);
            return;
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO advice_feedback (
                user_id, suggestion_id, position_id, action_taken, outcome, review_score, review_note, reviewed_at, created_at
             ) VALUES (
                :user_id, :suggestion_id, :position_id, :action_taken, :outcome, :review_score, :review_note, NOW(), NOW()
             )'
        );

        $stmt->execute([
            'user_id' => $userId,
            'suggestion_id' => $suggestionId,
            'position_id' => $positionId,
            'action_taken' => (string) ($body['action_taken'] ?? ''),
            'outcome' => (string) ($body['outcome'] ?? ''),
            'review_score' => $body['review_score'] ?? null,
            'review_note' => (string) ($body['review_note'] ?? ''),
        ]);

        $update = Database::connection()->prepare('UPDATE openclaw_suggestions SET status = :status, updated_at = NOW() WHERE id = :id AND user_id = :user_id');
        $update->execute([
            'status' => (string) ($body['status'] ?? 'reviewed'),
            'id' => $suggestionId,
            'user_id' => $userId,
        ]);

        AuditService::log('suggestions.feedback', 'suggestion', (string) $suggestionId, $body);
        $this->ok(['id' => (int) Database::connection()->lastInsertId()], 201);
    }

    public function metrics(): void
    {
        $userId = $this->userId();
        $pdo = Database::connection();

        $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM advice_feedback WHERE user_id = :user_id');
        $totalStmt->execute(['user_id' => $userId]);
        $total = (int) ($totalStmt->fetchColumn() ?: 0);

        $winStmt = $pdo->prepare("SELECT COUNT(*) FROM advice_feedback WHERE user_id = :user_id AND outcome IN ('profit', 'win', 'positive')");
        $winStmt->execute(['user_id' => $userId]);
        $win = (int) ($winStmt->fetchColumn() ?: 0);

        $avgStmt = $pdo->prepare('SELECT COALESCE(AVG(review_score), 0) FROM advice_feedback WHERE user_id = :user_id');
        $avgStmt->execute(['user_id' => $userId]);
        $avgScore = (float) ($avgStmt->fetchColumn() ?: 0);

        $this->ok([
            'total_feedback' => $total,
            'win_feedback' => $win,
            'win_rate_pct' => $total > 0 ? round($win / $total * 100, 2) : 0,
            'avg_review_score' => round($avgScore, 2),
        ]);
    }

    private function suggestionOwned(int $suggestionId, int $userId): bool
    {
        $stmt = Database::connection()->prepare('SELECT id FROM openclaw_suggestions WHERE id = :id AND user_id = :user_id LIMIT 1');
        $stmt->execute([
            'id' => $suggestionId,
            'user_id' => $userId,
        ]);

        return (bool) $stmt->fetchColumn();
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
