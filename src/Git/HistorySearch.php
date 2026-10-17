<?php

declare(strict_types=1);

namespace Poseidon\Git;

use Poseidon\Extractor\ExtractorRegistry;
use Poseidon\Extractor\PlainTextExtractor;
use Poseidon\Filesystem\Source;
use Poseidon\Filesystem\UnreadableFileException;
use Poseidon\Search\Query;
use Poseidon\Support\Path;

/**
 * Recherche dans l'historique Git : commits où le nombre d'occurrences d'un filtre a changé.
 *
 * - Fichiers texte : « git log -S » (ou -G pour une regex), rapide.
 * - Documents (DOCX, XLSX, PDF, ODT, courriels…) : leur contenu est compressé, Git ne peut pas
 *   y chercher. Poseidon extrait le texte de chaque version touchée et compare les comptes.
 */
final class HistorySearch
{
    public function __construct(private ExtractorRegistry $extractors, private int $maxCommits = 100, private int $maxSize = 50 * 1024 * 1024)
    {
    }

    /**
     * @return list<array{hash: string, short: string, author: string, date: string, subject: string, files: list<array{path: string, before: int, after: int}>}>
     */
    public function search(string $repo, string $pathspec, Query $query): array
    {
        $byCommit = [];

        // 1. Fichiers texte : pioche Git.
        $needle = $query->mode === Query::REGEX ? $query->input : ($query->terms[0] ?? $query->input);
        foreach (GitHistory::search($repo, $pathspec, $needle, $query->mode === Query::REGEX, $this->maxCommits) as $commit) {
            $files = array_values(array_filter($commit['files'], static fn (string $f): bool => in_array(Path::extension($f), PlainTextExtractor::EXTENSIONS, true) || in_array(Path::extension($f), ['csv', 'tsv', 'rtf'], true)));
            if ($files !== []) {
                $byCommit[$commit['hash']] = array_merge($commit, ['files' => []]);
                foreach ($files as $f) {
                    $byCommit[$commit['hash']]['files'][$f] = $this->counts($repo, $commit['hash'], $f, $query);
                }
            }
        }

        // 2. Documents : comparaison du texte extrait, version par version.
        foreach (GitHistory::touched($repo, $pathspec, $this->maxCommits) as $commit) {
            foreach ($commit['files'] as $f) {
                $ext = Path::extension($f);
                if (in_array($ext, PlainTextExtractor::EXTENSIONS, true) || !$this->extractors->supports($ext)) {
                    continue;
                }
                $counts = $this->counts($repo, $commit['hash'], $f, $query);
                if ($counts['before'] !== $counts['after']) {
                    $byCommit[$commit['hash']] ??= array_merge($commit, ['files' => []]);
                    $byCommit[$commit['hash']]['files'][$f] = $counts;
                }
            }
        }

        $out = [];
        foreach ($byCommit as $commit) {
            $files = [];
            foreach ($commit['files'] as $path => $c) {
                $files[] = ['path' => $path] + $c;
            }
            $commit['files'] = $files;
            $out[] = $commit;
        }
        usort($out, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));

        return $out;
    }

    /** @return array{before: int, after: int} */
    private function counts(string $repo, string $hash, string $file, Query $query): array
    {
        $parent = GitHistory::parent($repo, $hash);

        return [
            'before' => $parent === '' ? 0 : $this->count(GitHistory::show($repo, $parent, $file, $this->maxSize), $file, $query),
            'after' => $this->count(GitHistory::show($repo, $hash, $file, $this->maxSize), $file, $query),
        ];
    }

    private function count(string $bytes, string $file, Query $query): int
    {
        if ($bytes === '') {
            return 0;
        }
        $source = new Source($file, [], dirname($file), strlen($bytes), 0, static fn (): string => $bytes);
        try {
            $document = $this->extractors->extract($source, false);
        } catch (UnreadableFileException) {
            return 0;
        }
        if ($document === null) {
            return 0;
        }
        $text = $document->text();
        $total = 0;
        foreach (array_keys($query->terms) as $i) {
            $total += $query->count($text, $i);
        }

        return $total;
    }
}
