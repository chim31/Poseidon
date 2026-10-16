<?php

declare(strict_types=1);

namespace Poseidon\Extractor;

/**
 * Texte extrait d'un fichier, prêt pour la recherche.
 *
 * - lines  : liste de [emplacement, texte] (« ligne 12 », « p. 3 », « Feuille1 · L4 »).
 * - tables : pour les tableurs et CSV, les lignes de cellules par feuille,
 *            utilisées par l'extraction par colonnes (mapping).
 */
final class Document
{
    /**
     * @param list<array{0: string, 1: string}>                          $lines
     * @param list<array{name: string, rows: list<array<int, string>>}> $tables
     * @param list<string>                                               $warnings
     */
    public function __construct(
        public readonly array $lines,
        public readonly array $tables = [],
        public readonly array $warnings = [],
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function text(): string
    {
        return implode("\n", array_column($this->lines, 1));
    }

    public function isTabular(): bool
    {
        return $this->tables !== [];
    }
}
