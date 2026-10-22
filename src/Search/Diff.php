<?php

declare(strict_types=1);

namespace Poseidon\Search;

/**
 * Comparaison ligne à ligne façon « git diff » (algorithme de Myers, sortie unifiée).
 * Fonctionne sur le texte extrait : on peut comparer deux PDF, deux DOCX, un XLSX et un CSV...
 */
final class Diff
{
    /** Nombre maximal de lignes différentes traitées finement (borne la mémoire). */
    public const MAX_EDITS = 1000;

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{0: string, 1: string}> Opérations : [' '|'-'|'+', ligne]
     */
    public static function lines(array $a, array $b): array
    {
        // Préfixe et suffixe communs retirés pour accélérer.
        $start = 0;
        $endA = count($a);
        $endB = count($b);
        while ($start < $endA && $start < $endB && $a[$start] === $b[$start]) {
            $start++;
        }
        while ($endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1]) {
            $endA--;
            $endB--;
        }

        $ops = [];
        for ($i = 0; $i < $start; $i++) {
            $ops[] = [' ', $a[$i]];
        }
        foreach (self::myers(array_slice($a, $start, $endA - $start), array_slice($b, $start, $endB - $start)) as $op) {
            $ops[] = $op;
        }
        for ($i = $endA, $n = count($a); $i < $n; $i++) {
            $ops[] = [' ', $a[$i]];
        }

        return $ops;
    }

    /**
     * Regroupe les opérations en blocs (« hunks ») avec $context lignes de contexte.
     *
     * @param list<array{0: string, 1: string}> $ops
     * @return list<array{header: string, lines: list<array{0: string, 1: string}>}>
     */
    public static function hunks(array $ops, int $context = 3): array
    {
        $hunks = [];
        $n = count($ops);
        $lineA = 1;
        $lineB = 1;
        $posA = [];
        $posB = [];
        foreach ($ops as $k => [$type]) {
            $posA[$k] = $lineA;
            $posB[$k] = $lineB;
            if ($type !== '+') {
                $lineA++;
            }
            if ($type !== '-') {
                $lineB++;
            }
        }

        $k = 0;
        while ($k < $n) {
            if ($ops[$k][0] === ' ') {
                $k++;
                continue;
            }
            $from = max(0, $k - $context);
            $to = $k;
            $lastChange = $k;
            while ($to < $n && ($ops[$to][0] !== ' ' || $to - $lastChange <= 2 * $context)) {
                if ($ops[$to][0] !== ' ') {
                    $lastChange = $to;
                }
                $to++;
            }
            $to = min($n, $lastChange + $context + 1);
            $slice = array_slice($ops, $from, $to - $from);
            $countA = count(array_filter($slice, static fn (array $o): bool => $o[0] !== '+'));
            $countB = count(array_filter($slice, static fn (array $o): bool => $o[0] !== '-'));
            $hunks[] = [
                'header' => sprintf('@@ -%d,%d +%d,%d @@', $posA[$from], $countA, $posB[$from], $countB),
                'lines' => $slice,
            ];
            $k = $to;
        }

        return $hunks;
    }

    /** @param list<array{header: string, lines: list<array{0: string, 1: string}>}> $hunks */
    public static function unified(string $nameA, string $nameB, array $hunks): string
    {
        $out = "--- a/{$nameA}\n+++ b/{$nameB}\n";
        foreach ($hunks as $hunk) {
            $out .= $hunk['header'] . "\n";
            foreach ($hunk['lines'] as [$type, $line]) {
                $out .= $type . $line . "\n";
            }
        }

        return $out;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{0: string, 1: string}>
     */
    private static function myers(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        if ($n === 0) {
            return array_map(static fn (string $l): array => ['+', $l], $b);
        }
        if ($m === 0) {
            return array_map(static fn (string $l): array => ['-', $l], $a);
        }
        // Mémoire de Myers en O(D²) : au-delà de MAX_EDITS différences, comparaison grossière.
        $crude = static fn (): array => [...array_map(static fn (string $l): array => ['-', $l], $a), ...array_map(static fn (string $l): array => ['+', $l], $b)];
        if ($n + $m > 200_000) {
            return $crude();
        }

        $max = min($n + $m, self::MAX_EDITS);
        $v = [1 => 0];
        $trace = [];
        for ($d = 0; $d <= $max; $d++) {
            $trace[] = $v;
            for ($k = -$d; $k <= $d; $k += 2) {
                if ($k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1))) {
                    $x = $v[$k + 1] ?? 0;
                } else {
                    $x = ($v[$k - 1] ?? 0) + 1;
                }
                $y = $x - $k;
                while ($x < $n && $y < $m && $a[$x] === $b[$y]) {
                    $x++;
                    $y++;
                }
                $v[$k] = $x;
                if ($x >= $n && $y >= $m) {
                    return self::backtrack($trace, $a, $b, $n, $m);
                }
            }
        }

        return $crude();
    }

    /** @return list<array{0: string, 1: string}> */
    private static function backtrack(array $trace, array $a, array $b, int $x, int $y): array
    {
        $ops = [];
        for ($d = count($trace) - 1; $d >= 0; $d--) {
            $v = $trace[$d];
            $k = $x - $y;
            if ($k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1))) {
                $prevK = $k + 1;
            } else {
                $prevK = $k - 1;
            }
            $prevX = $v[$prevK] ?? 0;
            $prevY = $prevX - $prevK;
            while ($x > $prevX && $y > $prevY) {
                $ops[] = [' ', $a[$x - 1]];
                $x--;
                $y--;
            }
            if ($d > 0) {
                if ($x === $prevX) {
                    $ops[] = ['+', $b[$y - 1]];
                } else {
                    $ops[] = ['-', $a[$x - 1]];
                }
            }
            $x = $prevX;
            $y = $prevY;
        }

        return array_reverse($ops);
    }

    /**
     * Compare deux répertoires de manière intégrale (façon GitHub diff) :
     * fichiers modifiés (M), nouveaux/générés (A), supprimés (D).
     *
     * @return array{
     *   stats: array{totalFiles: int, added: int, modified: int, deleted: int, unchanged: int, additions: int, deletions: int},
     *   dirA: string,
     *   dirB: string,
     *   files: list<array{path: string, status: string, statusLabel: string, added: int, removed: int, hunks: list<mixed>, unified: string}>,
     *   unified: string
     * }
     */
    public static function compareDirectories(string $dirA, string $dirB, ?\Poseidon\Extractor\ExtractorRegistry $extractors = null, int $context = 3): array
    {
        $dirA = \Poseidon\Support\Path::normalize($dirA);
        $dirB = \Poseidon\Support\Path::normalize($dirB);
        if (!\Poseidon\Support\Path::isDir($dirA)) {
            throw new \InvalidArgumentException('Le premier dossier est introuvable ou inaccessible : ' . \Poseidon\Support\Path::display($dirA));
        }
        if (!\Poseidon\Support\Path::isDir($dirB)) {
            throw new \InvalidArgumentException('Le second dossier est introuvable ou inaccessible : ' . \Poseidon\Support\Path::display($dirB));
        }

        $scan = static function (string $dir): array {
            $files = [];
            $stack = [''];
            while ($stack !== []) {
                $sub = array_pop($stack);
                $fullDir = $sub === '' ? $dir : $dir . '/' . $sub;
                $entries = @scandir(\Poseidon\Support\Path::toNative($fullDir, true));
                if ($entries === false) {
                    continue;
                }
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..' || $entry === '.git' || $entry === '.impeccable') {
                        continue;
                    }
                    $rel = $sub === '' ? $entry : $sub . '/' . $entry;
                    $itemPath = $dir . '/' . $rel;
                    $native = \Poseidon\Support\Path::toNative($itemPath, true);
                    if (is_dir($native)) {
                        $stack[] = $rel;
                    } elseif (is_file(\Poseidon\Support\Path::toNative($itemPath))) {
                        $files[$rel] = $itemPath;
                    }
                }
            }
            ksort($files);

            return $files;
        };

        $filesA = $scan($dirA);
        $filesB = $scan($dirB);
        $allKeys = array_unique([...array_keys($filesA), ...array_keys($filesB)]);
        sort($allKeys);

        $getLines = static function (string $diskPath, string $root) use ($extractors): array {
            $native = \Poseidon\Support\Path::toNative($diskPath);
            if (!is_file($native)) {
                return [];
            }
            $size = (int) @filesize($native);
            $mtime = (int) @filemtime($native);
            $source = new \Poseidon\Filesystem\Source($diskPath, [], $root, $size, $mtime);
            if ($extractors !== null) {
                try {
                    $doc = $extractors->extract($source);
                    if ($doc !== null) {
                        return array_column($doc->lines, 1);
                    }
                } catch (\Throwable) {
                }
            }
            $content = @file_get_contents($native);
            if ($content === false) {
                return [];
            }
            if (\Poseidon\Support\Text::looksBinary($content)) {
                return ['(fichier binaire : ' . $size . ' octets)'];
            }

            return explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        };

        $diffFiles = [];
        $totalAdditions = 0;
        $totalDeletions = 0;
        $countAdded = 0;
        $countModified = 0;
        $countDeleted = 0;
        $countUnchanged = 0;
        $unifiedAll = [];

        foreach ($allKeys as $rel) {
            $inA = isset($filesA[$rel]);
            $inB = isset($filesB[$rel]);
            if ($inA && $inB) {
                $pathA = $filesA[$rel];
                $pathB = $filesB[$rel];
                if (filesize(\Poseidon\Support\Path::toNative($pathA)) === filesize(\Poseidon\Support\Path::toNative($pathB)) && sha1_file(\Poseidon\Support\Path::toNative($pathA)) === sha1_file(\Poseidon\Support\Path::toNative($pathB))) {
                    $countUnchanged++;
                    continue;
                }
                $linesA = $getLines($pathA, $dirA);
                $linesB = $getLines($pathB, $dirB);
                $status = 'M';
                $statusLabel = 'Modifié';
                $countModified++;
            } elseif ($inB) {
                $linesA = [];
                $linesB = $getLines($filesB[$rel], $dirB);
                $status = 'A';
                $statusLabel = 'Nouveau';
                $countAdded++;
            } else {
                $linesA = $getLines($filesA[$rel], $dirA);
                $linesB = [];
                $status = 'D';
                $statusLabel = 'Supprimé';
                $countDeleted++;
            }

            $ops = self::lines($linesA, $linesB);
            $hunks = self::hunks($ops, $context);
            $added = count(array_filter($ops, static fn (array $o): bool => $o[0] === '+'));
            $removed = count(array_filter($ops, static fn (array $o): bool => $o[0] === '-'));
            $totalAdditions += $added;
            $totalDeletions += $removed;
            $fileUnified = self::unified("a/{$rel}", "b/{$rel}", $hunks);
            $unifiedAll[] = $fileUnified;

            $diffFiles[] = [
                'path' => $rel,
                'status' => $status,
                'statusLabel' => $statusLabel,
                'added' => $added,
                'removed' => $removed,
                'hunks' => $hunks,
                'unified' => $fileUnified,
            ];
        }

        return [
            'stats' => [
                'totalFiles' => count($diffFiles),
                'added' => $countAdded,
                'modified' => $countModified,
                'deleted' => $countDeleted,
                'unchanged' => $countUnchanged,
                'additions' => $totalAdditions,
                'deletions' => $totalDeletions,
            ],
            'dirA' => \Poseidon\Support\Path::display($dirA),
            'dirB' => \Poseidon\Support\Path::display($dirB),
            'files' => $diffFiles,
            'unified' => implode("\n", $unifiedAll),
        ];
    }
}
