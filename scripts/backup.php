<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;

$pdo = Database::connection();
$config = require base_path('config/database.php');

$backupDir = base_path('storage/backups');
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0777, true);
}

$startedAt = now_sql();
$jobStmt = $pdo->prepare('INSERT INTO backup_jobs (status, file_path, size_bytes, error_message, started_at, created_at) VALUES (:status, :file_path, :size_bytes, :error_message, :started_at, NOW())');
$jobStmt->execute([
    'status' => 'running',
    'file_path' => null,
    'size_bytes' => null,
    'error_message' => null,
    'started_at' => $startedAt,
]);
$jobId = (int) $pdo->lastInsertId();

$timestamp = (new DateTimeImmutable())->format('Ymd_His');
$sqlFile = $backupDir . DIRECTORY_SEPARATOR . "mysql_backup_{$timestamp}.sql";
$jsonFile = $backupDir . DIRECTORY_SEPARATOR . "json_backup_{$timestamp}.json";

$mysqldump = 'D:\\phpstudy_pro\\Extensions\\MySQL8.0.12\\bin\\mysqldump.exe';

try {
    if (is_file($mysqldump)) {
        $cmd = sprintf(
            '"%s" -h%s -P%s -u%s -p%s %s > "%s"',
            $mysqldump,
            $config['host'],
            $config['port'],
            $config['username'],
            $config['password'],
            $config['database'],
            $sqlFile
        );

        exec($cmd, $output, $code);
        if ($code !== 0 || !is_file($sqlFile)) {
            throw new RuntimeException('mysqldump failed');
        }

        $size = filesize($sqlFile) ?: 0;
        $pdo->prepare('UPDATE backup_jobs SET status = :status, file_path = :file_path, size_bytes = :size_bytes, finished_at = NOW() WHERE id = :id')
            ->execute([
                'status' => 'success',
                'file_path' => $sqlFile,
                'size_bytes' => $size,
                'id' => $jobId,
            ]);

        echo "backup done: {$sqlFile}\n";
        exit(0);
    }

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $bundle = [];

    foreach ($tables as $table) {
        $stmt = $pdo->query('SELECT * FROM `' . $table . '`');
        $bundle[$table] = $stmt ? $stmt->fetchAll() : [];
    }

    file_put_contents($jsonFile, json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $size = filesize($jsonFile) ?: 0;

    $pdo->prepare('UPDATE backup_jobs SET status = :status, file_path = :file_path, size_bytes = :size_bytes, finished_at = NOW() WHERE id = :id')
        ->execute([
            'status' => 'success',
            'file_path' => $jsonFile,
            'size_bytes' => $size,
            'id' => $jobId,
        ]);

    echo "backup done: {$jsonFile}\n";
} catch (Throwable $e) {
    $pdo->prepare('UPDATE backup_jobs SET status = :status, error_message = :error_message, finished_at = NOW() WHERE id = :id')
        ->execute([
            'status' => 'failed',
            'error_message' => $e->getMessage(),
            'id' => $jobId,
        ]);
    fwrite(STDERR, "backup failed: {$e->getMessage()}\n");
    exit(1);
}
