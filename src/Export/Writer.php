<?php

declare(strict_types=1);

namespace Poseidon\Export;

/**
 * Écriture d'un jeu de données dans le format demandé (CSV, JSON, TXT, Markdown, XLSX).
 */
final class Writer
{
    /**
     * @param list<string>       $columns
     * @param list<list<string>> $rows
     */
    public static function write(string $format, array $columns, array $rows): string
    {
        return match ($format) {
            'csv' => self::csv($columns, $rows),
            'json' => self::json($columns, $rows),
            'txt' => self::txt($rows),
            'md' => self::markdown($columns, $rows),
            'xlsx' => XlsxWriter::build('Extraction', $columns, $rows),
            default => throw new \InvalidArgumentException('Format inconnu : ' . $format),
        };
    }

    public static function mime(string $format): string
    {
        return match ($format) {
            'csv' => 'text/csv; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'md' => 'text/markdown; charset=utf-8',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'text/plain; charset=utf-8',
        };
    }

    /** CSV UTF-8 avec BOM et séparateur « ; » : s'ouvre directement dans Excel en français. */
    private static function csv(array $columns, array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $columns, ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($fh, array_map([self::class, 'neutralizeFormula'], $row), ';', '"', '');
        }
        rewind($fh);
        $out = (string) stream_get_contents($fh);
        fclose($fh);

        return $out;
    }

    private static function json(array $columns, array $rows): string
    {
        $objects = array_map(static fn (array $row): array => array_combine($columns, array_pad($row, count($columns), '')), $rows);

        return (string) json_encode($objects, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    }

    private static function txt(array $rows): string
    {
        return implode('', array_map(static fn (array $row): string => implode("\t", $row) . "\n", $rows));
    }

    private static function markdown(array $columns, array $rows): string
    {
        $esc = static fn (string $v): string => str_replace(['|', "\n"], ['\|', ' '], $v);
        $out = '| ' . implode(' | ', array_map($esc, $columns)) . " |\n";
        $out .= '|' . str_repeat(' --- |', count($columns)) . "\n";
        foreach ($rows as $row) {
            $out .= '| ' . implode(' | ', array_map($esc, $row)) . " |\n";
        }

        return $out;
    }

    /** Empêche l'injection de formules à l'ouverture dans un tableur (=, +, -, @). */
    private static function neutralizeFormula(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($value)
            ? "'" . $value
            : $value;
    }
}
