<?php

declare(strict_types=1);

namespace Poseidon\Index;

use Poseidon\Archive\ZipWalker;
use Poseidon\Config;
use Poseidon\Extractor\ExtractorRegistry;
use Poseidon\Filesystem\Notice;
use Poseidon\Filesystem\RootGuard;
use Poseidon\Filesystem\Scanner;
use Poseidon\Filesystem\Source;
use Poseidon\Filesystem\UnreadableFileException;
use Poseidon\Support\Path;

/**
 * Construit ou met à jour l'index d'une racine : parcours complet, extraction des seuls fichiers
 * nouveaux ou modifiés, suppression des fichiers disparus.
 */
final class Indexer
{
    public function __construct(
        private Config $config,
        private ExtractorRegistry $extractors,
        private RootGuard $guard,
    ) {
    }

    /**
     * @return \Generator<int, array<string, mixed>> Événements : start, progress, notice, done.
     */
    public function build(string $rootInput, bool $rebuild = false): \Generator
    {
        $started = microtime(true);
        $root = $this->guard->resolveRoot($rootInput);
        if ($rebuild) {
            Index::delete($this->config, $root);
        }
        $index = Index::create($this->config, $root);
        $generation = $index->nextGeneration();
        $scanner = new Scanner($this->config, new ZipWalker($this->config), $this->guard);

        yield ['type' => 'start', 'root' => Path::display($root)];

        $seen = 0;
        $added = 0;
        $unchanged = 0;
        $notices = 0;
        $last = microtime(true);
        $descend = static function (Source $archive) use ($index): bool {
            $index->clearArchiveNotices($archive->key());

            return true;
        };

        foreach ($scanner->scan($root, [], true, $descend) as $item) {
            if ($item instanceof Notice) {
                if ($item->archiveKey !== null) {
                    $index->addArchiveNotice($item->archiveKey, $item);
                }
                $notices++;
                yield ['type' => 'notice'] + $item->toArray();
                continue;
            }
            $seen++;
            $row = $index->fresh($item);
            if ($row !== null) {
                $index->touch($row['id'], $generation);
                $unchanged++;
                if ($row['status'] === 'notice') {
                    $notices++;
                }
            } else {
                try {
                    $index->store($item, $this->extractors->extract($item), null, $generation);
                } catch (UnreadableFileException $e) {
                    $index->store($item, null, new Notice($e->reason, $item->absolutePath(), $e->getMessage()), $generation);
                    $notices++;
                } catch (\Throwable $e) {
                    $index->store($item, null, new Notice(UnreadableFileException::CORRUPT, $item->absolutePath(), 'Analyse impossible : ' . $e->getMessage()), $generation);
                    $notices++;
                }
                $added++;
            }
            $item->releaseBytes();

            if (microtime(true) - $last > 0.25) {
                $last = microtime(true);
                yield ['type' => 'progress', 'scanned' => $seen, 'indexed' => $added, 'current' => $item->relativePath()];
            }
        }

        $removed = $index->prune($generation);
        $index->setMeta('built_at', date(DATE_ATOM));
        $index->commit();

        yield ['type' => 'done', 'summary' => [
            'root' => Path::display($root),
            'files' => $seen,
            'indexed' => $added,
            'unchanged' => $unchanged,
            'removed' => $removed,
            'notices' => $notices,
            'durationMs' => (int) round((microtime(true) - $started) * 1000),
        ] + ['stats' => $index->stats()]];
    }
}
