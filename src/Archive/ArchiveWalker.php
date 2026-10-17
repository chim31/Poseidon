<?php

declare(strict_types=1);

namespace Poseidon\Archive;

use Poseidon\Config;
use Poseidon\Filesystem\Notice;
use Poseidon\Filesystem\Source;
use Poseidon\Filesystem\UnreadableFileException;
use Poseidon\Mail\MimeMessage;
use Poseidon\Mail\OutlookMessage;
use Poseidon\Support\Path;

/**
 * Aiguilleur des conteneurs : ZIP (natif), 7z / RAR / tar.* / ISO / CAB (bsdtar), tar et tar.gz
 * (PharData en repli), .gz simple, et courriels (.eml, .msg) dont les pièces jointes sont
 * parcourues comme des entrées d'archive. Les conteneurs s'imbriquent librement.
 */
final class ArchiveWalker
{
    private const ZIP = ['zip', 'jar', 'war', 'ear'];
    private const MAIL = ['eml', 'msg'];
    private const TAR = ['tar', 'tgz', 'tbz', 'tbz2', 'txz'];
    private const BSDTAR_ONLY = ['7z', 'rar', 'iso', 'cab', 'xz', 'bz2', 'lzh', 'cpio'];

    private ZipWalker $zip;

    public function __construct(private Config $config)
    {
        $this->zip = new ZipWalker($config, $this);
    }

    /** Ce nom de fichier désigne-t-il un conteneur que Poseidon sait ouvrir ? */
    public static function isContainer(string $name): bool
    {
        return self::kind($name) !== null;
    }

    /** @return 'zip'|'mail'|'tar'|'gz'|'bsdtar'|null */
    public static function kind(string $name): ?string
    {
        $lower = strtolower($name);
        $ext = Path::extension($lower);
        if (in_array($ext, self::ZIP, true)) {
            return 'zip';
        }
        if (in_array($ext, self::MAIL, true)) {
            return 'mail';
        }
        if (in_array($ext, self::TAR, true) || str_ends_with($lower, '.tar.gz') || str_ends_with($lower, '.tar.bz2') || str_ends_with($lower, '.tar.xz')) {
            return Bsdtar::binary() !== null || in_array($ext, ['tar', 'tgz'], true) || str_ends_with($lower, '.tar.gz') ? 'tar' : null;
        }
        if ($ext === 'gz') {
            return 'gz';
        }
        if (in_array($ext, self::BSDTAR_ONLY, true)) {
            return Bsdtar::binary() !== null ? 'bsdtar' : null;
        }

        return null;
    }

    /**
     * @param array<string, true> $filter
     * @return \Generator<int, Source|Notice>
     */
    public function walk(Source $container, array $filter = [], ?\Closure $descend = null): \Generator
    {
        $maxDepth = (int) $this->config->get('zip.max_depth');
        if ($container->archiveDepth() >= $maxDepth) {
            yield new Notice(UnreadableFileException::LIMIT, $container->absolutePath(), sprintf('Archive non ouverte : profondeur maximale (%d) atteinte.', $maxDepth), $container->key());

            return;
        }

        $kind = self::kind($container->name());
        if ($kind === 'zip') {
            yield from $this->zip->walk($container, $filter, $descend);

            return;
        }

        try {
            $entries = match ($kind) {
                'mail' => $this->mailEntries($container),
                'gz' => $this->gzipEntries($container),
                'tar', 'bsdtar' => $this->tarEntries($container),
                default => [],
            };
        } catch (UnreadableFileException $e) {
            yield new Notice($e->reason, $container->absolutePath(), $e->getMessage(), $container->key());

            return;
        }

        yield from $this->entries($container, $entries, $filter, $descend);
    }

    /**
     * Lit une entrée au bout d'une chaîne de conteneurs (a.zip › b.7z › mail.eml › facture.pdf),
     * quel que soit le type de chaque conteneur.
     *
     * @param list<string> $chain
     */
    public function readChain(string $diskPath, array $chain): string
    {
        $current = new Source($diskPath, [], dirname($diskPath), 0, (int) @filemtime(Path::toNative($diskPath)));
        if ($chain === []) {
            return $current->bytes();
        }
        $prefix = [];
        foreach ($chain as $name) {
            $data = $this->readEntry($current, $name);
            $prefix[] = $name;
            $current = new Source($diskPath, $prefix, dirname($diskPath), strlen($data), $current->mtime, static fn (): string => $data);
        }

        return $current->bytes();
    }

    private function readEntry(Source $container, string $name): string
    {
        $kind = self::kind($container->name());
        if ($kind === 'zip') {
            $handle = ZipHandle::open($container);
            try {
                $stat = $handle->zip->statName($name);
                if ($stat === false) {
                    throw new UnreadableFileException('Entrée introuvable dans l\'archive : ' . $name, UnreadableFileException::CORRUPT);
                }
                if (($stat['encryption_method'] ?? 0) !== 0) {
                    throw UnreadableFileException::protected('Entrée d\'archive chiffrée');
                }
                if ((int) $stat['size'] > (int) $this->config->get('zip.max_entry_size')) {
                    throw new UnreadableFileException('Entrée trop volumineuse.', UnreadableFileException::TOO_LARGE);
                }
                $data = $handle->zip->getFromName($name);
            } finally {
                $handle->close();
            }
            if ($data === false) {
                throw new UnreadableFileException('Entrée d\'archive illisible : ' . $name, UnreadableFileException::CORRUPT);
            }

            return $data;
        }
        $entries = match ($kind) {
            'mail' => $this->mailEntries($container),
            'gz' => $this->gzipEntries($container),
            'tar', 'bsdtar' => $this->tarEntries($container),
            default => throw new UnreadableFileException('Conteneur non pris en charge : ' . $container->name(), UnreadableFileException::UNSUPPORTED),
        };
        foreach ($entries as $entry) {
            if ($entry['name'] === $name) {
                return ($entry['load'])();
            }
        }
        throw new UnreadableFileException('Entrée introuvable dans l\'archive : ' . $name, UnreadableFileException::CORRUPT);
    }

    /**
     * @param list<array{name: string, size: int, load: \Closure}> $entries
     * @param array<string, true>                                   $filter
     * @return \Generator<int, Source|Notice>
     */
    private function entries(Source $container, array $entries, array $filter, ?\Closure $descend): \Generator
    {
        $maxEntries = (int) $this->config->get('zip.max_entries');
        if (count($entries) > $maxEntries) {
            yield new Notice(UnreadableFileException::LIMIT, $container->absolutePath(), sprintf('Archive ignorée : %d entrées (limite %d).', count($entries), $maxEntries), $container->key());

            return;
        }
        foreach ($entries as $entry) {
            $ext = Path::extension($entry['name']);
            $wanted = $filter === [] || isset($filter[$ext]);
            $nested = self::isContainer($entry['name']);
            if (!$wanted && !$nested) {
                continue;
            }
            $source = new Source($container->diskPath, [...$container->innerPath, $entry['name']], $container->root, $entry['size'], $container->mtime, $entry['load']);
            if ($wanted) {
                yield $source;
            }
            if ($nested && ($descend === null || $descend($source))) {
                yield from $this->walk($source, $filter, $descend);
            }
        }
    }

    /** @return list<array{name: string, size: int, load: \Closure}> */
    private function mailEntries(Source $container): array
    {
        $data = $container->bytes();
        $message = str_starts_with($data, "\xD0\xCF\x11\xE0") ? OutlookMessage::parse($data) : MimeMessage::parse($data);
        $out = [];
        $used = [];
        foreach ($message->attachments as $attachment) {
            $name = $attachment['name'];
            for ($i = 2; isset($used[$name]); $i++) {
                $name = pathinfo($attachment['name'], PATHINFO_FILENAME) . " ({$i})." . pathinfo($attachment['name'], PATHINFO_EXTENSION);
            }
            $used[$name] = true;
            $bytes = $attachment['data'];
            $out[] = ['name' => $name, 'size' => strlen($bytes), 'load' => static fn (): string => $bytes];
        }

        return $out;
    }

    /** @return list<array{name: string, size: int, load: \Closure}> */
    private function gzipEntries(Source $container): array
    {
        $max = (int) $this->config->get('zip.max_entry_size');
        $data = @gzdecode($container->bytes(), $max);
        if ($data === false) {
            throw new UnreadableFileException('Fichier .gz illisible, corrompu ou trop volumineux une fois décompressé.', UnreadableFileException::CORRUPT);
        }
        $name = preg_replace('/\.gz$/i', '', $container->name()) ?: 'contenu';

        return [['name' => $name, 'size' => strlen($data), 'load' => static fn (): string => $data]];
    }

    /** @return list<array{name: string, size: int, load: \Closure}> */
    private function tarEntries(Source $container): array
    {
        $max = (int) $this->config->get('zip.max_entry_size');
        [$path, $cleanup] = $this->onDisk($container);
        $out = [];

        if (Bsdtar::binary() !== null) {
            foreach (Bsdtar::entries($path) as $name) {
                $out[] = ['name' => $name, 'size' => 0, 'load' => static function () use ($path, $name, $max): string {
                    return Bsdtar::read($path, $name, $max);
                }];
            }
        } else {
            $budget = (int) $this->config->get('zip.max_total_size');
            // .tar.gz / .tgz : décompression par PHP (PharData rend un contenu vide sous Windows).
            if (preg_match('/\.(tgz|gz)$/i', $container->name())) {
                $tar = @gzdecode((string) file_get_contents($path), $budget);
                if ($tar === false) {
                    throw new UnreadableFileException('Archive tar.gz illisible, corrompue ou trop volumineuse.', UnreadableFileException::CORRUPT);
                }
                if ($cleanup) {
                    @unlink($path);
                }
                $path = (string) tempnam(sys_get_temp_dir(), 'poseidon_tar_');
                @rename($path, $path . '.tar');
                $path .= '.tar';
                file_put_contents($path, $tar);
                $cleanup = true;
            }
            try {
                $phar = new \PharData($path);
                foreach (new \RecursiveIteratorIterator($phar) as $file) {
                    /** @var \PharFileInfo $file */
                    if ($file->isDir()) {
                        continue;
                    }
                    // Chemin interne relatif (Windows mélange « \\ » et « / » dans les chemins phar://).
                    $full = str_replace('\\', '/', $file->getPathname());
                    $base = str_replace('\\', '/', $phar->getPath());
                    $pos = strpos($full, $base);
                    $name = ltrim($pos === false ? basename($full) : substr($full, $pos + strlen($base)), '/');
                    if ($file->getSize() > $max) {
                        continue;
                    }
                    // Lecture immédiate : sous Windows, l'archive phar fermée ne rend plus son contenu ensuite.
                    $budget -= (int) $file->getSize();
                    if ($budget < 0) {
                        break;
                    }
                    $content = (string) $file->getContent();
                    $out[] = ['name' => $name, 'size' => strlen($content), 'load' => static fn (): string => $content];
                }
            } catch (\Throwable) {
                throw new UnreadableFileException('Archive tar illisible ou corrompue.', UnreadableFileException::CORRUPT);
            }
        }

        // Le fichier temporaire d'une archive imbriquée vit tant que ses entrées peuvent être lues.
        if ($cleanup) {
            register_shutdown_function(static fn () => @unlink($path));
        }

        return $out;
    }

    /** @return array{0: string, 1: bool} Chemin lisible par un outil externe, et s'il faut le supprimer. */
    private function onDisk(Source $container): array
    {
        if (!$container->inArchive()) {
            return [Path::toNative($container->diskPath), false];
        }
        $suffix = preg_match('/(\.tar\.(?:gz|bz2|xz)|\.[a-z0-9]+)$/i', $container->name(), $m) ? strtolower($m[1]) : '.bin';
        $temp = tempnam(sys_get_temp_dir(), 'poseidon_arc_');
        if ($temp === false) {
            throw new UnreadableFileException('Fichier temporaire impossible pour l\'archive imbriquée.');
        }
        $target = $temp . $suffix;
        @rename($temp, $target);
        file_put_contents($target, $container->bytes());
        $container->releaseBytes();

        return [$target, true];
    }
}
