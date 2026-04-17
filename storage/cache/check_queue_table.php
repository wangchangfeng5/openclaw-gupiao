<?php
require __DIR__ . '/../../bootstrap/app.php';
$pdo = App\Core\Database::connection();
$row = $pdo->query("SHOW TABLES LIKE 'openclaw_job_queue'")->fetch();
echo $row ? "queue_table_ok\n" : "queue_table_missing\n";
