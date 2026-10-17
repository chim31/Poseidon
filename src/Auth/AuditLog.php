<?php

declare(strict_types=1);

namespace Poseidon\Auth;

use Poseidon\Config;

/**
 * Journal d'audit (var/log/audit.log, une ligne JSON par action) : qui, quand, d'où, quoi.
 * Rotation simple au-delà de 10 Mo (audit.log.1).
 */
final class AuditLog
{
    private const MAX_SIZE = 10 * 1024 * 1024;

    public function __construct(private Config $config)
    {
    }

    public function file(): string
    {
        return (string) $this->config->get('audit_file', POSEIDON_ROOT . '/var/log/audit.log');
    }

    /** @param array<string, mixed> $details */
    public function log(string $action, string $user, array $details = []): void
    {
        if ($this->config->get('audit', true) === false) {
            return;
        }
        $file = $this->file();
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0700, true);
        }
        if (is_file($file) && filesize($file) > self::MAX_SIZE) {
            @rename($file, $file . '.1');
        }
        $entry = [
            'time' => date(DATE_ATOM),
            'user' => $user,
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'),
            'action' => $action,
        ] + array_map(static fn ($v) => is_string($v) ? mb_substr($v, 0, 500) : $v, $details);
        @file_put_contents($file, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }

    /** @return list<array<string, mixed>> Dernières entrées, la plus récente d'abord. */
    public function tail(int $count = 200, ?string $user = null): array
    {
        $file = $this->file();
        if (!is_file($file)) {
            return [];
        }
        $lines = array_reverse(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        $out = [];
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry) && ($user === null || ($entry['user'] ?? '') === $user)) {
                $out[] = $entry;
                if (count($out) >= $count) {
                    break;
                }
            }
        }

        return $out;
    }
}
