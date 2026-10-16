<?php

declare(strict_types=1);

namespace Poseidon\Filesystem;

use Poseidon\Config;
use Poseidon\Support\Path;

/**
 * Résout une racine saisie par l'utilisateur (Windows, UNC, Linux, file://)
 * et garantit qu'aucun accès ne sort des racines autorisées.
 */
final class RootGuard
{
    /** @var list<string> */
    private array $allowed;

    /** @var array<string, string> */
    private array $mounts = [];

    public function __construct(Config $config)
    {
        foreach ((array) $config->get('network_mounts', []) as $share => $mount) {
            $this->mounts[rtrim(Path::normalize((string) $share), '/')] = rtrim(Path::normalize((string) $mount), '/');
        }
        // Racines stockées sous leur chemin réel : un lien symbolique ne peut pas élargir le périmètre.
        $this->allowed = array_map(
            fn (string $r): string => self::real($this->map(Path::normalize($r))),
            array_values(array_filter((array) $config->get('allowed_roots', []), 'is_string'))
        );
    }

    /** @return list<string> */
    public function allowedRoots(): array
    {
        return $this->allowed;
    }

    public function isRestricted(): bool
    {
        return $this->allowed !== [];
    }

    /**
     * Transforme la saisie en racine exploitable ; lève une exception explicite sinon.
     */
    public function resolveRoot(string $input): string
    {
        if (trim($input) === '') {
            throw new \InvalidArgumentException('Indique un répertoire de recherche.');
        }
        $path = $this->map(Path::normalize($input));

        if (Path::isUnc($path) && DIRECTORY_SEPARATOR !== '\\') {
            throw new \InvalidArgumentException(sprintf(
                'Le partage réseau %s n\'est pas accessible directement sous ce système. Monte-le (ex. /mnt/partage) puis déclare la correspondance dans network_mounts, ou lance Poseidon sous Windows.',
                Path::display($path)
            ));
        }
        $native = Path::toNative($path);
        if (!file_exists($native)) {
            $this->assertAllowed($path);
            throw new \InvalidArgumentException(sprintf('Répertoire introuvable : %s', Path::display($path)));
        }
        // Vérification sur le chemin réel (liens symboliques résolus), pas sur la saisie.
        $real = self::real($path);
        $this->assertAllowed($real);
        if (!is_readable($native)) {
            throw new \InvalidArgumentException(sprintf('Accès refusé au répertoire : %s', Path::display($path)));
        }

        return $real;
    }

    public function assertAllowed(string $path): void
    {
        if ($this->allowed === []) {
            return;
        }
        $path = self::real(Path::normalize($path));
        foreach ($this->allowed as $root) {
            if ($path === rtrim($root, '/') || Path::isInside($path, $root)) {
                return;
            }
        }
        throw new \DomainException(sprintf('Chemin hors des racines autorisées : %s', Path::display($path)));
    }

    /** Chemin réel s'il existe (liens symboliques résolus), sinon le chemin normalisé. */
    public static function real(string $path): string
    {
        $real = @realpath(Path::toNative($path));

        return $real === false ? Path::normalize($path) : Path::normalize($real);
    }

    private function map(string $path): string
    {
        foreach ($this->mounts as $share => $mount) {
            if ($path === $share) {
                return $mount;
            }
            $rel = Path::relativeTo($path, $share);
            if ($rel !== null) {
                return $mount . '/' . $rel;
            }
        }

        return $path;
    }
}
