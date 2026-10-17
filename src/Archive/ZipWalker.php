<?php

declare(strict_types=1);

namespace Poseidon\Archive;

use Poseidon\Config;
use Poseidon\Filesystem\Notice;
use Poseidon\Filesystem\Source;
use Poseidon\Filesystem\UnreadableFileException;
use Poseidon\Support\Path;

/**
 * Parcours récursif des archives ZIP (ZIP dans ZIP...), sans extraction sur disque
 * des fichiers ordinaires. Protégé contre les bombes ZIP (profondeur, taille, ratio,
 * nombre d'entrées) et contre les entrées chiffrées.
 */
final class ZipWalker
{
    private const ARCHIVE_EXTENSIONS = ['zip', 'jar', 'war', 'ear'];

    public function __construct(private Config $config, private ?ArchiveWalker $dispatcher = null)
    {
    }

    public static function isArchive(string $extension): bool
    {
        return in_array(strtolower($extension), self::ARCHIVE_EXTENSIONS, true);
    }

    /**
     * @param array<string, true> $filter
     * @return \Generator<int, Source|Notice>
     */
    public function walk(Source $archive, array $filter = [], ?\Closure $descend = null): \Generator
    {
        $budget = (int) $this->config->get('zip.max_total_size');
        yield from $this->walkOpened($archive, $filter, $budget, $descend);
    }

    /**
     * @param array<string, true> $filter
     * @return \Generator<int, Source|Notice>
     */
    private function walkOpened(Source $archive, array $filter, int &$budget, ?\Closure $descend = null): \Generator
    {
        $akey = $archive->key();
        $maxDepth = (int) $this->config->get('zip.max_depth');
        $maxEntry = (int) $this->config->get('zip.max_entry_size');
        $maxRatio = (int) $this->config->get('zip.max_ratio');
        $maxEntries = (int) $this->config->get('zip.max_entries');
        $label = $archive->absolutePath();

        if ($archive->archiveDepth() >= $maxDepth) {
            yield new Notice(UnreadableFileException::LIMIT, $label, sprintf('Archive non ouverte : profondeur maximale (%d) atteinte.', $maxDepth), $akey);

            return;
        }

        try {
            $handle = ZipHandle::open($archive);
        } catch (UnreadableFileException $e) {
            yield new Notice($e->reason, $label, $e->getMessage(), $akey);

            return;
        }

        try {
            $zip = $handle->zip;
            if ($zip->numFiles > $maxEntries) {
                yield new Notice(UnreadableFileException::LIMIT, $label, sprintf('Archive ignorée : %d entrées (limite %d).', $zip->numFiles, $maxEntries), $akey);

                return;
            }
            $protectedCount = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i, \ZipArchive::FL_UNCHANGED);
                if ($stat === false) {
                    continue;
                }
                $name = (string) $stat['name'];
                if (str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/')) {
                    continue;
                }
                $ext = Path::extension($name);
                $wanted = $filter === [] || isset($filter[$ext]);
                $isArchive = $this->dispatcher !== null ? ArchiveWalker::isContainer($name) : self::isArchive($ext);
                if (!$wanted && !$isArchive) {
                    continue;
                }

                $chain = [...$archive->innerPath, $name];
                $display = $archive->absolutePath() . ' › ' . $name;
                $size = (int) $stat['size'];

                if (($stat['encryption_method'] ?? 0) !== 0) {
                    $protectedCount++;
                    if ($protectedCount <= 20) {
                        yield new Notice(UnreadableFileException::PROTECTED, $display, 'Entrée chiffrée (archive protégée par mot de passe) : ignorée.', $akey);
                    }
                    continue;
                }
                if ($size > $maxEntry) {
                    yield new Notice(UnreadableFileException::TOO_LARGE, $display, 'Entrée trop volumineuse : ignorée.', $akey);
                    continue;
                }
                $compressed = max(1, (int) $stat['comp_size']);
                if ($size > 1024 * 1024 && intdiv($size, $compressed) > $maxRatio) {
                    yield new Notice(UnreadableFileException::LIMIT, $display, 'Taux de compression suspect (bombe ZIP ?) : entrée ignorée.', $akey);
                    continue;
                }
                if ($budget - $size < 0) {
                    yield new Notice(UnreadableFileException::LIMIT, $label, 'Volume décompressé maximal atteint : suite de l\'archive ignorée.', $akey);

                    return;
                }

                $index = $i;
                $loader = static function () use ($zip, $index, $display): string {
                    $data = $zip->getFromIndex($index);
                    if ($data === false) {
                        throw new UnreadableFileException('Entrée d\'archive illisible : ' . $display, UnreadableFileException::CORRUPT);
                    }

                    return $data;
                };
                $entry = new Source($archive->diskPath, $chain, $archive->root, $size, (int) ($stat['mtime'] ?? 0), $loader);

                if ($wanted) {
                    $budget -= $size;
                    yield $entry;
                }
                if ($isArchive && ($descend === null || $descend($entry))) {
                    // ZIP dans ZIP : même budget ; autre conteneur (7z, courriel…) : l'aiguilleur.
                    yield from self::isArchive($ext) || $this->dispatcher === null
                        ? $this->walkOpened($entry, $filter, $budget, $descend)
                        : $this->dispatcher->walk($entry, $filter, $descend);
                }
            }
            if ($protectedCount > 20) {
                yield new Notice(UnreadableFileException::PROTECTED, $label, sprintf('%d entrées chiffrées au total dans cette archive.', $protectedCount), $akey);
            }
        } finally {
            $handle->close();
        }
    }
}
