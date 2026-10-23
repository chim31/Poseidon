<?php

declare(strict_types=1);

namespace Poseidon\Search;

use Poseidon\Support\Path;
use Poseidon\Support\Text;

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
        // /dev/null : fichier absent d'un côté (ajout ou suppression), comme dans un patch Git.
        $out = '--- ' . ($nameA === '/dev/null' ? $nameA : "a/{$nameA}") . "\n+++ " . ($nameB === '/dev/null' ? $nameB : "b/{$nameB}") . "\n";
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

    /** Nombre maximal de blocs renvoyés par fichier (borne la réponse). */
    public const MAX_HUNKS = 500;

    /** Entrées parcourues au plus dans chaque dossier comparé. */
    public const MAX_SCANNED = 100000;

    /**
     * Carte d'un fichier dans une comparaison multi-fichiers (dossiers ou Git), façon GitHub.
     *
     * @param list<string> $linesA
     * @param list<string> $linesB
     * @return array{path: string, oldPath: string, status: string, statusLabel: string, added: int, removed: int, hunks: list<array{header: string, lines: list<array{0: string, 1: string}>}>, unified: string}
     */
    public static function fileEntry(string $path, string $status, array $linesA, array $linesB, int $context = 3, string $oldPath = ''): array
    {
        $oldPath = $oldPath === '' ? $path : $oldPath;
        $ops = self::lines($linesA, $linesB);
        $hunks = self::hunks($ops, $context);
        $added = 0;
        $removed = 0;
        foreach ($ops as [$op]) {
            $added += $op === '+' ? 1 : 0;
            $removed += $op === '-' ? 1 : 0;
        }

        return [
            'path' => $path,
            'oldPath' => $oldPath,
            'status' => $status,
            'statusLabel' => match ($status) {
                'A' => 'Nouveau',
                'D' => 'Supprimé',
                'R' => 'Renommé',
                default => 'Modifié',
            },
            'added' => $added,
            'removed' => $removed,
            'hunks' => array_slice($hunks, 0, self::MAX_HUNKS),
            'unified' => self::unified($status === 'A' ? '/dev/null' : $oldPath, $status === 'D' ? '/dev/null' : $path, $hunks),
        ];
    }

    /**
     * Totaux d'une comparaison multi-fichiers.
     *
     * @param list<array{status: string, added: int, removed: int}> $files
     * @return array{totalFiles: int, added: int, modified: int, deleted: int, renamed: int, unchanged: int, additions: int, deletions: int}
     */
    public static function stats(array $files, int $unchanged = 0): array
    {
        $count = static fn (string $s): int => count(array_filter($files, static fn (array $f): bool => $f['status'] === $s));

        return [
            'totalFiles' => count($files),
            'added' => $count('A'),
            'modified' => $count('M'),
            'deleted' => $count('D'),
            'renamed' => $count('R'),
            'unchanged' => $unchanged,
            'additions' => array_sum(array_column($files, 'added')),
            'deletions' => array_sum(array_column($files, 'removed')),
        ];
    }

    /**
     * Compare deux répertoires de manière intégrale (façon GitHub diff) :
     * fichiers modifiés (M), nouveaux/générés (A), supprimés (D).
     *
     * Les liens symboliques et jonctions ne sont jamais suivis (ni boucle, ni sortie du dossier comparé) :
     * ils sont signalés dans « skipped ». Les fichiers plus gros que $maxFileSize sont comparés par empreinte seulement.
     *
     * @return array{stats: array<string, int>, dirA: string, dirB: string, files: list<array<string, mixed>>, unified: string, truncated: bool, skipped: list<string>}
     */
    public static function compareDirectories(string $dirA, string $dirB, ?\Poseidon\Extractor\ExtractorRegistry $extractors = null, int $context = 3, int $maxFileSize = 0, int $maxFiles = 2000): array
    {
        $dirA = Path::normalize($dirA);
        $dirB = Path::normalize($dirB);
        if (!Path::isDir($dirA)) {
            throw new \InvalidArgumentException('Le premier dossier est introuvable ou inaccessible : ' . Path::display($dirA));
        }
        if (!Path::isDir($dirB)) {
            throw new \InvalidArgumentException('Le second dossier est introuvable ou inaccessible : ' . Path::display($dirB));
        }

        $skipped = [];
        $truncated = false;
        $scan = static function (string $dir, string $side) use (&$skipped, &$truncated): array {
            $files = [];
            $seen = 0;
            $stack = [''];
            while ($stack !== []) {
                $sub = array_pop($stack);
                $entries = @scandir(Path::toNative($sub === '' ? $dir : $dir . '/' . $sub, true));
                if ($entries === false) {
                    $skipped[] = $side . ' : ' . ($sub === '' ? '.' : $sub) . ' (dossier illisible)';
                    continue;
                }
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..' || $entry === '.git') {
                        continue;
                    }
                    if (++$seen > self::MAX_SCANNED) {
                        $truncated = true;

                        return $files;
                    }
                    $rel = $sub === '' ? $entry : $sub . '/' . $entry;
                    $native = Path::toNative($dir . '/' . $rel);
                    if (is_link($native)) {
                        $skipped[] = $side . ' : ' . $rel . ' (lien symbolique non suivi)';
                    } elseif (is_dir($native)) {
                        $stack[] = $rel;
                    } elseif (is_file($native)) {
                        $files[$rel] = $dir . '/' . $rel;
                    }
                }
            }

            return $files;
        };

        $filesA = $scan($dirA, 'A');
        $filesB = $scan($dirB, 'B');
        $allKeys = array_unique([...array_keys($filesA), ...array_keys($filesB)]);
        sort($allKeys, SORT_STRING);

        $getLines = static function (string $diskPath, string $root) use ($extractors, $maxFileSize): array {
            $native = Path::toNative($diskPath);
            $size = (int) @filesize($native);
            if ($maxFileSize > 0 && $size > $maxFileSize) {
                return ['(fichier trop volumineux pour être affiché : ' . $size . ' octets)'];
            }
            if ($extractors !== null) {
                try {
                    $doc = $extractors->extract(new \Poseidon\Filesystem\Source($diskPath, [], $root, $size, (int) @filemtime($native)));
                    if ($doc !== null) {
                        return array_column($doc->lines, 1);
                    }
                } catch (\Throwable) {
                    // illisible pour l'extracteur : lecture brute ci-dessous
                }
            }
            $content = @file_get_contents($native);
            if ($content === false) {
                return ['(fichier illisible)'];
            }
            if (Text::looksBinary($content)) {
                return ['(fichier binaire : ' . $size . ' octets)'];
            }

            return explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        };

        $files = [];
        $unchanged = 0;
        foreach ($allKeys as $rel) {
            $inA = isset($filesA[$rel]);
            $inB = isset($filesB[$rel]);
            if ($inA && $inB) {
                $nativeA = Path::toNative($filesA[$rel]);
                $nativeB = Path::toNative($filesB[$rel]);
                if (@filesize($nativeA) === @filesize($nativeB) && @sha1_file($nativeA) === @sha1_file($nativeB)) {
                    $unchanged++;
                    continue;
                }
            }
            if (count($files) >= $maxFiles) {
                $truncated = true;
                break;
            }
            $status = $inA && $inB ? 'M' : ($inB ? 'A' : 'D');
            $files[] = self::fileEntry(
                $rel,
                $status,
                $inA ? $getLines($filesA[$rel], $dirA) : [],
                $inB ? $getLines($filesB[$rel], $dirB) : [],
                $context,
            );
        }

        return [
            'stats' => self::stats($files, $unchanged),
            'dirA' => Path::display($dirA),
            'dirB' => Path::display($dirB),
            'files' => $files,
            'unified' => implode("\n", array_column($files, 'unified')),
            'truncated' => $truncated,
            'skipped' => array_slice($skipped, 0, 200),
        ];
    }
}
