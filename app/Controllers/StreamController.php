<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Services\AuthService;

final class StreamController
{
    public function events(): void
    {
        $userId = (int) (AuthService::id() ?? 0);
        if ($userId <= 0) {
            http_response_code(401);
            return;
        }

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');

        $lastId = (int) ($_GET['last_id'] ?? 0);
        $pollSeconds = max(1, min(10, (int) env('SSE_POLL_SECONDS', 2)));
        $maxSeconds = max(5, min(60, (int) env('SSE_MAX_SECONDS', 25)));

        $start = time();

        while ((time() - $start) < $maxSeconds) {
            $stmt = Database::connection()->prepare(
                'SELECT id, alert_type, title, message, severity, triggered_at
                 FROM alerts
                 WHERE user_id = :user_id AND id > :last_id
                 ORDER BY id ASC'
            );
            $stmt->execute([
                'user_id' => $userId,
                'last_id' => $lastId,
            ]);
            $alerts = $stmt->fetchAll() ?: [];

            foreach ($alerts as $alert) {
                $lastId = max($lastId, (int) $alert['id']);
                echo 'id: ' . $alert['id'] . "\n";
                echo "event: alert\n";
                echo 'data: ' . json_encode($alert, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            }

            $heartbeat = [
                'server_time' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'last_id' => $lastId,
            ];
            echo "event: heartbeat\n";
            echo 'data: ' . json_encode($heartbeat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";

            @ob_flush();
            flush();
            sleep($pollSeconds);
        }
    }
}
