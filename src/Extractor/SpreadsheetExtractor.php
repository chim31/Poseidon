<?php

declare(strict_types=1);

namespace Poseidon\Extractor;

use Poseidon\Filesystem\Source;
use Poseidon\Filesystem\UnreadableFileException;
use Poseidon\Support\Text;

/**
 * XLSX / XLSM (Excel 2007+) et ODS (LibreOffice Calc), sans ext-dom :
 * valeurs des cellules, feuille par feuille, + tableau pour le mapping de colonnes.
 */
final class SpreadsheetExtractor implements Extractor
{
    /** Limites d'Excel : au-delà, la valeur est ignorée (fichier malformé ou piégé). */
    public const MAX_COLUMNS = 16384;
    public const MAX_ROWS = 1048576;

    public function extensions(): array
    {
        return ['xlsx', 'xlsm', 'xltx', 'ods'];
    }

    public function extract(Source $source): Document
    {
        $office = OfficeArchive::open($source);
        try {
            $tables = $source->extension() === 'ods' ? $this->ods($office) : $this->xlsx($office);
        } finally {
            $office->close();
        }

        $lines = [];
        foreach ($tables as $table) {
            foreach ($table['rows'] as $rowNumber => $cells) {
                $parts = [];
                foreach ($cells as $col => $value) {
                    if ($value !== '') {
                        $parts[] = $value;
                    }
                }
                if ($parts !== []) {
                    $lines[] = [$table['name'] . ' · L' . $rowNumber, implode(' | ', $parts)];
                }
            }
        }

        return new Document($lines, $tables);
    }

    /** @return list<array{name: string, rows: array<int, array<int, string>>}> */
    private function xlsx(OfficeArchive $office): array
    {
        $workbook = $office->read('xl/workbook.xml');
        if ($workbook === null) {
            throw new UnreadableFileException('Classeur Excel sans feuille lisible.', UnreadableFileException::CORRUPT);
        }
        $shared = $this->sharedStrings((string) $office->read('xl/sharedStrings.xml'));

        // Feuilles déclarées : nom + identifiant de relation.
        $targets = [];
        if (preg_match_all('#<Relationship\b[^>]*>#', (string) $office->read('xl/_rels/workbook.xml.rels'), $rels)) {
            foreach ($rels[0] as $rel) {
                if (preg_match('#Id="([^"]+)"#', $rel, $id) && preg_match('#Target="([^"]+)"#', $rel, $target)) {
                    $path = ltrim($target[1], '/');
                    $targets[$id[1]] = str_starts_with($path, 'xl/') ? $path : 'xl/' . $path;
                }
            }
        }

        $tables = [];
        preg_match_all('#<sheet\b[^>]*>#', $workbook, $sheets);
        foreach ($sheets[0] as $n => $tag) {
            preg_match('#name="([^"]*)"#', $tag, $name);
            preg_match('#r:id="([^"]+)"#', $tag, $rid);
            $sheetName = Text::xmlDecode($name[1] ?? ('Feuille' . ($n + 1)));
            $file = $targets[$rid[1] ?? ''] ?? 'xl/worksheets/sheet' . ($n + 1) . '.xml';
            $xml = $office->read($file);
            if ($xml === null) {
                continue;
            }
            $tables[] = ['name' => $sheetName, 'rows' => $this->sheetRows($xml, $shared)];
        }

        return $tables;
    }

    /** @return list<string> */
    private function sharedStrings(string $xml): array
    {
        $strings = [];
        preg_match_all('#<si>(.*?)</si>#s', $xml, $items);
        foreach ($items[1] as $item) {
            // Ignore les annotations phonétiques (<rPh>).
            $item = preg_replace('#<rPh\b.*?</rPh>#s', '', $item) ?? $item;
            preg_match_all('#<t(?:\s[^>]*)?>(.*?)</t>#s', $item, $t);
            $strings[] = Text::xmlDecode(implode('', $t[1]));
        }

        return $strings;
    }

    /**
     * @param list<string> $shared
     * @return array<int, array<int, string>>
     */
    private function sheetRows(string $xml, array $shared): array
    {
        $rows = [];
        preg_match_all('#<row\b([^>]*)>(.*?)</row>#s', $xml, $rowMatches, PREG_SET_ORDER);
        $auto = 0;
        foreach ($rowMatches as $row) {
            $auto++;
            $rowNumber = preg_match('#\br="(\d{1,7})"#', $row[1], $r) ? (int) $r[1] : $auto;
            if ($rowNumber > self::MAX_ROWS) {
                break;
            }
            $auto = $rowNumber;
            $cells = [];
            $col = -1;
            preg_match_all('#<c\b([^>]*?)(?:/>|>(.*?)</c>)#s', $row[2], $cellMatches, PREG_SET_ORDER);
            foreach ($cellMatches as $cell) {
                $attrs = $cell[1];
                $inner = $cell[2] ?? '';
                $col = preg_match('#\br="([A-Z]+)\d+"#', $attrs, $ref) ? (strlen($ref[1]) > 3 ? self::MAX_COLUMNS : Text::columnIndex($ref[1])) : $col + 1;
                if ($col >= self::MAX_COLUMNS) {
                    continue;
                }
                $type = preg_match('#\bt="([^"]+)"#', $attrs, $t) ? $t[1] : 'n';
                $value = '';
                if ($type === 'inlineStr') {
                    preg_match_all('#<t(?:\s[^>]*)?>(.*?)</t>#s', $inner, $parts);
                    $value = Text::xmlDecode(implode('', $parts[1]));
                } elseif (preg_match('#<v>(.*?)</v>#s', $inner, $v)) {
                    $raw = Text::xmlDecode($v[1]);
                    $value = match ($type) {
                        's' => $shared[(int) $raw] ?? '',
                        'b' => $raw === '1' ? 'VRAI' : 'FAUX',
                        default => $raw,
                    };
                }
                $cells[$col] = $value;
            }
            if ($cells !== []) {
                ksort($cells);
                $rows[$rowNumber] = $cells;
            }
        }

        return $rows;
    }

    /** @return list<array{name: string, rows: array<int, array<int, string>>}> */
    private function ods(OfficeArchive $office): array
    {
        $xml = $office->read('content.xml');
        if ($xml === null) {
            throw new UnreadableFileException('Classeur ODS sans contenu lisible.', UnreadableFileException::CORRUPT);
        }
        $tables = [];
        preg_match_all('#<table:table\b([^>]*)>(.*?)</table:table>#s', $xml, $sheetMatches, PREG_SET_ORDER);
        foreach ($sheetMatches as $sheet) {
            $name = preg_match('#table:name="([^"]*)"#', $sheet[1], $n) ? Text::xmlDecode($n[1]) : 'Feuille';
            $rows = [];
            $rowNumber = 0;
            preg_match_all('#<table:table-row\b([^>]*?)(?:/>|>(.*?)</table:table-row>)#s', $sheet[2], $rowMatches, PREG_SET_ORDER);
            foreach ($rowMatches as $row) {
                $repeatRows = preg_match('#number-rows-repeated="(\d+)"#', $row[1], $rr) ? (int) $rr[1] : 1;
                $cells = [];
                $col = 0;
                preg_match_all('#<table:(?:covered-)?table-cell\b([^>]*?)(?:/>|>(.*?)</table:(?:covered-)?table-cell>)#s', $row[2] ?? '', $cellMatches, PREG_SET_ORDER);
                foreach ($cellMatches as $cell) {
                    $repeat = preg_match('#number-columns-repeated="(\d+)"#', $cell[1], $cr) ? (int) $cr[1] : 1;
                    preg_match_all('#<text:p\b[^>]*>(.*?)</text:p>#s', $cell[2] ?? '', $p);
                    $value = trim(implode(' ', array_map([Text::class, 'stripXml'], $p[1])));
                    if ($value !== '') {
                        for ($k = 0; $k < min($repeat, 256) && $col + $k < self::MAX_COLUMNS; $k++) {
                            $cells[$col + $k] = $value;
                        }
                    }
                    $col = min(self::MAX_COLUMNS, $col + $repeat);
                }
                $rowNumber += 1;
                if ($cells !== []) {
                    $rows[$rowNumber] = $cells;
                }
                $rowNumber += $repeatRows - 1;
                if ($rowNumber > self::MAX_ROWS) {
                    break;
                }
            }
            $tables[] = ['name' => $name, 'rows' => $rows];
        }

        return $tables;
    }
}
