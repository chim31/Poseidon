<?php

declare(strict_types=1);

namespace Poseidon\Search;

use Poseidon\Archive\ArchiveWalker;
use Poseidon\Config;
use Poseidon\Extractor\ExtractorRegistry;
use Poseidon\Filesystem\Notice;
use Poseidon\Filesystem\RootGuard;
use Poseidon\Filesystem\Scanner;
use Poseidon\Filesystem\Source;
use Poseidon\Filesystem\UnreadableFileException;
use Poseidon\Index\Index;
use Poseidon\Support\Path;

/**
 * Orchestration « ls -R | grep -Ri | wc » : parcourt, extrait, compare, compte.
 *
 * Produit un flux d'événements (idéal pour l'affichage progressif) :
 *  - ['type' => 'start',    'root' => ..., 'query' => ...]
 *  - ['type' => 'match',    'file' => [...], 'counts' => [...], 'total' => n, 'hits' => [...]]
 *  - ['type' => 'notice',   'reason' => ..., 'path' => ..., 'message' => ...]
 *  - ['type' => 'progress', 'scanned' => n, 'matched' => n]
 *  - ['type' => 'done',     'summary' => [...]]
 */
final class SearchEngine
{
    private Scanner $scanner;

    private RootGuard $guard;

    public function __construct(private Config $config, private ?ExtractorRegistry $extractors = null)
    {
        $this->extractors ??= new ExtractorRegistry($config);
        $this->guard = new RootGuard($config);
        \Poseidon\Git\GitHistory::trust((array) $config->get('git_trusted_repos', []));
        $this->scanner = new Scanner($config, new ArchiveWalker($config), $this->guard);
    }

    public function extractors(): ExtractorRegistry
    {
        return $this->extractors;
    }

    public function guard(): RootGuard
    {
        return $this->guard;
    }

    /** Localisateur de fichiers par identifiant, avec la même configuration que ce moteur. */
    public function locator(): \Poseidon\Filesystem\FileLocator
    {
        return new \Poseidon\Filesystem\FileLocator($this->guard, new ArchiveWalker($this->config));
    }

    /** @return \Generator<int, array<string, mixed>> */
    public function search(Query $query, SearchOptions $options): \Generator
    {
        $started = microtime(true);
        $root = $this->guard->resolveRoot($options->root);
        $matcher = new Matcher($query, (int) $this->config->get('max_hits_per_file', 50));

        // Index plein texte : utilisé s'il existe pour cette racine (ou un dossier parent).
        $index = $options->useIndex && $options->target !== SearchOptions::TARGET_NAME ? Index::find($this->config, $root) : null;
        $candidates = $index?->candidates($query);
        $known = [];        // clé => vrai si le fichier était déjà indexé et inchangé avant cette recherche
        $pending = [];      // avertissements rejoués pour les archives non rouvertes
        $skipped = 0;
        $skippedArchives = 0;
        $wanted = array_fill_keys($options->extensions, true);
        $descend = $index === null ? null : function (Source $archive) use ($index, $candidates, $wanted, &$known, &$pending, &$skippedArchives): bool {
            $key = $archive->key();
            $fresh = $known[$key] ?? ($index->fresh($archive) !== null);
            if ($candidates !== null && $fresh && !isset($candidates['archives'][$key])) {
                foreach ($index->archiveNotices($key) as $notice) {
                    // Mêmes règles que le parcours : avertissements des seules entrées retenues par le filtre.
                    $ext = Path::extension((string) preg_replace('/^.* › /u', '', $notice->path));
                    if ($wanted === [] || isset($wanted[$ext]) || ArchiveWalker::isContainer('x.' . $ext)) {
                        $pending[] = $notice;
                    }
                }
                $skippedArchives++;

                return false;
            }
            $index->clearArchiveNotices($key);

            return true;
        };

        yield ['type' => 'start', 'root' => Path::display($root), 'query' => $query->toArray(), 'indexed' => $index !== null];

        $scanned = 0;
        $matched = 0;
        $inArchives = 0;
        $notices = 0;
        $totals = array_fill(0, count($query->terms), 0);
        $byExtension = [];
        $truncated = false;
        $lastProgress = microtime(true);

        foreach ($this->withPending($this->scanner->scan($root, $options->extensions, $options->intoArchives, $descend), $pending) as $item) {
            if ($item instanceof Notice) {
                if ($index !== null && $item->archiveKey !== null) {
                    $index->addArchiveNotice($item->archiveKey, $item);
                }
                $notices++;
                yield ['type' => 'notice'] + $item->toArray();
                continue;
            }

            /** @var Source $item */
            if (!$options->nameMatches($item->name())) {
                continue;
            }
            $scanned++;

            $row = $index?->fresh($item);
            if ($index !== null) {
                $known[$item->key()] = $row !== null;
            }
            if ($row !== null && $row['status'] === 'notice') {
                $result = new Notice((string) $row['reason'], $item->absolutePath(), (string) $row['message']);
            } elseif ($row !== null && $candidates !== null && !isset($candidates['ids'][$row['id']])) {
                // Inchangé et sans le filtre : pas besoin de le relire.
                $skipped++;
                $result = $options->target === SearchOptions::TARGET_BOTH ? $this->evaluateName($item, $query) : null;
            } else {
                $result = $this->evaluate($item, $query, $matcher, $options, $row === null ? $index : null);
            }
            $item->releaseBytes();

            if ($result instanceof Notice) {
                $notices++;
                yield ['type' => 'notice'] + $result->toArray();
            } elseif ($result !== null) {
                $matched++;
                if ($item->inArchive()) {
                    $inArchives++;
                }
                foreach ($result['counts'] as $i => $c) {
                    $totals[$i] += $c;
                }
                $ext = $item->extension() ?: '—';
                $byExtension[$ext] = ($byExtension[$ext] ?? 0) + 1;
                yield ['type' => 'match'] + $result;

                if ($matched >= $options->maxResults) {
                    $truncated = true;
                    break;
                }
            }

            if (microtime(true) - $lastProgress > 0.25) {
                $lastProgress = microtime(true);
                yield ['type' => 'progress', 'scanned' => $scanned, 'matched' => $matched, 'current' => $item->relativePath()];
            }
        }
        $index?->commit();

        arsort($byExtension);
        yield ['type' => 'done', 'summary' => [
            'root' => Path::display($root),
            'scanned' => $scanned,
            'matched' => $matched,
            'inArchives' => $inArchives,
            'occurrences' => array_sum($totals),
            'termTotals' => $totals,
            'byExtension' => $byExtension,
            'notices' => $notices,
            'truncated' => $truncated,
            'index' => $index === null ? null : [
                'root' => Path::display($index->root),
                'usable' => $candidates !== null,
                'skippedFiles' => $skipped,
                'skippedArchives' => $skippedArchives,
            ],
            'durationMs' => (int) round((microtime(true) - $started) * 1000),
        ]];
    }

    /**
     * Intercale les avertissements en attente (archives non rouvertes) dans le flux du parcours.
     *
     * @param array<int, Notice> $pending
     * @return \Generator<int, Source|Notice>
     */
    private function withPending(\Generator $items, array &$pending): \Generator
    {
        foreach ($items as $item) {
            while ($pending !== []) {
                yield array_shift($pending);
            }
            yield $item;
        }
        while ($pending !== []) {
            yield array_shift($pending);
        }
    }

    /**
     * Recherche complète en mémoire (CLI, tests, export).
     *
     * @return array{matches: list<array<string, mixed>>, notices: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function collect(Query $query, SearchOptions $options): array
    {
        $matches = [];
        $notices = [];
        $summary = [];
        foreach ($this->search($query, $options) as $event) {
            match ($event['type']) {
                'match' => $matches[] = $event,
                'notice' => $notices[] = $event,
                'done' => $summary = $event['summary'],
                default => null,
            };
        }

        return ['matches' => $matches, 'notices' => $notices, 'summary' => $summary];
    }

    /** @return array<string, mixed>|null Correspondance sur le seul nom du fichier. */
    private function evaluateName(Source $source, Query $query): ?array
    {
        $counts = array_map(static fn (int $i): int => $query->count($source->name(), $i), array_keys($query->terms));

        return $query->isMatch($counts) ? $this->payload($source, $counts, [], 0, true) : null;
    }

    /**
     * @param Index|null $store Index à alimenter (fichier nouveau ou modifié).
     * @return array<string, mixed>|Notice|null
     */
    private function evaluate(Source $source, Query $query, Matcher $matcher, SearchOptions $options, ?Index $store = null): array|Notice|null
    {
        if ($options->target === SearchOptions::TARGET_NAME) {
            return $this->evaluateName($source, $query);
        }
        $nameHit = $options->target === SearchOptions::TARGET_BOTH && $this->evaluateName($source, $query) !== null;
        $nameCounts = $nameHit ? array_map(static fn (int $i): int => $query->count($source->name(), $i), array_keys($query->terms)) : [];

        try {
            $document = $this->extractors->extract($source);
        } catch (UnreadableFileException $e) {
            $notice = new Notice($e->reason, $source->absolutePath(), $e->getMessage());
            $store?->store($source, null, $notice);

            return $notice;
        } catch (\Throwable $e) {
            $notice = new Notice(UnreadableFileException::CORRUPT, $source->absolutePath(), 'Analyse impossible : ' . $e->getMessage());
            $store?->store($source, null, $notice);

            return $notice;
        }
        $store?->store($source, $document);

        if ($document === null) {
            return $nameHit ? $this->payload($source, $nameCounts, [], 0, true) : null;
        }

        $result = $matcher->match($document);
        if (!$query->isMatch($result->counts)) {
            return $nameHit ? $this->payload($source, $nameCounts, [], 0, true) : null;
        }

        return $this->payload($source, $result->counts, $result->hits, $result->hitLines, $nameHit);
    }

    /** @return array<string, mixed> */
    private function payload(Source $source, array $counts, array $hits, int $hitLines, bool $nameHit): array
    {
        return [
            'file' => $source->toArray(),
            'counts' => $counts,
            'total' => array_sum($counts),
            'hitLines' => $hitLines,
            'nameMatch' => $nameHit,
            'hits' => $hits,
        ];
    }
}
