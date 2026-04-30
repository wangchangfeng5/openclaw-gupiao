<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditService;
use App\Services\DailyReviewService;

final class DailyReviewController extends BaseController
{
    private DailyReviewService $service;

    public function __construct()
    {
        $this->service = new DailyReviewService();
    }

    public function index(): void
    {
        $userId = $this->userId();
        $days = max(7, min(365, (int) $this->query('days', 60)));
        $rows = $this->service->listForUser($userId, $days);

        $this->ok([
            'days' => $days,
            'reviews' => $rows,
            'generated_at' => now_sql(),
        ]);
    }

    public function latest(): void
    {
        $userId = $this->userId();
        $row = $this->service->latestForUser($userId);
        $this->ok([
            'review' => $row,
            'generated_at' => now_sql(),
        ]);
    }

    public function generate(): void
    {
        $userId = $this->userId();
        $body = $this->body();
        $reviewDate = isset($body['review_date']) ? (string) $body['review_date'] : null;
        $slot = (string) ($body['slot'] ?? 'manual');

        $row = $this->service->generateForUser($userId, $reviewDate, $slot);
        if ($row === []) {
            $this->fail('generate review failed', 500);
            return;
        }

        AuditService::log('daily_review.generate', 'daily_review', (string) ($row['id'] ?? ''), [
            'review_date' => $row['review_date'] ?? null,
            'slot' => $slot,
        ]);

        $this->ok([
            'review' => $row,
            'generated_at' => now_sql(),
        ]);
    }

    public function update(array $params): void
    {
        $userId = $this->userId();
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            $this->fail('invalid review id', 422);
            return;
        }

        $row = $this->service->updateSelfReview($userId, $id, $this->body());
        if ($row === null) {
            $this->fail('review not found', 404);
            return;
        }

        AuditService::log('daily_review.update', 'daily_review', (string) $id, [
            'review_date' => $row['review_date'] ?? null,
        ]);

        $this->ok([
            'review' => $row,
            'updated_at' => now_sql(),
        ]);
    }
}
