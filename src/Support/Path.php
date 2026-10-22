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
        $raw = trim($path);
        // Retire des guillemets collés autour d'un chemin copié depuis l'explorateur Windows.
        if (strlen($raw) >= 2 && ($raw[0] === '"' && str_ends_with($raw, '"') || $raw[0] === "'" && str_ends_with($raw, "'"))) {
            $raw = substr($raw, 1, -1);
        }
        // file:// et smb:// copiés depuis un navigateur ou un gestionnaire de fichiers.
        if (preg_match('#^file:/{2,3}#i', $raw)) {
            $raw = rawurldecode(preg_replace('#^file:/{2}(?=/)|^file:/{2}#i', '', $raw) ?? $raw);
            if (preg_match('#^/[A-Za-z]:#', $raw)) {
                $raw = substr($raw, 1);
            }
        }
        if (preg_match('#^smb://#i', $raw)) {
            $raw = '//' . rawurldecode(substr($raw, 6));
        }

        $wasBackslashUnc = str_starts_with($raw, '\\\\') || (str_starts_with($raw, '\\') && preg_match('#^\\\\[A-Za-z0-9_\-\.]+[\\/][A-Za-z0-9_\-\.$]+#', $raw));
        $path = str_replace('\\', '/', $raw);
        $isUnc = str_starts_with($path, '//') || $wasBackslashUnc;
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
    public static function toNative(string $path, bool $forDir = false): string
    {
        $path = self::normalize($path);
        $native = DIRECTORY_SEPARATOR === '\\' ? str_replace('/', '\\', $path) : $path;
        if ($forDir && DIRECTORY_SEPARATOR === '\\' && self::isUnc($path) && substr_count(trim($path, '/'), '/') === 1) {
            $native = rtrim($native, '\\') . '\\';
        }

        return $native;
    }

    public static function isDir(string $path): bool
    {
        $path = self::normalize($path);
        $native = self::toNative($path);

        return is_dir($native) || (DIRECTORY_SEPARATOR === '\\' && self::isUnc($path) && is_dir(self::toNative($path, true)));
    }

    public static function fileExists(string $path): bool
    {
        $path = self::normalize($path);
        $native = self::toNative($path);

        return file_exists($native) || (DIRECTORY_SEPARATOR === '\\' && self::isUnc($path) && file_exists(self::toNative($path, true)));
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