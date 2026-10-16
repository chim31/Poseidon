<?php

declare(strict_types=1);

namespace Poseidon\Extractor;

use Poseidon\Filesystem\Source;
use Poseidon\Filesystem\UnreadableFileException;
use Poseidon\Support\Text;

/**
 * DOCX (Word 2007+), DOCM, DOTX — corps, en-têtes, pieds de page, notes.
 */
final class WordExtractor implements Extractor
{
    public function extensions(): array
    {
        return ['docx', 'docm', 'dotx'];
    }

    public function extract(Source $source): Document
    {
        $office = OfficeArchive::open($source);
        try {
            $body = $office->read('word/document.xml');
            if ($body === null) {
                throw new UnreadableFileException('Document Word sans contenu lisible.', UnreadableFileException::CORRUPT);
            }
            $lines = [];
            foreach (self::paragraphs($body) as $i => $text) {
                $lines[] = ['§ ' . ($i + 1), $text];
            }
            foreach ($office->names('#^word/(header|footer|footnotes|endnotes)\d*\.xml$#') as $part) {
                $kind = match (true) {
                    str_contains($part, 'header') => 'en-tête',
                    str_contains($part, 'footer') => 'pied de page',
                    default => 'note',
                };
                foreach (self::paragraphs((string) $office->read($part)) as $text) {
                    $lines[] = [$kind, $text];
                }
            }

            return new Document($lines);
        } finally {
            $office->close();
        }
    }

    /** @return list<string> */
    public static function paragraphs(string $xml): array
    {
        $xml = preg_replace('#<w:tab\b[^>]*/>#', '<w:t>&#9;</w:t>', $xml) ?? $xml;
        $xml = preg_replace('#<w:(br|cr)\b[^>]*/>#', ' ', $xml) ?? $xml;
        // Supprime le texte des champs masqués (instructions de champ).
        $xml = preg_replace('#<w:instrText\b[^>]*>.*?</w:instrText>#s', '', $xml) ?? $xml;
        $out = [];
        foreach (preg_split('#</w:p>#', $xml) ?: [] as $chunk) {
            preg_match_all('#<w:t(?:\s[^>]*)?>(.*?)</w:t>#s', $chunk, $m);
            $text = Text::xmlDecode(implode('', $m[1]));
            if (trim($text) !== '') {
                $out[] = $text;
            }
        }

        return $out;
    }
}
