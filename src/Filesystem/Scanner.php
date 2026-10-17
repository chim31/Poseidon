<?php

declare(strict_types=1);

namespace Poseidon\Filesystem;

use Poseidon\Archive\ArchiveWalker;
use Poseidon\Config;
use Poseidon\Support\Path;

/**
 * Équivalent de « ls -R » : parcours récursif en flux d'une racine,
 * archives ZIP incluses. Produit des Source et des Notice (jamais d'exception
 * pour un fichier ou un dossier illisible).
 */
final class Scanner
{
    /** @var array<string, true> */
    private array $excluded;

    public function __construct(private Config $config, private ArchiveWalker $archives, private ?RootGuard $guard = null)
    {
        $this->excluded = array_fill_keys(array_map('strtolower', (array) $config->get('excluded_dirs', [])), true);
    }

    /**
     * @param list<string> $extensions Extensions retenues (vide = toutes).
     * @return \Generator<int, Source|Notice>
     */
    public function scan(string $root, array $extensions = [], bool $intoArchives = true, ?\Closure $descend = null): \Generator
    {
        $filter = array_fill_keys(array_map('strtolower', $extensions), true);
        $root = Path::normalize($root);
        $native = Path::toNative($root);

        if (is_file($native)) {
            yield from $this->file($root, dirname($root), $filter, $intoArchives, $descend);

            return;
        }

        $stack = [$root];
        $visited = [];
        $maxSize = (int) $this->config->get('max_file_size');

        while ($stack !== []) {
            $dir = array_pop($stack);
            $real = realpath(Path::toNative($dir));
            if ($real !== false) {
                if (isset($visited[$real])) {
                    continue; // boucle de liens symboliques
                }
                $visited[$real] = true;
            }

            $entries = @scandir(Path::toNative($dir), SCANDIR_SORT_ASCENDING);
            if ($entries === false) {
                yield new Notice(UnreadableFileException::DENIED, Path::display($dir), 'Dossier illisible : accès refusé.');
                continue;
            }

            $subdirs = [];
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir === '/' ? '/' . $entry : $dir . '/' . $entry;
                $nativePath = Path::toNative($path);
                if (is_link($nativePath) && !$this->linkAllowed($path)) {
                    yield new Notice(UnreadableFileException::LIMIT, Path::display($path), 'Lien symbolique vers l\'extérieur des racines autorisées : ignoré.');
                    continue;
                }
                if (is_dir($nativePath)) {
                    if (!isset($this->excluded[strtolower($entry)])) {
                        $subdirs[] = $path;
                    }
                    continue;
                }
                if (!is_file($nativePath)) {
                    continue;
                }
                $size = (int) @filesize($nativePath);
                if ($size > $maxSize) {
                    $ext = Path::extension($entry);
                    if ($filter === [] || isset($filter[$ext])) {
                        yield new Notice(UnreadableFileException::TOO_LARGE, Path::display($path), sprintf('Fichier ignoré : %s dépasse la limite configurée.', self::humanSize($size)));
                    }
                    continue;
                }
                yield from $this->file($path, $root, $filter, $intoArchives, $descend);
            }
            // Ordre alphabétique conservé : on empile à l'envers.
            foreach (array_reverse($subdirs) as $sub) {
                $stack[] = $sub;
            }
        }
    }

    /**
     * @param array<string, true> $filter
     * @return \Generator<int, Source|Notice>
     */
    private function file(string $path, string $root, array $filter, bool $intoArchives, ?\Closure $descend = null): \Generator
    {
        $native = Path::toNative($path);
        $ext = Path::extension($path);
        $source = new Source($path, [], $root, (int) @filesize($native), (int) @filemtime($native));

        if (!is_readable($native)) {
            if ($filter === [] || isset($filter[$ext])) {
                yield new Notice(UnreadableFileException::DENIED, Path::display($path), 'Fichier illisible : accès refusé.');
            }

            return;
        }

        if ($filter === [] || isset($filter[$ext])) {
            yield $source;
        }

        if ($intoArchives && ArchiveWalker::isContainer($path) && ($descend === null || $descend($source))) {
            yield from $this->archives->walk($source, $filter, $descend);
        }
    }

    /** Un lien n'est suivi que si sa cible reste dans les racines autorisées. */
    private function linkAllowed(string $path): bool
    {
        if ($this->guard === null || !$this->guard->isRestricted()) {
            return true;
        }
        try {
            $this->guard->assertAllowed(RootGuard::real($path));

            return true;
        } catch (\DomainException) {
            return false;
        }
    }

    public static function humanSize(int $bytes): string
    {
        $units = ['o', 'Ko', 'Mo', 'Go', 'To'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) $bytes : number_format($value, 1, ',', ' ')) . ' ' . $units[$i];
    }
}
