<?php

declare(strict_types=1);

namespace Poseidon\Search;

/**
 * Paramètres d'une recherche (hors filtre textuel, porté par Query).
 */
final class SearchOptions
{
    public const TARGET_CONTENT = 'content';
    public const TARGET_NAME = 'name';
    public const TARGET_BOTH = 'both';

    /**
     * @param list<string> $extensions Extensions retenues (vide = toutes).
     */
    public function __construct(
        public readonly string $root,
        public readonly array $extensions = [],
        public readonly bool $intoArchives = true,
        public readonly string $target = self::TARGET_CONTENT,
        public readonly int $maxResults = 5000,
        public readonly ?string $nameFilter = null,
    ) {
        if (!in_array($target, [self::TARGET_CONTENT, self::TARGET_NAME, self::TARGET_BOTH], true)) {
            throw new \InvalidArgumentException('Cible de recherche inconnue : ' . $target);
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $extensions = $data['extensions'] ?? [];
        if (is_string($extensions)) {
            $extensions = preg_split('/[\s,;]+/', $extensions) ?: [];
        }
        $extensions = array_values(array_unique(array_filter(array_map(
            static fn ($e): string => strtolower(ltrim(trim((string) $e), '.*')),
            (array) $extensions
        ))));

        $name = trim((string) ($data['name'] ?? ''));

        return new self(
            (string) ($data['root'] ?? ''),
            $extensions,
            filter_var($data['archives'] ?? true, FILTER_VALIDATE_BOOL),
            (string) ($data['target'] ?? self::TARGET_CONTENT),
            max(1, min(50000, (int) ($data['limit'] ?? 5000))),
            $name === '' ? null : $name,
        );
    }

    /** Filtre de nom façon shell (*.pdf, rapport_202?*) appliqué au nom du fichier. */
    public function nameMatches(string $name): bool
    {
        if ($this->nameFilter === null) {
            return true;
        }
        $regex = '~^' . strtr(preg_quote($this->nameFilter, '~'), ['\*' => '.*', '\?' => '.']) . '$~iu';

        return (bool) preg_match($regex, $name);
    }
}
