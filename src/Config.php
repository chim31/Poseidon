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
            $values = array_replace_recursive($values, require $local);
        }

        return new self($values);
    }

    /** @param array<string, mixed> $overrides */
    public function with(array $overrides): self
    {
        return new self(array_replace_recursive($this->values, $overrides));
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
