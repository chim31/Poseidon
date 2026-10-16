<?php

declare(strict_types=1);

namespace Poseidon\Search;

use Poseidon\Archive\ZipWalker;
use Poseidon\Config;
use Poseidon\Extractor\ExtractorRegistry;
use Poseidon\Filesystem\Notice;
use Poseidon\Filesystem\RootGuard;
use Poseidon\Filesystem\Scanner;
use Poseidon\Filesystem\Source;
use Poseidon\Filesystem\UnreadableFileException;

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
        $this->scanner = new Scanner($config, new ZipWalker($config), $this->guard);
    }

    public function extractors(): ExtractorRegistry
    {
        return $this->extractors;
    }

    public function guard(): RootGuard
    {
        return $this->guard;
    }

    /** @return \Generator<int, array<string, mixed>> */
    public function search(Query $query, SearchOptions $options): \Generator
    {
        $started = microtime(true);
        $root = $this->guard->resolveRoot($options->root);
        $matcher = new Matcher($query, (int) $this->config->get('max_hits_per_file', 50));

        yield ['type' => 'start', 'root' => \Poseidon\Support\Path::display($root), 'query' => $query->toArray()];

        $scanned = 0;
        $matched = 0;
        $inArchives = 0;
        $notices = 0;
        $totals = array_fill(0, count($query->terms), 0);
        $byExtension = [];
        $truncated = false;
        $lastProgress = microtime(true);

        foreach ($this->scanner->scan($root, $options->extensions, $options->intoArchives) as $item) {
            if ($item instanceof Notice) {
                $notices++;
                yield ['type' => 'notice'] + $item->toArray();
                continue;
            }

            /** @var Source $item */
            if (!$options->nameMatches($item->name())) {
                continue;
            }
            $scanned++;

            $result = $this->evaluate($item, $query, $matcher, $options);
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

        arsort($byExtension);
        yield ['type' => 'done', 'summary' => [
            'root' => \Poseidon\Support\Path::display($root),
            'scanned' => $scanned,
            'matched' => $matched,
            'inArchives' => $inArchives,
            'occurrences' => array_sum($totals),
            'termTotals' => $totals,
            'byExtension' => $byExtension,
            'notices' => $notices,
            'truncated' => $truncated,
            'durationMs' => (int) round((microtime(true) - $started) * 1000),
        ]];
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

    /** @return array<string, mixed>|Notice|null */
    private function evaluate(Source $source, Query $query, Matcher $matcher, SearchOptions $options): array|Notice|null
    {
        $nameCounts = array_fill(0, count($query->terms), 0);
        if ($options->target !== SearchOptions::TARGET_CONTENT) {
            foreach ($query->patterns as $i => $pattern) {
                $nameCounts[$i] = (int) preg_match_all($pattern, $source->name());
            }
            if ($options->target === SearchOptions::TARGET_NAME) {
                return $query->isMatch($nameCounts) ? $this->payload($source, $nameCounts, [], 0, true) : null;
            }
        }

        try {
            $document = $this->extractors->extract($source);
        } catch (UnreadableFileException $e) {
            return new Notice($e->reason, $source->absolutePath(), $e->getMessage());
        } catch (\Throwable $e) {
            return new Notice(UnreadableFileException::CORRUPT, $source->absolutePath(), 'Analyse impossible : ' . $e->getMessage());
        }

        if ($document === null) {
            return $options->target === SearchOptions::TARGET_BOTH && $query->isMatch($nameCounts)
                ? $this->payload($source, $nameCounts, [], 0, true)
                : null;
        }

        $result = $matcher->match($document);
        $counts = $result->counts;
        $nameHit = false;
        if ($options->target === SearchOptions::TARGET_BOTH && $query->isMatch($nameCounts)) {
            $nameHit = true;
        }
        if (!$query->isMatch($counts) && !$nameHit) {
            return null;
        }

        return $this->payload($source, $counts, $result->hits, $result->hitLines, $nameHit);
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
