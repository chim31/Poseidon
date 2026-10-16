<?php

declare(strict_types=1);

namespace Poseidon\Extractor;

use Poseidon\Filesystem\Source;
use Poseidon\Filesystem\UnreadableFileException;
use Poseidon\Support\Text;

/**
 * ODT / ODP (LibreOffice) et PPTX (PowerPoint) : paragraphes de texte.
 */
final class OpenDocumentExtractor implements Extractor
{
    public function extensions(): array
    {
        return ['odt', 'odp', 'pptx', 'pptm'];
    }

    public function extract(Source $source): Document
    {
        $office = OfficeArchive::open($source);
        try {
            return str_starts_with($source->extension(), 'ppt') ? $this->pptx($office) : $this->odf($office);
        } finally {
            $office->close();
        }
    }

    private function odf(OfficeArchive $office): Document
    {
        $xml = $office->read('content.xml');
        if ($xml === null) {
            throw new UnreadableFileException('Document OpenDocument sans contenu lisible.', UnreadableFileException::CORRUPT);
        }
        $xml = preg_replace('#<text:tab\s*/>#', "\t", $xml) ?? $xml;
        $xml = preg_replace('#<text:s\b[^>]*/>#', ' ', $xml) ?? $xml;
        $xml = preg_replace('#<text:line-break\s*/>#', ' ', $xml) ?? $xml;
        preg_match_all('#<text:(p|h)\b[^>]*>(.*?)</text:\1>#s', $xml, $m);
        $lines = [];
        foreach ($m[2] as $i => $inner) {
            $text = Text::stripXml($inner);
            if (trim($text) !== '') {
                $lines[] = ['§ ' . ($i + 1), $text];
            }
        }

        return new Document($lines);
    }

    private function pptx(OfficeArchive $office): Document
    {
        $lines = [];
        foreach ($office->names('#^ppt/(slides|notesSlides)/(slide|notesSlide)\d+\.xml$#') as $part) {
            preg_match('#(\d+)\.xml$#', $part, $num);
            $label = (str_contains($part, 'notes') ? 'notes diapo ' : 'diapo ') . $num[1];
            foreach (preg_split('#</a:p>#', (string) $office->read($part)) ?: [] as $chunk) {
                preg_match_all('#<a:t>(.*?)</a:t>#s', $chunk, $t);
                $text = Text::xmlDecode(implode('', $t[1]));
                if (trim($text) !== '') {
                    $lines[] = [$label, $text];
                }
            }
        }

        return new Document($lines);
    }
}
