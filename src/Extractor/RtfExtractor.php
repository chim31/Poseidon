<?php

declare(strict_types=1);

namespace Poseidon\Extractor;

use Poseidon\Filesystem\Source;
use Poseidon\Support\Text;

/**
 * RTF : retire les mots de contrôle et les groupes de métadonnées.
 */
final class RtfExtractor implements Extractor
{
    public function extensions(): array
    {
        return ['rtf'];
    }

    public function extract(Source $source): Document
    {
        return $this->fromString($source->bytes());
    }

    public function fromString(string $rtf): Document
    {
        // Groupes à ignorer : tables de polices, couleurs, styles, infos, images.
        $rtf = preg_replace('#\{\\\\(?:\*\\\\[a-z]+|fonttbl|colortbl|stylesheet|info|pict)\b((?:[^{}]++|\{(?1)\})*)\}#s', '', $rtf) ?? $rtf;
        $rtf = preg_replace_callback("#\\\\'([0-9a-fA-F]{2})#", static fn (array $m): string => mb_convert_encoding(chr(hexdec($m[1])), 'UTF-8', 'Windows-1252'), $rtf) ?? $rtf;
        $rtf = preg_replace_callback('#\\\\u(-?\d+)\??#', static fn (array $m): string => mb_chr(((int) $m[1] + 65536) % 65536, 'UTF-8'), $rtf) ?? $rtf;
        $rtf = preg_replace('#\\\\(par|line|row)\b ?#', "\n", $rtf) ?? $rtf;
        $rtf = preg_replace('#\\\\tab\b ?#', "\t", $rtf) ?? $rtf;
        $rtf = preg_replace('#\\\\[a-zA-Z]+-?\d* ?#', '', $rtf) ?? $rtf;
        $rtf = str_replace(['\\{', '\\}', '\\\\', '{', '}'], ['{', '}', '\\', '', ''], $rtf);

        return new Document(Text::lines(mb_check_encoding($rtf, 'UTF-8') ? $rtf : mb_convert_encoding($rtf, 'UTF-8', 'Windows-1252')));
    }
}
