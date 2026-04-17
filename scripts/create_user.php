<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;

$username = isset($argv[1]) ? trim((string) $argv[1]) : '';
$password = isset($argv[2]) ? (string) $argv[2] : '';

if ($username === '' || $password === '') {
    fwrite(STDERR, "Usage: php scripts/create_user.php <username> <password>\n");
    exit(1);
}

if (!preg_match('/^[a-zA-Z0-9._-]{3,64}$/', $username)) {
    fwrite(STDERR, "Invalid username. Allowed: letters/numbers/._- and length 3-64.\n");
    exit(1);
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$pdo = Database::connection();
$exists = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
$exists->execute(['username' => $username]);
if ($exists->fetch()) {
    fwrite(STDERR, "User already exists: {$username}\n");
    exit(2);
}

$hash = password_hash($password, PASSWORD_BCRYPT);
$insert = $pdo->prepare('INSERT INTO users (username, password_hash, created_at, updated_at) VALUES (:username, :password_hash, NOW(), NOW())');
$insert->execute([
    'username' => $username,
    'password_hash' => $hash,
]);

$id = (int) $pdo->lastInsertId();
echo "Created user #{$id}: {$username}\n";
