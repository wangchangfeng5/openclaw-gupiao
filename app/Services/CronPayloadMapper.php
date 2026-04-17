<?php

declare(strict_types=1);

namespace App\Services;

final class CronPayloadMapper
{
    public static function buildAddArgs(array $payload): array
    {
        $args = ['cron', 'add'];

        self::appendOptional($args, '--name', $payload['name'] ?? null);
        self::appendOptional($args, '--description', $payload['description'] ?? null);
        self::appendOptional($args, '--message', $payload['message'] ?? null);
        self::appendOptional($args, '--system-event', $payload['system_event'] ?? null);
        self::appendOptional($args, '--session', $payload['session'] ?? 'isolated');
        self::appendOptional($args, '--cron', $payload['cron'] ?? null);
        self::appendOptional($args, '--every', $payload['every'] ?? null);
        self::appendOptional($args, '--at', $payload['at'] ?? null);
        self::appendOptional($args, '--tz', $payload['tz'] ?? 'Asia/Shanghai');
        self::appendOptional($args, '--model', $payload['model'] ?? null);
        self::appendOptional($args, '--thinking', $payload['thinking'] ?? null);

        if (($payload['announce'] ?? true) === true) {
            $args[] = '--announce';
            self::appendOptional($args, '--channel', $payload['channel'] ?? null);
            self::appendOptional($args, '--to', $payload['to'] ?? null);
        } else {
            $args[] = '--no-deliver';
        }

        if (($payload['disabled'] ?? false) === true) {
            $args[] = '--disabled';
        }

        if (($payload['light_context'] ?? false) === true) {
            $args[] = '--light-context';
        }

        return $args;
    }

    public static function buildEditArgs(string $jobId, array $payload): array
    {
        $args = ['cron', 'edit', $jobId];

        self::appendOptional($args, '--name', $payload['name'] ?? null);
        self::appendOptional($args, '--description', $payload['description'] ?? null);
        self::appendOptional($args, '--message', $payload['message'] ?? null);
        self::appendOptional($args, '--system-event', $payload['system_event'] ?? null);
        self::appendOptional($args, '--session', $payload['session'] ?? null);
        self::appendOptional($args, '--cron', $payload['cron'] ?? null);
        self::appendOptional($args, '--every', $payload['every'] ?? null);
        self::appendOptional($args, '--at', $payload['at'] ?? null);
        self::appendOptional($args, '--tz', $payload['tz'] ?? null);
        self::appendOptional($args, '--model', $payload['model'] ?? null);
        self::appendOptional($args, '--thinking', $payload['thinking'] ?? null);

        if (array_key_exists('enabled', $payload)) {
            $args[] = ((bool) $payload['enabled']) ? '--enable' : '--disable';
        }

        if (array_key_exists('announce', $payload)) {
            if ((bool) $payload['announce']) {
                $args[] = '--announce';
                self::appendOptional($args, '--channel', $payload['channel'] ?? null);
                self::appendOptional($args, '--to', $payload['to'] ?? null);
            } else {
                $args[] = '--no-deliver';
            }
        }

        if (array_key_exists('light_context', $payload)) {
            $args[] = ((bool) $payload['light_context']) ? '--light-context' : '--no-light-context';
        }

        return $args;
    }

    private static function appendOptional(array &$args, string $flag, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $args[] = $flag;
        $args[] = (string) $value;
    }
}
