<?php

declare(strict_types=1);

namespace Poseidon;

/**
 * Accès en lecture à la configuration (config/poseidon.php, surchargeable).
 */
final class Config
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values)
    {
    }

    public static function load(?string $file = null): self
    {
        $file ??= POSEIDON_ROOT . '/config/poseidon.php';
        $values = require $file;
        $local = dirname($file) . '/poseidon.local.php';
        if (is_file($local)) {
            $values = self::merge($values, require $local);
        }

        return new self($values);
    }

    /** @param array<string, mixed> $overrides */
    public function with(array $overrides): self
    {
        return new self(self::merge($this->values, $overrides));
    }

    /**
     * Fusion de configuration : un tableau associatif se fusionne clé par clé,
     * une liste (racines, hôtes…) remplace entièrement la précédente, y compris par une liste vide.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $base[$key] = is_array($value) && !array_is_list($value) && is_array($base[$key] ?? null) && !array_is_list($base[$key])
                ? self::merge($base[$key], $value)
                : $value;
        }

        return $base;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }
}
