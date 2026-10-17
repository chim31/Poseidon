<?php

declare(strict_types=1);

namespace Poseidon\Support;

use Poseidon\Filesystem\UnreadableFileException;

/**
 * Lecteur du format « Compound File Binary » (OLE2) de Microsoft : conteneur des .msg Outlook
 * et des anciens .doc/.xls/.ppt. Lecture seule, en mémoire, avec gardes contre les chaînes cycliques.
 */
final class Cfb
{
    public const SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    private const END = 0xFFFFFFFE;
    private const FREE = 0xFFFFFFFF;
    private const NONE = 0xFFFFFFFF;

    private int $sectorSize;
    private int $miniSectorSize;
    private int $cutoff;

    /** @var list<int> */
    private array $fat = [];

    /** @var list<int> */
    private array $miniFat = [];

    private string $miniStream = '';

    /** @var list<array{name: string, type: int, left: int, right: int, child: int, start: int, size: int}> */
    private array $entries = [];

    /** @var array<string, int> Chemin complet => index d'entrée. */
    private array $paths = [];

    private function __construct(private string $data)
    {
    }

    public static function open(string $data): self
    {
        if (!str_starts_with($data, self::SIGNATURE) || strlen($data) < 512) {
            throw new UnreadableFileException('Conteneur OLE invalide.', UnreadableFileException::CORRUPT);
        }
        $cfb = new self($data);
        $cfb->load();

        return $cfb;
    }

    /** @return list<string> Chemins des flux et dossiers sous un préfixe (« » pour la racine). */
    public function children(string $storage = ''): array
    {
        $out = [];
        foreach (array_keys($this->paths) as $path) {
            $parent = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';
            if ($parent === $storage) {
                $out[] = $path;
            }
        }

        return $out;
    }

    public function has(string $path): bool
    {
        return isset($this->paths[$path]);
    }

    public function read(string $path): ?string
    {
        $index = $this->paths[$path] ?? null;
        if ($index === null || $this->entries[$index]['type'] !== 2) {
            return null;
        }
        $entry = $this->entries[$index];
        if ($entry['size'] < $this->cutoff) {
            return $this->readMini($entry['start'], $entry['size']);
        }

        return substr($this->chainData($entry['start']), 0, $entry['size']);
    }

    private function load(): void
    {
        $h = $this->data;
        $major = self::u16($h, 0x1A);
        $this->sectorSize = 1 << self::u16($h, 0x1E);
        $this->miniSectorSize = 1 << self::u16($h, 0x20);
        $this->cutoff = self::u32($h, 0x38);
        if (!in_array($this->sectorSize, [512, 4096], true) || !in_array($major, [3, 4], true)) {
            throw new UnreadableFileException('Conteneur OLE non pris en charge.', UnreadableFileException::CORRUPT);
        }

        // Table d'allocation (FAT) : secteurs listés dans le DIFAT.
        $difat = [];
        for ($i = 0; $i < 109; $i++) {
            $sector = self::u32($h, 0x4C + $i * 4);
            if ($sector < self::END) {
                $difat[] = $sector;
            }
        }
        $next = self::u32($h, 0x44);
        $guard = 0;
        while ($next < self::END && $guard++ < 10000) {
            $block = $this->sector($next);
            $perSector = intdiv($this->sectorSize, 4) - 1;
            for ($i = 0; $i < $perSector; $i++) {
                $sector = self::u32($block, $i * 4);
                if ($sector < self::END) {
                    $difat[] = $sector;
                }
            }
            $next = self::u32($block, $perSector * 4);
        }
        foreach ($difat as $sector) {
            $block = $this->sector($sector);
            for ($i = 0; $i < $this->sectorSize; $i += 4) {
                $this->fat[] = self::u32($block, $i);
            }
        }

        // Répertoire.
        $dir = $this->chainData(self::u32($h, 0x30));
        for ($off = 0; $off + 128 <= strlen($dir); $off += 128) {
            $nameLength = self::u16($dir, $off + 64);
            $name = $nameLength > 2 ? mb_convert_encoding(substr($dir, $off, min(64, $nameLength) - 2), 'UTF-8', 'UTF-16LE') : '';
            $this->entries[] = [
                'name' => $name,
                'type' => ord($dir[$off + 66]),
                'left' => self::u32($dir, $off + 68),
                'right' => self::u32($dir, $off + 72),
                'child' => self::u32($dir, $off + 76),
                'start' => self::u32($dir, $off + 116),
                'size' => self::u32($dir, $off + 120),
            ];
        }
        if ($this->entries === [] || $this->entries[0]['type'] !== 5) {
            throw new UnreadableFileException('Conteneur OLE sans racine.', UnreadableFileException::CORRUPT);
        }

        // Mini-flux (petits flux de moins de 4 096 octets).
        $miniFat = $this->chainData(self::u32($h, 0x3C));
        for ($i = 0; $i + 4 <= strlen($miniFat); $i += 4) {
            $this->miniFat[] = self::u32($miniFat, $i);
        }
        $root = $this->entries[0];
        $this->miniStream = $root['start'] < self::END ? substr($this->chainData($root['start']), 0, $root['size']) : '';

        $visited = [];
        $this->index($root['child'], '', $visited);
    }

    /** @param array<int, true> $visited */
    private function index(int $id, string $prefix, array &$visited): void
    {
        if ($id === self::NONE || !isset($this->entries[$id]) || isset($visited[$id])) {
            return;
        }
        $visited[$id] = true;
        $entry = $this->entries[$id];
        $this->index($entry['left'], $prefix, $visited);
        $path = ($prefix === '' ? '' : $prefix . '/') . $entry['name'];
        $this->paths[$path] = $id;
        if ($entry['type'] === 1) {
            $this->index($entry['child'], $path, $visited);
        }
        $this->index($entry['right'], $prefix, $visited);
    }

    private function sector(int $n): string
    {
        $offset = ($n + 1) * $this->sectorSize;
        if ($offset >= strlen($this->data)) {
            throw new UnreadableFileException('Conteneur OLE tronqué.', UnreadableFileException::CORRUPT);
        }

        return substr($this->data, $offset, $this->sectorSize);
    }

    private function chainData(int $start): string
    {
        $out = '';
        $guard = 0;
        $limit = count($this->fat) + 1;
        for ($n = $start; $n < self::END && $n !== self::FREE; $n = $this->fat[$n] ?? self::END) {
            if (++$guard > $limit) {
                throw new UnreadableFileException('Conteneur OLE corrompu (chaîne cyclique).', UnreadableFileException::CORRUPT);
            }
            $out .= $this->sector($n);
        }

        return $out;
    }

    private function readMini(int $start, int $size): string
    {
        $out = '';
        $guard = 0;
        for ($n = $start; $n < self::END && strlen($out) < $size; $n = $this->miniFat[$n] ?? self::END) {
            if (++$guard > count($this->miniFat) + 1) {
                break;
            }
            $out .= substr($this->miniStream, $n * $this->miniSectorSize, $this->miniSectorSize);
        }

        return substr($out, 0, $size);
    }

    private static function u16(string $s, int $o): int
    {
        return unpack('v', $s, $o)[1] ?? 0;
    }

    private static function u32(string $s, int $o): int
    {
        return unpack('V', $s, $o)[1] ?? 0;
    }
}
