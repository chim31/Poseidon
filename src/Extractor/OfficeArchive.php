<?php

declare(strict_types=1);

namespace Poseidon\Extractor;

use Poseidon\Archive\ZipHandle;
use Poseidon\Filesystem\Source;
use Poseidon\Filesystem\UnreadableFileException;

/**
 * Lecture des formats bureautiques « ZIP + XML » (DOCX, XLSX, PPTX, ODT, ODS, ODP).
 * Détecte les documents Office chiffrés, qui sont alors des conteneurs OLE et non des ZIP.
 */
final class OfficeArchive
{
    private const OLE_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    /** Taille maximale d'une partie XML décompressée, et ratio de compression toléré (anti-bombe). */
    private const MAX_PART_SIZE = 150 * 1024 * 1024;
    private const MAX_RATIO = 200;

    private function __construct(private ZipHandle $handle)
    {
    }

    public static function open(Source $source): self
    {
        $head = $source->inArchive() ? substr($source->bytes(), 0, 8) : self::head($source);
        if ($head === self::OLE_SIGNATURE) {
            throw UnreadableFileException::protected('Document Office protégé par mot de passe');
        }
        try {
            return new self(ZipHandle::open($source));
        } catch (UnreadableFileException $e) {
            throw new UnreadableFileException('Document bureautique corrompu ou illisible.', $e->reason);
        }
    }

    public function read(string $name): ?string
    {
        $stat = $this->handle->zip->statName($name);
        if ($stat === false) {
            return null;
        }
        $size = (int) $stat['size'];
        if ($size > self::MAX_PART_SIZE || ($size > 1024 * 1024 && intdiv($size, max(1, (int) $stat['comp_size'])) > self::MAX_RATIO)) {
            throw new UnreadableFileException('Document bureautique suspect (partie interne démesurée) : ignoré.', UnreadableFileException::LIMIT);
        }
        $data = $this->handle->zip->getFromName($name);

        return $data === false ? null : $data;
    }

    /** @return list<string> Noms d'entrées correspondant au motif, triés naturellement. */
    public function names(string $pattern): array
    {
        $names = [];
        for ($i = 0; $i < $this->handle->zip->numFiles; $i++) {
            $name = (string) $this->handle->zip->getNameIndex($i);
            if (preg_match($pattern, $name)) {
                $names[] = $name;
            }
        }
        natsort($names);

        return array_values($names);
    }

    public function close(): void
    {
        $this->handle->close();
    }

    private static function head(Source $source): string
    {
        $fh = @fopen($source->diskPath, 'rb');
        if ($fh === false) {
            throw new UnreadableFileException('Lecture impossible : droits insuffisants ou fichier verrouillé.');
        }
        $head = (string) fread($fh, 8);
        fclose($fh);

        return $head;
    }
}
