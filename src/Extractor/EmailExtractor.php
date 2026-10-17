<?php

declare(strict_types=1);

namespace Poseidon\Extractor;

use Poseidon\Filesystem\Source;
use Poseidon\Mail\MimeMessage;
use Poseidon\Mail\OutlookMessage;
use Poseidon\Support\Text;

/**
 * Courriels .eml et .msg : en-têtes, corps, noms des pièces jointes.
 * Le contenu des pièces jointes est parcouru séparément (ArchiveWalker).
 */
final class EmailExtractor implements Extractor
{
    public function extensions(): array
    {
        return ['eml', 'msg'];
    }

    public function extract(Source $source): Document
    {
        $data = $source->bytes();
        $message = str_starts_with($data, "\xD0\xCF\x11\xE0") ? OutlookMessage::parse($data) : MimeMessage::parse($data);

        $labels = ['subject' => 'objet', 'from' => 'de', 'to' => 'à', 'cc' => 'copie', 'date' => 'date'];
        $lines = [];
        foreach ($labels as $key => $label) {
            if (($message->headers[$key] ?? '') !== '') {
                $lines[] = [$label, $message->headers[$key]];
            }
        }
        foreach (Text::lines($message->body, 'corps · L') as $line) {
            $lines[] = $line;
        }
        foreach ($message->attachments as $attachment) {
            $lines[] = ['pièce jointe', $attachment['name']];
        }

        return new Document($lines);
    }
}
