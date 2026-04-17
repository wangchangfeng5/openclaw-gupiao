<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;

$dbConfig = require base_path('config/database.php');
$bootstrapDsn = sprintf('mysql:host=%s;port=%s;charset=%s', $dbConfig['host'], $dbConfig['port'], $dbConfig['charset']);
$bootstrapPdo = new PDO($bootstrapDsn, $dbConfig['username'], $dbConfig['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$bootstrapPdo->exec(sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s_general_ci', $dbConfig['database'], $dbConfig['charset'], $dbConfig['charset']));

$pdo = Database::connection();

$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL UNIQUE,
    migrated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$applied = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$applied = is_array($applied) ? $applied : [];

$files = glob(base_path('database/migrations/*.sql')) ?: [];
sort($files);

$runCount = 0;

foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        echo "[SKIP] {$name}\n";
        continue;
    }

    echo "[RUN ] {$name}\n";
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException("Cannot read migration {$name}");
    }

    $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql) ?: []));
    $pdo->beginTransaction();
    try {
        foreach ($statements as $statement) {
            if ($statement === '') {
                continue;
            }
            $pdo->exec($statement);
        }

        $stmt = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
        $stmt->execute(['migration' => $name]);
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        $runCount++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "[ERR ] {$name}: " . $e->getMessage() . PHP_EOL);
        throw $e;
    }
}

echo "Done. Applied {$runCount} migration(s).\n";
