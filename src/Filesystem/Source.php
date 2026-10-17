<?php

declare(strict_types=1);

namespace Poseidon\Filesystem;

use Poseidon\Support\Path;
use Poseidon\Support\Token;

/**
 * Un fichier à analyser : soit un fichier sur disque, soit une entrée d'archive
 * (éventuellement imbriquée : a.zip › b.zip › c.docx).
 */
final class Source
{
    /**
     * @param string        $diskPath  Fichier réel sur disque (le fichier lui-même ou l'archive racine).
     * @param list<string>  $innerPath Chaîne des entrées d'archives depuis $diskPath.
     * @param string        $root      Racine de recherche (pour le chemin relatif).
     */
    public function __construct(
        public readonly string $diskPath,
        public readonly array $innerPath,
        public readonly string $root,
        public readonly int $size,
        public readonly int $mtime,
        private ?\Closure $loader = null,
    ) {
    }

    private ?string $bytes = null;

    public function name(): string
    {
        $last = $this->innerPath === [] ? $this->diskPath : $this->innerPath[array_key_last($this->innerPath)];

        return basename(str_replace('\\', '/', $last));
    }

    public function extension(): string
    {
        return Path::extension($this->name());
    }

    public function inArchive(): bool
    {
        return $this->innerPath !== [];
    }

    /** Contenu fourni en mémoire (entrée d'archive, version Git…) : ne jamais relire le chemin sur le disque. */
    public function inMemory(): bool
    {
        return $this->loader !== null || $this->inArchive();
    }

    public function archiveDepth(): int
    {
        return count($this->innerPath);
    }

    /** Chemin absolu complet, archives incluses : C:\a\b.zip › dossier/c.docx */
    public function absolutePath(): string
    {
        return implode(' › ', [Path::display($this->diskPath), ...$this->innerPath]);
    }

    public function relativePath(): string
    {
        $rel = Path::relativeTo($this->diskPath, $this->root) ?? Path::normalize($this->diskPath);

        return implode(' › ', [$rel === '' ? basename($this->diskPath) : $rel, ...$this->innerPath]);
    }

    /** Dossier relatif contenant le fichier sur disque. */
    public function relativeDir(): string
    {
        $rel = Path::relativeTo($this->diskPath, $this->root) ?? '';
        $dir = dirname($rel);

        return $dir === '.' ? '' : $dir;
    }

    /** Identifiant signé transportable dans une URL (pour afficher / télécharger / comparer). */
    public function id(): string
    {
        return Token::sign((string) json_encode([Path::normalize($this->diskPath), $this->innerPath, Path::normalize($this->root)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array{0: string, 1: list<string>, 2: string} */
    public static function decodeId(string $id): array
    {
        $data = json_decode(Token::verify($id), true);
        if (!is_array($data) || !is_string($data[0] ?? null) || !is_array($data[1] ?? null) || !is_string($data[2] ?? null)) {
            throw new \InvalidArgumentException('Identifiant de fichier invalide.');
        }
        foreach ($data[1] as $entry) {
            if (!is_string($entry)) {
                throw new \InvalidArgumentException('Identifiant de fichier invalide.');
            }
        }

        return [$data[0], array_values($data[1]), $data[2]];
    }

    /** Contenu brut (chargé à la demande pour un fichier sur disque). */
    public function bytes(): string
    {
        if ($this->bytes === null) {
            if ($this->loader !== null) {
                $this->bytes = ($this->loader)();
            } else {
                $data = @file_get_contents(Path::toNative($this->diskPath));
                if ($data === false) {
                    throw new UnreadableFileException('Lecture impossible : droits insuffisants ou fichier verrouillé.', UnreadableFileException::DENIED);
                }
                $this->bytes = $data;
            }
        }

        return $this->bytes;
    }

    public function releaseBytes(): void
    {
        $this->bytes = null;
    }

    /** Identité stable (chemin disque + chaîne d'archives), indépendante de la date. */
    public function key(): string
    {
        return self::keyFor($this->diskPath, $this->innerPath);
    }

    /** @param list<string> $chain */
    public static function keyFor(string $disk, array $chain): string
    {
        return Path::normalize($disk) . "\x1F" . json_encode(array_values($chain), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Clé de cache stable tant que le fichier n'a pas changé. */
    public function cacheKey(): string
    {
        return sha1(Path::normalize($this->diskPath) . '|' . implode('|', $this->innerPath) . '|' . $this->size . '|' . $this->mtime);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'name' => $this->name(),
            'extension' => $this->extension(),
            'absolutePath' => $this->absolutePath(),
            'relativePath' => $this->relativePath(),
            'directory' => $this->relativeDir(),
            'archiveDepth' => $this->archiveDepth(),
            'size' => $this->size,
            'modified' => $this->mtime > 0 ? date(DATE_ATOM, $this->mtime) : null,
        ];
    }
}
