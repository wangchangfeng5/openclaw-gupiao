<?php

declare(strict_types=1);

namespace App\Services;

final class IngestTargetService
{
    private const FILE_RELATIVE_PATH = 'storage/cache/ingest_target.json';

    public static function setUsername(string $username): void
    {
        $username = trim($username);
        if ($username === '') {
            return;
        }

        $payload = [
            'username' => $username,
            'updated_at' => now_sql(),
        ];

        self::writeJson($payload);
    }

    public static function getUsername(): ?string
    {
        $data = self::readJson();
        $username = trim((string) ($data['username'] ?? ''));
        return $username !== '' ? $username : null;
    }

    private static function path(): string
    {
        return base_path(self::FILE_RELATIVE_PATH);
    }

    private static function readJson(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function writeJson(array $payload): void
    {
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        @file_put_contents(
            $path,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }
}

