<?php

declare(strict_types=1);

namespace Poseidon\Export;

use Poseidon\Support\Text;

/**
 * Écrivain XLSX minimal (SpreadsheetML + ZipArchive), sans dépendance :
 * une feuille, en-tête en gras figé, nombres reconnus, filtre automatique.
 */
final class XlsxWriter
{
    /**
     * @param list<string>       $columns
     * @param list<list<string>> $rows
     */
    public static function build(string $sheetName, array $columns, array $rows): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'poseidon_xlsx_');
        $zip = new \ZipArchive();
        if ($temp === false || $zip->open($temp, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Création du fichier XLSX impossible.');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::esc(mb_substr($sheetName, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">' . self::esc("'" . $sheetName . "'") . '!$A$1:$' . Text::columnLetter(max(0, count($columns) - 1)) . '$' . (count($rows) + 1) . '</definedName></definedNames>'
            . '</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0B2340"/></patternFill></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="2"><xf/><xf fontId="1" fillId="2" applyFont="1" applyFill="1"/></cellXfs>'
            . '</styleSheet>');

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetData>';
        $xml .= self::row(1, $columns, true);
        foreach ($rows as $i => $row) {
            $xml .= self::row($i + 2, $row, false);
        }
        $xml .= '</sheetData>';
        if ($columns !== []) {
            $xml .= '<autoFilter ref="A1:' . Text::columnLetter(count($columns) - 1) . (count($rows) + 1) . '"/>';
        }
        $xml .= '</worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();

        $data = (string) file_get_contents($temp);
        @unlink($temp);

        return $data;
    }

    /** @param list<string> $cells */
    private static function row(int $number, array $cells, bool $header): string
    {
        $out = '<row r="' . $number . '">';
        foreach (array_values($cells) as $c => $value) {
            $ref = Text::columnLetter($c) . $number;
            $style = $header ? ' s="1"' : '';
            if (!$header && preg_match('/^-?(0|[1-9]\d{0,14})(\.\d+)?$/', $value)) {
                $out .= '<c r="' . $ref . '"' . $style . '><v>' . $value . '</v></c>';
            } else {
                $out .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . self::esc($value) . '</t></is></c>';
            }
        }

        return $out . '</row>';
    }

    private static function esc(string $value): string
    {
        // Retire les caractères de contrôle interdits en XML.
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
