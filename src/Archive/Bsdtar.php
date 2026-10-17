<?php

declare(strict_types=1);

namespace Poseidon\Archive;

use Poseidon\Filesystem\UnreadableFileException;
use Poseidon\Support\Process;

/**
 * libarchive (bsdtar) : lecture des archives 7z, RAR, tar.*, ISO, CAB.
 * Présent sous Linux/macOS (bsdtar) et sous Windows 10/11 (tar.exe est bsdtar).
 */
final class Bsdtar
{
    private static string|false|null $binary = null;

    /** Tests : forcer l'absence (false) ou un binaire précis ; null rétablit la détection. */
    public static function override(string|false|null $binary): void
    {
        self::$binary = $binary;
    }

    public static function binary(): ?string
    {
        if (self::$binary === null) {
            self::$binary = false;
            $configured = getenv('POSEIDON_BSDTAR');
            foreach (array_filter([$configured ?: null, Process::which('bsdtar'), Process::which('tar')]) as $candidate) {
                $version = Process::run([$candidate, '--version'], 5);
                if ($version['code'] === 0 && str_contains($version['stdout'], 'bsdtar')) {
                    self::$binary = $candidate;
                    break;
                }
            }
        }

        return self::$binary === false ? null : self::$binary;
    }

    /** @return list<string> Noms des fichiers de l'archive (dossiers exclus). */
    public static function entries(string $archive): array
    {
        $bin = self::binary() ?? throw new UnreadableFileException('bsdtar indisponible pour ce format d\'archive.', UnreadableFileException::UNSUPPORTED);
        $result = Process::run([$bin, '-tf', $archive], 60, 64 * 1024 * 1024);
        if ($result['code'] !== 0) {
            throw self::failure($result['stderr'], 'Archive illisible ou corrompue.');
        }
        $names = [];
        foreach (preg_split('/\r?\n/', $result['stdout']) ?: [] as $name) {
            if ($name !== '' && !str_ends_with($name, '/')) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public static function read(string $archive, string $entry, int $maxSize): string
    {
        $bin = self::binary() ?? throw new UnreadableFileException('bsdtar indisponible pour ce format d\'archive.', UnreadableFileException::UNSUPPORTED);
        $result = Process::run([$bin, '-xOf', $archive, $entry], 120, $maxSize);
        if ($result['overflow']) {
            throw new UnreadableFileException('Entrée trop volumineuse : ignorée.', UnreadableFileException::TOO_LARGE);
        }
        if ($result['timedOut']) {
            throw new UnreadableFileException('Lecture de l\'entrée interrompue : délai dépassé.', UnreadableFileException::TIMEOUT);
        }
        if ($result['code'] !== 0) {
            throw self::failure($result['stderr'], 'Entrée d\'archive illisible.');
        }

        return $result['stdout'];
    }

    private static function failure(string $stderr, string $default): UnreadableFileException
    {
        $err = strtolower($stderr);
        if (str_contains($err, 'encrypt') || str_contains($err, 'passphrase') || str_contains($err, 'password')) {
            return UnreadableFileException::protected('Archive protégée par mot de passe');
        }

        return new UnreadableFileException($default, UnreadableFileException::CORRUPT);
    }
}
