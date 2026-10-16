<?php

declare(strict_types=1);

namespace Poseidon\Extractor;

use Poseidon\Filesystem\Source;

interface Extractor
{
    /** @return list<string> Extensions prises en charge (minuscules, sans point). */
    public function extensions(): array;

    /** @throws \Poseidon\Filesystem\UnreadableFileException */
    public function extract(Source $source): Document;
}
