<?php

declare(strict_types=1);

namespace Poseidon\Support;

/**
 * Jetons signés (HMAC-SHA256) : un identifiant de fichier n'est valide que s'il a été
 * émis par Poseidon lors d'une recherche. Empêche de forger un identifiant pour lire
 * un fichier arbitraire du serveur.
 */
final class Token
{
    private static ?string $key = null;

    public static function init(string $keyFile): void
    {
        if (is_file($keyFile)) {
            $key = trim((string) file_get_contents($keyFile));
        } else {
            $key = bin2hex(random_bytes(32));
            if (!is_dir(dirname($keyFile))) {
                @mkdir(dirname($keyFile), 0700, true);
            }
            @file_put_contents($keyFile, $key, LOCK_EX);
            @chmod($keyFile, 0600);
        }
        self::$key = $key;
    }

    public static function sign(string $payload): string
    {
        $body = self::b64($payload);

        return $body . '.' . self::b64(substr(hash_hmac('sha256', $body, self::key(), true), 0, 16));
    }

    public static function verify(string $token): string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException('Identifiant de fichier invalide.');
        }
        $expected = self::b64(substr(hash_hmac('sha256', $parts[0], self::key(), true), 0, 16));
        if (!hash_equals($expected, $parts[1])) {
            throw new \InvalidArgumentException('Identifiant de fichier invalide ou expiré.');
        }
        $payload = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if ($payload === false) {
            throw new \InvalidArgumentException('Identifiant de fichier invalide.');
        }

        return $payload;
    }

    private static function key(): string
    {
        if (self::$key === null) {
            self::init(POSEIDON_ROOT . '/var/secret.key');
        }

        return (string) self::$key;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
