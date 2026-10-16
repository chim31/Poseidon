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

    public function __construct(private Config $config)
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
    public function walk(Source $archive, array $filter = []): \Generator
    {
        $budget = (int) $this->config->get('zip.max_total_size');
        yield from $this->walkOpened($archive, $filter, $budget);
    }

    /**
     * @param array<string, true> $filter
     * @return \Generator<int, Source|Notice>
     */
    private function walkOpened(Source $archive, array $filter, int &$budget): \Generator
    {
        $maxDepth = (int) $this->config->get('zip.max_depth');
        $maxEntry = (int) $this->config->get('zip.max_entry_size');
        $maxRatio = (int) $this->config->get('zip.max_ratio');
        $maxEntries = (int) $this->config->get('zip.max_entries');
        $label = $archive->absolutePath();

        if ($archive->archiveDepth() >= $maxDepth) {
            yield new Notice(UnreadableFileException::LIMIT, $label, sprintf('Archive non ouverte : profondeur maximale (%d) atteinte.', $maxDepth));

            return;
        }

        try {
            $handle = ZipHandle::open($archive);
        } catch (UnreadableFileException $e) {
            yield new Notice($e->reason, $label, $e->getMessage());

            return;
        }

        try {
            $zip = $handle->zip;
            if ($zip->numFiles > $maxEntries) {
                yield new Notice(UnreadableFileException::LIMIT, $label, sprintf('Archive ignorée : %d entrées (limite %d).', $zip->numFiles, $maxEntries));

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
                $isArchive = self::isArchive($ext);
                if (!$wanted && !$isArchive) {
                    continue;
                }

                $chain = [...$archive->innerPath, $name];
                $display = $archive->absolutePath() . ' › ' . $name;
                $size = (int) $stat['size'];

                if (($stat['encryption_method'] ?? 0) !== 0) {
                    $protectedCount++;
                    if ($protectedCount <= 20) {
                        yield new Notice(UnreadableFileException::PROTECTED, $display, 'Entrée chiffrée (archive protégée par mot de passe) : ignorée.');
                    }
                    continue;
                }
                if ($size > $maxEntry) {
                    yield new Notice(UnreadableFileException::TOO_LARGE, $display, 'Entrée trop volumineuse : ignorée.');
                    continue;
                }
                $compressed = max(1, (int) $stat['comp_size']);
                if ($size > 1024 * 1024 && intdiv($size, $compressed) > $maxRatio) {
                    yield new Notice(UnreadableFileException::LIMIT, $display, 'Taux de compression suspect (bombe ZIP ?) : entrée ignorée.');
                    continue;
                }
                if ($budget - $size < 0) {
                    yield new Notice(UnreadableFileException::LIMIT, $label, 'Volume décompressé maximal atteint : suite de l\'archive ignorée.');

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
                if ($isArchive) {
                    yield from $this->walkOpened($entry, $filter, $budget);
                }
            }
            if ($protectedCount > 20) {
                yield new Notice(UnreadableFileException::PROTECTED, $label, sprintf('%d entrées chiffrées au total dans cette archive.', $protectedCount));
            }
        } finally {
            $handle->close();
        }
    }
}
