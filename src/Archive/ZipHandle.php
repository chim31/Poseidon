<?php

declare(strict_types=1);

namespace Poseidon\Archive;

use Poseidon\Filesystem\Source;
use Poseidon\Filesystem\UnreadableFileException;
use Poseidon\Support\Path;

/**
 * Ouvre une archive ZIP, qu'elle soit sur disque ou imbriquée (via un fichier
 * temporaire supprimé à la fermeture).
 */
final class ZipHandle
{
    private function __construct(public readonly \ZipArchive $zip, private ?string $tempFile)
    {
    }

    public static function open(Source $source): self
    {
        $temp = null;
        if ($source->inArchive()) {
            $temp = tempnam(sys_get_temp_dir(), 'poseidon_');
            if ($temp === false || file_put_contents($temp, $source->bytes()) === false) {
                throw new UnreadableFileException('Impossible de créer un fichier temporaire pour l\'archive imbriquée.', UnreadableFileException::DENIED);
            }
            $source->releaseBytes();
            $path = $temp;
        } else {
            $path = Path::toNative($source->diskPath);
        }

        $zip = new \ZipArchive();
        $code = $zip->open($path, \ZipArchive::RDONLY);
        if ($code !== true) {
            if ($temp !== null) {
                @unlink($temp);
            }
            throw new UnreadableFileException(self::describe((int) $code), UnreadableFileException::CORRUPT);
        }

        return new self($zip, $temp);
    }

    /**
     * Lit une entrée au bout d'une chaîne d'archives : a.zip › b.zip › c.txt.
     *
     * @param list<string> $chain
     */
    public static function readChain(string $diskPath, array $chain): string
    {
        if ($chain === []) {
            $data = @file_get_contents(Path::toNative($diskPath));
            if ($data === false) {
                throw new UnreadableFileException('Lecture impossible : droits insuffisants ou fichier verrouillé.');
            }

            return $data;
        }

        $current = new Source($diskPath, [], dirname($diskPath), 0, 0);
        $prefix = [];
        foreach ($chain as $entryName) {
            $handle = self::open($current);
            try {
                $stat = $handle->zip->statName($entryName);
                if ($stat === false) {
                    throw new UnreadableFileException('Entrée introuvable dans l\'archive : ' . $entryName, UnreadableFileException::CORRUPT);
                }
                if (($stat['encryption_method'] ?? 0) !== 0) {
                    throw UnreadableFileException::protected('Entrée d\'archive chiffrée');
                }
                $data = $handle->zip->getFromName($entryName);
                if ($data === false) {
                    throw new UnreadableFileException('Entrée d\'archive illisible : ' . $entryName, UnreadableFileException::CORRUPT);
                }
            } finally {
                $handle->close();
            }
            $prefix[] = $entryName;
            $current = new Source($diskPath, $prefix, dirname($diskPath), strlen($data), 0, static fn (): string => $data);
        }

        return $data;
    }

    public function close(): void
    {
        @$this->zip->close();
        if ($this->tempFile !== null) {
            @unlink($this->tempFile);
            $this->tempFile = null;
        }
    }

    private static function describe(int $code): string
    {
        return match ($code) {
            \ZipArchive::ER_NOZIP => 'Ce fichier n\'est pas une archive ZIP valide.',
            \ZipArchive::ER_INCONS => 'Archive ZIP incohérente ou corrompue.',
            \ZipArchive::ER_OPEN, \ZipArchive::ER_READ => 'Archive illisible : accès refusé ou fichier verrouillé.',
            \ZipArchive::ER_MEMORY => 'Mémoire insuffisante pour ouvrir l\'archive.',
            default => sprintf('Archive ZIP illisible (code %d).', $code),
        };
    }
}
