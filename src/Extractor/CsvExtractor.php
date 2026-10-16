<?php

declare(strict_types=1);

namespace Poseidon\Extractor;

use Poseidon\Filesystem\Source;
use Poseidon\Support\Text;

/**
 * CSV / TSV : texte ligne à ligne + tableau de cellules pour l'extraction par colonnes.
 */
final class CsvExtractor implements Extractor
{
    public function extensions(): array
    {
        return ['csv', 'tsv'];
    }

    public function extract(Source $source): Document
    {
        $text = Text::toUtf8($source->bytes());
        $rawLines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $delimiter = $source->extension() === 'tsv' ? "\t" : self::detectDelimiter(array_slice($rawLines, 0, 20));

        $lines = [];
        $rows = [];
        foreach ($rawLines as $i => $raw) {
            if (trim($raw) === '') {
                continue;
            }
            $lines[] = ['ligne ' . ($i + 1), $raw];
            $rows[$i + 1] = str_getcsv($raw, $delimiter, '"', '');
        }

        return new Document($lines, [['name' => $source->name(), 'rows' => $rows]]);
    }

    /** @param list<string> $sample */
    private static function detectDelimiter(array $sample): string
    {
        $best = ',';
        $bestScore = -1;
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $counts = array_map(static fn (string $l): int => substr_count($l, $candidate), array_filter($sample, static fn (string $l): bool => $l !== ''));
            if ($counts === [] || min($counts) === 0) {
                continue;
            }
            $score = min($counts) * 10 - (max($counts) - min($counts));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best;
    }
}
