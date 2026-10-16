<?php

declare(strict_types=1);

namespace Poseidon\Export;

use Poseidon\Extractor\Document;
use Poseidon\Extractor\ExtractorRegistry;
use Poseidon\Filesystem\FileLocator;
use Poseidon\Filesystem\UnreadableFileException;
use Poseidon\Search\Query;
use Poseidon\Search\SearchEngine;
use Poseidon\Search\SearchOptions;
use Poseidon\Support\Text;

/**
 * Transforme une recherche en jeu de données : on ne garde que ce que le filtre désigne,
 * puis on l'écrit dans un nouveau document.
 */
final class DataExtractor
{
    public function __construct(
        private SearchEngine $engine,
        private ExtractorRegistry $extractors,
        private FileLocator $locator,
    ) {
    }

    /**
     * @return array{columns: list<string>, rows: list<list<string>>, notices: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function run(Query $query, SearchOptions $options, ExtractionSpec $spec): array
    {
        $found = $this->engine->collect($query, $options);
        $notices = $found['notices'];
        $rows = [];
        $columns = $this->baseColumns($query, $spec);
        $dynamicColumns = [];

        foreach ($found['matches'] as $match) {
            $file = $match['file'];
            if ($spec->mode === ExtractionSpec::FILES) {
                $rows[] = [$file['name'], $file['relativePath'], $file['absolutePath'], (string) $match['total'], ...array_map('strval', $match['counts'])];
                continue;
            }

            try {
                $document = $this->extractors->extract($this->locator->locate($file['id']));
            } catch (UnreadableFileException $e) {
                $notices[] = ['type' => 'notice', 'reason' => $e->reason, 'path' => $file['absolutePath'], 'message' => $e->getMessage()];
                continue;
            }
            if ($document === null) {
                continue;
            }

            $source = $spec->withSource ? [$file['name'], $file['relativePath']] : [];
            foreach (match ($spec->mode) {
                ExtractionSpec::MATCHES => $this->matches($document, $query),
                ExtractionSpec::LINES => $this->lines($document, $query),
                default => $this->columns($document, $query, $spec, $dynamicColumns),
            } as $row) {
                $rows[] = [...$source, ...$row];
            }
        }

        if ($spec->mode === ExtractionSpec::COLUMNS) {
            $columns = [...($spec->withSource ? ['Fichier', 'Chemin relatif'] : []), 'Feuille', 'Ligne', ...$dynamicColumns];
        } elseif ($spec->mode === ExtractionSpec::MATCHES) {
            $groups = 0;
            foreach ($rows as $row) {
                $groups = max($groups, count($row) - count($columns));
            }
            for ($g = 1; $g <= $groups; $g++) {
                $columns[] = 'Groupe ' . $g;
            }
        }

        if ($spec->unique) {
            $seen = [];
            $offset = $spec->withSource && $spec->mode !== ExtractionSpec::FILES ? 2 : 0;
            $rows = array_values(array_filter($rows, static function (array $row) use (&$seen, $offset): bool {
                $key = implode("\x1F", array_slice($row, $offset));
                if (isset($seen[$key])) {
                    return false;
                }
                $seen[$key] = true;

                return true;
            }));
        }

        // Toutes les lignes à la largeur des colonnes.
        $width = count($columns);
        $rows = array_map(static fn (array $r): array => array_pad(array_map('strval', $r), $width, ''), $rows);

        return ['columns' => $columns, 'rows' => $rows, 'notices' => $notices, 'summary' => $found['summary']];
    }

    /** @return list<string> */
    private function baseColumns(Query $query, ExtractionSpec $spec): array
    {
        $source = $spec->withSource ? ['Fichier', 'Chemin relatif'] : [];

        return match ($spec->mode) {
            ExtractionSpec::FILES => ['Fichier', 'Chemin relatif', 'Chemin absolu', 'Occurrences', ...array_map(static fn (string $t): string => 'Terme : ' . $t, $query->terms)],
            ExtractionSpec::MATCHES => [...$source, 'Emplacement', 'Terme', 'Valeur'],
            ExtractionSpec::LINES => [...$source, 'Emplacement', 'Ligne'],
            default => $source,
        };
    }

    /** @return \Generator<int, list<string>> */
    private function matches(Document $document, Query $query): \Generator
    {
        foreach ($document->lines as [$location, $line]) {
            foreach ($query->patterns as $i => $pattern) {
                if (preg_match_all($pattern, $line, $m, PREG_SET_ORDER) > 0) {
                    foreach ($m as $groups) {
                        if ($groups[0] === '') {
                            continue;
                        }
                        yield [$location, $query->terms[$i], $groups[0], ...array_slice($groups, 1)];
                    }
                }
            }
        }
    }

    /** @return \Generator<int, list<string>> */
    private function lines(Document $document, Query $query): \Generator
    {
        foreach ($document->lines as [$location, $line]) {
            foreach ($query->patterns as $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    yield [$location, $line];
                    break;
                }
            }
        }
    }

    /**
     * @param list<string> $dynamicColumns Colonnes de sortie (accumulées sur tous les fichiers).
     * @return \Generator<int, list<string>>
     */
    private function columns(Document $document, Query $query, ExtractionSpec $spec, array &$dynamicColumns): \Generator
    {
        foreach ($document->tables as $table) {
            $rows = $table['rows'];
            $header = $spec->headerRow > 0 ? ($rows[$spec->headerRow] ?? []) : [];

            // Résolution des colonnes demandées : lettre Excel ou nom d'en-tête.
            $selected = [];
            if ($spec->columns === []) {
                $maxCol = 0;
                foreach ($rows as $cells) {
                    $maxCol = max($maxCol, $cells === [] ? 0 : max(array_keys($cells)) + 1);
                }
                for ($c = 0; $c < $maxCol; $c++) {
                    $selected[] = [$c, ($header[$c] ?? '') !== '' ? $header[$c] : Text::columnLetter($c)];
                }
            } else {
                foreach ($spec->columns as $wanted) {
                    $index = null;
                    foreach ($header as $c => $label) {
                        if (mb_strtolower(trim($label)) === mb_strtolower(trim($wanted))) {
                            $index = $c;
                            break;
                        }
                    }
                    if ($index === null && preg_match('/^[A-Za-z]{1,3}$/', $wanted)) {
                        $index = Text::columnIndex($wanted);
                    }
                    if ($index !== null) {
                        $selected[] = [$index, $wanted];
                    }
                }
            }

            foreach ($selected as [, $label]) {
                if (!in_array($label, $dynamicColumns, true)) {
                    $dynamicColumns[] = $label;
                }
            }

            foreach ($rows as $rowNumber => $cells) {
                if ($rowNumber === $spec->headerRow) {
                    continue;
                }
                if (!$spec->allRows) {
                    $line = implode(' | ', $cells);
                    $counts = array_map(static fn (string $p): int => (int) preg_match($p, $line), $query->patterns);
                    if (!$query->isMatch($counts)) {
                        continue;
                    }
                }
                $out = array_fill(0, count($dynamicColumns), '');
                foreach ($selected as [$index, $label]) {
                    $out[(int) array_search($label, $dynamicColumns, true)] = $cells[$index] ?? '';
                }
                yield [$table['name'], (string) $rowNumber, ...$out];
            }
        }
    }
}
