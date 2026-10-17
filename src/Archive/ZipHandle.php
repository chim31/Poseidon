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
        if ($source->inMemory()) {
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
