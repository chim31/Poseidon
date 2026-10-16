<?php

declare(strict_types=1);

namespace Poseidon\Support;

/**
 * Normalisation des chemins : slash / anti-slash, lettres de lecteur Windows,
 * partages réseau UNC (\\serveur\partage), segments « . » et « .. ».
 *
 * Forme interne : séparateur « / », UNC conservé sous la forme « //serveur/partage/... ».
 */
final class Path
{
    public static function normalize(string $path): string
    {
        $path = trim($path);
        // Retire des guillemets collés autour d'un chemin copié depuis l'explorateur Windows.
        if (strlen($path) >= 2 && ($path[0] === '"' && str_ends_with($path, '"') || $path[0] === "'" && str_ends_with($path, "'"))) {
            $path = substr($path, 1, -1);
        }
        // file:// et smb:// copiés depuis un navigateur ou un gestionnaire de fichiers.
        if (preg_match('#^file:/{2,3}#i', $path)) {
            $path = rawurldecode(preg_replace('#^file:/{2}(?=/)|^file:/{2}#i', '', $path) ?? $path);
            if (preg_match('#^/[A-Za-z]:#', $path)) {
                $path = substr($path, 1);
            }
        }
        if (preg_match('#^smb://#i', $path)) {
            $path = '//' . rawurldecode(substr($path, 6));
        }

        $path = str_replace('\\', '/', $path);
        $isUnc = str_starts_with($path, '//');
        $prefix = '';

        if ($isUnc) {
            $prefix = '//';
            $path = ltrim($path, '/');
        } elseif (preg_match('#^([A-Za-z]):(/|$)#', $path, $m)) {
            $prefix = strtoupper($m[1]) . ':/';
            $path = substr($path, strlen($m[0]));
        } elseif (str_starts_with($path, '/')) {
            $prefix = '/';
            $path = ltrim($path, '/');
        }

        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                // Sur un UNC, on ne remonte jamais au-dessus de \\serveur\partage.
                if ($parts !== [] && end($parts) !== '..' && !($isUnc && count($parts) <= 2)) {
                    array_pop($parts);
                    continue;
                }
                if ($prefix !== '') {
                    continue;
                }
            }
            $parts[] = $segment;
        }

        $result = $prefix . implode('/', $parts);

        return $result === '' ? '.' : $result;
    }

    public static function isUnc(string $path): bool
    {
        return str_starts_with(str_replace('\\', '/', trim($path)), '//');
    }

    public static function isAbsolute(string $path): bool
    {
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, '/') || (bool) preg_match('#^[A-Za-z]:/#', $path);
    }

    public static function join(string ...$parts): string
    {
        $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));

        return self::normalize(implode('/', $parts));
    }

    /** Chemin relatif de $path par rapport à $root (normalisés), ou null si hors racine. */
    public static function relativeTo(string $path, string $root): ?string
    {
        $path = self::normalize($path);
        $root = rtrim(self::normalize($root), '/');
        if ($root === '' || $path === $root) {
            return $path === $root ? '' : null;
        }
        $compare = self::caseInsensitiveFs($root)
            ? static fn (string $a, string $b): bool => strcasecmp($a, $b) === 0
            : static fn (string $a, string $b): bool => $a === $b;

        $prefix = $root . '/';
        if ($compare(substr($path, 0, strlen($prefix)), $prefix)) {
            return substr($path, strlen($prefix));
        }

        return null;
    }

    public static function isInside(string $path, string $root): bool
    {
        return self::relativeTo($path, $root) !== null;
    }

    /** Forme d'affichage native : anti-slash pour Windows/UNC, slash sinon. */
    public static function display(string $path): string
    {
        $path = self::normalize($path);
        if (self::isUnc($path) || preg_match('#^[A-Z]:/#', $path)) {
            return str_replace('/', '\\', $path);
        }

        return $path;
    }

    /** Chemin utilisable par le système de fichiers courant. */
    public static function toNative(string $path): string
    {
        $path = self::normalize($path);

        return DIRECTORY_SEPARATOR === '\\' ? str_replace('/', '\\', $path) : $path;
    }

    public static function extension(string $path): string
    {
        $base = basename(str_replace('\\', '/', $path));
        $dot = strrpos($base, '.');

        return $dot === false || $dot === 0 ? '' : strtolower(substr($base, $dot + 1));
    }

    private static function caseInsensitiveFs(string $root): bool
    {
        return DIRECTORY_SEPARATOR === '\\' || self::isUnc($root) || (bool) preg_match('#^[A-Z]:/#', $root);
    }
}
