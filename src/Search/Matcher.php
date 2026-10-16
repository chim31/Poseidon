<?php

declare(strict_types=1);

namespace Poseidon\Search;

use Poseidon\Extractor\Document;

/**
 * Équivalent de « grep » sur un Document : compte les occurrences de chaque terme
 * et garde les lignes-preuves, avec la position de chaque correspondance pour le surlignage.
 */
final class Matcher
{
    public function __construct(private Query $query, private int $maxHits = 50)
    {
    }

    public function match(Document $document): MatchResult
    {
        $patterns = $this->query->patterns;
        $counts = array_fill(0, count($patterns), 0);

        // Pré-filtre rapide sur le texte complet : abandon immédiat si un terme requis manque.
        $text = $document->text();
        $present = 0;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $present++;
            } elseif ($this->query->mode === Query::ALL) {
                return new MatchResult($counts, [], 0);
            }
        }
        if ($present === 0) {
            return new MatchResult($counts, [], 0);
        }

        $hits = [];
        $hitLines = 0;
        foreach ($document->lines as [$location, $line]) {
            $spans = [];
            foreach ($patterns as $i => $pattern) {
                if (preg_match_all($pattern, $line, $m, PREG_OFFSET_CAPTURE) > 0) {
                    foreach ($m[0] as [$value, $offset]) {
                        if ($value === '') {
                            continue;
                        }
                        $counts[$i]++;
                        $spans[] = [mb_strlen(substr($line, 0, $offset)), mb_strlen($value), $i];
                    }
                }
            }
            if ($spans !== []) {
                $hitLines++;
                if (count($hits) < $this->maxHits) {
                    usort($spans, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
                    $hits[] = ['location' => $location, 'text' => self::clip($line, $spans), 'spans' => self::shift($line, $spans)];
                }
            }
        }

        return new MatchResult($counts, $hits, $hitLines);
    }

    /** Lignes très longues (CSV, PDF mis en page) : on garde une fenêtre autour de la première correspondance. */
    private static function clip(string $line, array $spans): string
    {
        $length = mb_strlen($line);
        if ($length <= 400) {
            return $line;
        }
        $start = max(0, $spans[0][0] - 120);

        return ($start > 0 ? '…' : '') . mb_substr($line, $start, 400) . ($start + 400 < $length ? '…' : '');
    }

    /** @return list<array{0: int, 1: int, 2: int}> */
    private static function shift(string $line, array $spans): array
    {
        if (mb_strlen($line) <= 400) {
            return $spans;
        }
        $start = max(0, $spans[0][0] - 120);
        $offset = $start > 0 ? $start - 1 : 0; // le « … » ajouté occupe un caractère
        $out = [];
        foreach ($spans as [$pos, $len, $term]) {
            $p = $pos - $offset;
            if ($p >= 0 && $p + $len <= 402) {
                $out[] = [$p, $len, $term];
            }
        }

        return $out;
    }
}
