<?php

declare(strict_types=1);

namespace Poseidon\Search;

final class MatchResult
{
    /**
     * @param list<int>                                                                       $counts   Occurrences par terme.
     * @param list<array{location: string, text: string, spans: list<array{0:int,1:int,2:int}>}> $hits Lignes-preuves.
     */
    public function __construct(
        public readonly array $counts,
        public readonly array $hits,
        public readonly int $hitLines,
    ) {
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }
}
