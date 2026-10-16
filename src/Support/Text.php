<?php

declare(strict_types=1);

namespace Poseidon\Support;

/**
 * Outils texte : détection d'encodage, décodage XML sans ext-dom, binaire ou non.
 */
final class Text
{
    /** Convertit un contenu en UTF-8 (BOM UTF-8/16, UTF-8 valide, sinon Windows-1252). */
    public static function toUtf8(string $data): string
    {
        if (str_starts_with($data, "\xEF\xBB\xBF")) {
            return substr($data, 3);
        }
        if (str_starts_with($data, "\xFF\xFE")) {
            return mb_convert_encoding(substr($data, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($data, "\xFE\xFF")) {
            return mb_convert_encoding(substr($data, 2), 'UTF-8', 'UTF-16BE');
        }
        if (mb_check_encoding($data, 'UTF-8')) {
            return $data;
        }

        return mb_convert_encoding($data, 'UTF-8', 'Windows-1252');
    }

    /** Heuristique « fichier binaire » : octet nul ou trop de caractères de contrôle au début. */
    public static function looksBinary(string $data): bool
    {
        $sample = substr($data, 0, 8192);
        if ($sample === '') {
            return false;
        }
        if (str_starts_with($sample, "\xFF\xFE") || str_starts_with($sample, "\xFE\xFF")) {
            return false;
        }
        if (str_contains($sample, "\0")) {
            return true;
        }
        $control = preg_match_all('/[\x01-\x08\x0E-\x1A\x1C-\x1F]/', $sample);

        return $control > strlen($sample) / 20;
    }

    public static function xmlDecode(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Retire les balises XML et décode les entités. */
    public static function stripXml(string $xml): string
    {
        return self::xmlDecode((string) preg_replace('/<[^>]+>/', '', $xml));
    }

    /** Découpe un texte en lignes non vides avec leur numéro (1-based). */
    public static function lines(string $text, string $prefix = 'ligne '): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $i => $line) {
            if (trim($line) !== '') {
                $out[] = [$prefix . ($i + 1), rtrim($line)];
            }
        }

        return $out;
    }

    /** Convertit un index de colonne (0-based) en lettres Excel : 0 => A, 27 => AB. */
    public static function columnLetter(int $index): string
    {
        $letters = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letters = chr(65 + $mod) . $letters;
            $index = intdiv($index - $mod, 26);
        }

        return $letters;
    }

    /** Convertit des lettres de colonne en index 0-based : A => 0, AB => 27. */
    public static function columnIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $n = 0;
        for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }

        return $n - 1;
    }
}
