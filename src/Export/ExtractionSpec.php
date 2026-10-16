<?php

declare(strict_types=1);

namespace Poseidon\Export;

/**
 * Ce que l'on extrait des fichiers trouvés, à la manière d'un SELECT :
 *
 *  - files   : une ligne par fichier (chemin, compteurs par terme) ;
 *  - matches : une ligne par occurrence (valeur trouvée, + groupes capturés en mode regex) ;
 *  - lines   : une ligne par ligne contenant une correspondance ;
 *  - columns : tableurs/CSV — lignes contenant une correspondance (ou toutes), colonnes choisies
 *              par lettre (A, C, AB) ou par nom d'en-tête (Client, Montant).
 */
final class ExtractionSpec
{
    public const FILES = 'files';
    public const MATCHES = 'matches';
    public const LINES = 'lines';
    public const COLUMNS = 'columns';

    public const FORMATS = ['csv', 'json', 'txt', 'md', 'xlsx'];

    /**
     * @param list<string> $columns
     */
    public function __construct(
        public readonly string $mode = self::MATCHES,
        public readonly string $format = 'csv',
        public readonly array $columns = [],
        public readonly int $headerRow = 1,
        public readonly bool $allRows = false,
        public readonly bool $withSource = true,
        public readonly bool $unique = false,
    ) {
        if (!in_array($mode, [self::FILES, self::MATCHES, self::LINES, self::COLUMNS], true)) {
            throw new \InvalidArgumentException('Mode d\'extraction inconnu : ' . $mode);
        }
        if (!in_array($format, self::FORMATS, true)) {
            throw new \InvalidArgumentException('Format de sortie inconnu : ' . $format);
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $columns = $data['columns'] ?? [];
        if (is_string($columns)) {
            $columns = preg_split('/\s*[,;]\s*/', trim($columns)) ?: [];
        }

        return new self(
            (string) ($data['extract'] ?? $data['mode'] ?? self::MATCHES),
            strtolower((string) ($data['format'] ?? 'csv')),
            array_values(array_filter(array_map('strval', (array) $columns), static fn (string $c): bool => $c !== '')),
            max(0, (int) ($data['headerRow'] ?? 1)),
            filter_var($data['allRows'] ?? false, FILTER_VALIDATE_BOOL),
            filter_var($data['withSource'] ?? true, FILTER_VALIDATE_BOOL),
            filter_var($data['unique'] ?? false, FILTER_VALIDATE_BOOL),
        );
    }
}
