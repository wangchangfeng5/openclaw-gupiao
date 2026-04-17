<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Services\AlertService;
use App\Services\AuditService;
use App\Services\OpenClawBridgeService;
use App\Services\OpenClawQueueService;

final class StrategyTemplateController extends BaseController
{
    public function index(): void
    {
        $userId = $this->userId();
        $stmt = Database::connection()->prepare('SELECT * FROM strategy_templates WHERE user_id = :user_id ORDER BY period ASC, id DESC');
        $stmt->execute(['user_id' => $userId]);

        $this->ok(['templates' => $stmt->fetchAll() ?: []]);
    }

    public function store(): void
    {
        $userId = $this->userId();
        $body = $this->body();
        $name = trim((string) ($body['name'] ?? ''));
        $prompt = trim((string) ($body['prompt'] ?? ''));
        $cron = trim((string) ($body['cron_expression'] ?? ''));

        if ($name === '' || $prompt === '' || $cron === '') {
            $this->fail('name, prompt, cron_expression are required', 422);
            return;
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO strategy_templates (user_id, name, period, prompt, cron_expression, timezone, enabled, created_at, updated_at)
             VALUES (:user_id, :name, :period, :prompt, :cron_expression, :timezone, :enabled, NOW(), NOW())'
        );
        $stmt->execute([
            'user_id' => $userId,
            'name' => $name,
            'period' => (string) ($body['period'] ?? 'custom'),
            'prompt' => $prompt,
            'cron_expression' => $cron,
            'timezone' => (string) ($body['timezone'] ?? 'Asia/Shanghai'),
            'enabled' => (int) (($body['enabled'] ?? true) ? 1 : 0),
        ]);

        $id = (int) Database::connection()->lastInsertId();
        AuditService::log('strategy_templates.create', 'strategy_template', (string) $id, $body);

        $this->ok(['id' => $id], 201);
    }

    public function instantiate(array $params): void
    {
        $userId = $this->userId();
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            $this->fail('invalid template id', 422);
            return;
        }

        $stmt = Database::connection()->prepare('SELECT * FROM strategy_templates WHERE id = :id AND user_id = :user_id LIMIT 1');
        $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
        ]);
        $tpl = $stmt->fetch();

        if (!$tpl) {
            $this->fail('template not found', 404);
            return;
        }

        $body = $this->body();

        $bridgePayload = [
            'name' => (string) ($body['name'] ?? ($tpl['name'] . ' #' . date('Ymd'))),
            'description' => (string) ($body['description'] ?? ('from template:' . $tpl['id'])),
            'session' => (string) ($body['session'] ?? 'isolated'),
            'cron' => (string) ($body['cron_expression'] ?? $tpl['cron_expression']),
            'tz' => (string) ($body['timezone'] ?? $tpl['timezone'] ?? 'Asia/Shanghai'),
            'message' => (string) ($body['prompt'] ?? $tpl['prompt']),
            'announce' => (bool) ($body['announce'] ?? false),
            'light_context' => (bool) ($body['light_context'] ?? true),
        ];

        $bridge = new OpenClawBridgeService();
        $result = $bridge->addJob($bridgePayload);
        if (!$result['ok']) {
            $queue = new OpenClawQueueService($userId);
            $reason = (string) ($result['error'] ?? 'unknown');
            $queued = $queue->enqueueCreate($bridgePayload, $reason);

            AlertService::create(
                'cron_degraded',
                'OpenClaw gateway not writable, template job queued',
                'Template instantiate request has been queued locally and will retry automatically.',
                'warning',
                'strategy_template',
                (string) $id,
                $userId
            );

            AuditService::log('strategy_templates.instantiate.queued', 'strategy_template', (string) $id, [
                'payload' => $bridgePayload,
                'reason' => $reason,
                'queue_id' => $queued['queue_id'],
                'job_id' => $queued['local_job_id'],
            ]);

            $this->ok([
                'template_id' => $id,
                'queued' => true,
                'degraded' => true,
                'queue_id' => $queued['queue_id'],
                'job_id' => $queued['local_job_id'],
                'reason' => $reason,
            ], 202);
            return;
        }

        $bridge->syncJobsToDb($userId);
        AuditService::log('strategy_templates.instantiate', 'strategy_template', (string) $id, $bridgePayload);

        $this->ok([
            'template_id' => $id,
            'queued' => false,
            'degraded' => false,
            'result' => $result['json'] ?? trim((string) $result['stdout']),
        ]);
    }
}
