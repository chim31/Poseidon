<?php

declare(strict_types=1);

namespace Poseidon\Extractor;

use Poseidon\Config;
use Poseidon\Filesystem\Source;
use Poseidon\Filesystem\UnreadableFileException;
use Poseidon\Support\Process;
use Poseidon\Support\Text;

/**
 * Anciens formats binaires Office (DOC, XLS, PPT) : antiword si présent pour .doc,
 * sinon extraction des chaînes lisibles (UTF-16LE et ASCII/CP1252). Résultat approximatif,
 * mais suffisant pour trouver un mot.
 */
final class LegacyOfficeExtractor implements Extractor
{
    private ?string $antiword;

    public function __construct(private Config $config)
    {
        $configured = $config->get('antiword');
        $this->antiword = is_string($configured) && $configured !== '' ? $configured : Process::which('antiword');
    }

    public function extensions(): array
    {
        return ['doc', 'xls', 'ppt', 'dot', 'xlt', 'pps'];
    }

    public function extract(Source $source): Document
    {
        $data = $source->bytes();
        if (!str_starts_with($data, "\xD0\xCF\x11\xE0")) {
            // Certains .doc sont en réalité du RTF ou du texte.
            if (str_starts_with($data, '{\\rtf')) {
                return (new RtfExtractor())->fromString($data);
            }
            throw new UnreadableFileException('Format Office ancien non reconnu.', UnreadableFileException::CORRUPT);
        }
        if (self::isEncrypted($data)) {
            throw UnreadableFileException::protected('Document Office protégé par mot de passe');
        }

        if ($this->antiword !== null && $source->extension() === 'doc' && !$source->inMemory()) {
            $result = Process::run([$this->antiword, '-m', 'UTF-8.txt', $source->diskPath], 20);
            if ($result['code'] === 0 && trim($result['stdout']) !== '') {
                return new Document(Text::lines($result['stdout']));
            }
        }

        return new Document(self::strings($data), [], ['Extraction approximative (format binaire ancien).']);
    }

    /** Indicateurs de chiffrement des formats OLE (Word, Excel, PowerPoint). */
    private static function isEncrypted(string $data): bool
    {
        return str_contains($data, "E\0n\0c\0r\0y\0p\0t\0e\0d\0P\0a\0c\0k\0a\0g\0e")
            || str_contains($data, "M\0i\0c\0r\0o\0s\0o\0f\0t\0 \0E\0n\0h\0a\0n\0c\0e\0d\0 \0C\0r\0y\0p\0t\0o");
    }

    /** @return list<array{0: string, 1: string}> */
    private static function strings(string $data): array
    {
        $found = [];
        // UTF-16LE : suites de caractères imprimables séparés par des octets nuls.
        if (preg_match_all('/(?:[\x20-\x7E\xA0-\xFF][\x00]|[\x09\x0A\x0D][\x00]){4,}/', $data, $m)) {
            foreach ($m[0] as $chunk) {
                $found[] = mb_convert_encoding($chunk, 'UTF-8', 'UTF-16LE');
            }
        }
        // 8 bits : suites imprimables (CP1252).
        if (preg_match_all('/[\x20-\x7E\xC0-\xFF\x09]{6,}/', $data, $m)) {
            foreach ($m[0] as $chunk) {
                $found[] = Text::toUtf8($chunk);
            }
        }

        $lines = [];
        $seen = [];
        foreach ($found as $chunk) {
            foreach (preg_split('/[\r\n]+/', $chunk) ?: [] as $line) {
                $line = trim($line);
                if (mb_strlen($line) < 4 || isset($seen[$line]) || !preg_match('/\p{L}{3}/u', $line)) {
                    continue;
                }
                $seen[$line] = true;
                $lines[] = ['fragment ' . (count($lines) + 1), $line];
            }
        }

        return $lines;
    }
}
