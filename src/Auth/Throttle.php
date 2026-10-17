<?php

declare(strict_types=1);

namespace Poseidon\Auth;

use Poseidon\Config;

/**
 * Limite les tentatives de connexion : 5 échecs en 10 minutes par adresse IP bloquent la connexion.
 */
final class Throttle
{
    private const MAX = 5;
    private const WINDOW = 600;

    public function __construct(private Config $config)
    {
    }

    public function blocked(string $ip): bool
    {
        return count($this->recent($ip)) >= self::MAX;
    }

    public function fail(string $ip): void
    {
        $times = [...$this->recent($ip), time()];
        $this->write($ip, $times);
    }

    public function clear(string $ip): void
    {
        @unlink($this->file($ip));
    }

    /** @return list<int> */
    private function recent(string $ip): array
    {
        $file = $this->file($ip);
        $times = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];

        return array_values(array_filter($times, static fn ($t): bool => is_int($t) && $t > time() - self::WINDOW));
    }

    private function write(string $ip, array $times): void
    {
        $dir = dirname($this->file($ip));
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        file_put_contents($this->file($ip), json_encode($times), LOCK_EX);
    }

    private function file(string $ip): string
    {
        return (string) $this->config->get('state_dir', POSEIDON_ROOT . '/var/state') . '/login-' . sha1($ip) . '.json';
    }
}
