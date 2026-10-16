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
        $this->allowed = array_map(
            fn (string $r): string => $this->map(Path::normalize($r)),
            array_values(array_filter((array) $config->get('allowed_roots', []), 'is_string'))
        );
        foreach ((array) $config->get('network_mounts', []) as $share => $mount) {
            $this->mounts[rtrim(Path::normalize((string) $share), '/')] = rtrim(Path::normalize((string) $mount), '/');
        }
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
        $this->assertAllowed($path);

        $native = Path::toNative($path);
        if (!file_exists($native)) {
            throw new \InvalidArgumentException(sprintf('Répertoire introuvable : %s', Path::display($path)));
        }
        if (!is_readable($native)) {
            throw new \InvalidArgumentException(sprintf('Accès refusé au répertoire : %s', Path::display($path)));
        }
        $real = realpath($native);

        return $real === false ? $path : Path::normalize($real);
    }

    public function assertAllowed(string $path): void
    {
        if ($this->allowed === []) {
            return;
        }
        $path = Path::normalize($path);
        foreach ($this->allowed as $root) {
            if ($path === rtrim($root, '/') || Path::isInside($path, $root)) {
                return;
            }
        }
        throw new \DomainException(sprintf('Chemin hors des racines autorisées : %s', Path::display($path)));
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
