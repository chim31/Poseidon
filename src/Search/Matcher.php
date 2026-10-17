<?php

declare(strict_types=1);

namespace Poseidon\Search;

use Poseidon\Extractor\Document;

/**
 * Équivalent de « grep » sur un Document : compte les occurrences de chaque terme
 * et garde les lignes-preuves, avec la position de chaque correspondance pour le surlignage.
 * En mode proximité, seules les occurrences groupées (à moins de N mots) comptent.
 */
final class Matcher
{
    public function __construct(private Query $query, private int $maxHits = 50)
    {
    }

    public function match(Document $document): MatchResult
    {
        $termCount = count($this->query->terms);
        $counts = array_fill(0, $termCount, 0);

        // Pré-filtre rapide sur le texte complet : abandon immédiat si un terme requis manque.
        $text = $document->text();
        $present = 0;
        $required = in_array($this->query->mode, [Query::ALL, Query::NEAR], true);
        for ($i = 0; $i < $termCount; $i++) {
            $found = $this->query->isFuzzy($i) ? $this->query->find($text, $i) !== [] : preg_match($this->query->patterns[$i], $text) === 1;
            if ($found) {
                $present++;
            } elseif ($required) {
                return new MatchResult($counts, [], 0);
            }
        }
        if ($present === 0) {
            return new MatchResult($counts, [], 0);
        }

        $spansByLine = $this->spans($document);

        $hits = [];
        $hitLines = 0;
        foreach ($document->lines as $index => [$location, $line]) {
            $spans = $spansByLine[$index] ?? [];
            if ($spans === []) {
                continue;
            }
            foreach ($spans as $span) {
                $counts[$span[2]]++;
            }
            $hitLines++;
            if (count($hits) < $this->maxHits) {
                usort($spans, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
                $spans = array_map(static fn (array $s): array => [$s[0], $s[1], $s[2]], $spans);
                $hits[] = ['location' => $location, 'text' => self::clip($line, $spans), 'spans' => self::shift($line, $spans)];
            }
        }

        return new MatchResult($counts, $hits, $hitLines);
    }

    /**
     * Toutes les correspondances par ligne (positions non recadrées), proximité appliquée.
     *
     * @return array<int, list<array{0: int, 1: int, 2: int, 3: int}>> ligne => [position, longueur (caractères), terme, octet]
     */
    public function spans(Document $document): array
    {
        $termCount = count($this->query->terms);
        $spansByLine = [];
        foreach ($document->lines as $index => [, $line]) {
            for ($i = 0; $i < $termCount; $i++) {
                foreach ($this->query->find($line, $i) as [$offset, $length]) {
                    $spansByLine[$index][] = [mb_strlen(substr($line, 0, $offset)), mb_strlen(substr($line, $offset, $length)), $i, $offset];
                }
            }
        }
        if ($this->query->mode === Query::NEAR && $termCount > 1) {
            $spansByLine = $this->keepNear($document, $spansByLine);
        }
        foreach ($spansByLine as &$spans) {
            usort($spans, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        }
        unset($spans);

        return $spansByLine;
    }

    /**
     * Mode proximité : ne garde que les occurrences appartenant à un groupe où chaque terme
     * apparaît à moins de « distance » mots d'une occurrence du premier terme (lignes enchaînées).
     *
     * @param array<int, list<array{0:int,1:int,2:int,3:int}>> $spansByLine
     * @return array<int, list<array{0:int,1:int,2:int,3:int}>>
     */
    private function keepNear(Document $document, array $spansByLine): array
    {
        // Position de chaque correspondance en nombre de mots depuis le début du document.
        $wordBase = 0;
        $positioned = [];
        foreach ($document->lines as $index => [, $line]) {
            preg_match_all('/[\p{L}\p{N}_]+/u', $line, $words, PREG_OFFSET_CAPTURE);
            $starts = array_column($words[0], 1);
            foreach ($spansByLine[$index] ?? [] as $k => $span) {
                $w = 0;
                foreach ($starts as $j => $start) {
                    if ($start <= $span[3]) {
                        $w = $j;
                    }
                }
                $positioned[] = ['line' => $index, 'k' => $k, 'term' => $span[2], 'word' => $wordBase + $w];
            }
            $wordBase += max(1, count($starts));
        }

        $byTerm = [];
        foreach ($positioned as $p) {
            $byTerm[$p['term']][] = $p;
        }
        $keep = [];
        $distance = $this->query->distance;
        foreach ($byTerm[0] ?? [] as $anchor) {
            $group = [$anchor];
            for ($t = 1, $n = count($this->query->terms); $t < $n; $t++) {
                $near = array_filter($byTerm[$t] ?? [], static fn (array $p): bool => abs($p['word'] - $anchor['word']) <= $distance + 1);
                if ($near === []) {
                    continue 2;
                }
                array_push($group, ...$near);
            }
            foreach ($group as $p) {
                $keep[$p['line']][$p['k']] = true;
            }
        }

        $out = [];
        foreach ($spansByLine as $line => $spans) {
            foreach ($spans as $k => $span) {
                if (isset($keep[$line][$k])) {
                    $out[$line][] = $span;
                }
            }
        }

        return $out;
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
