<?php

declare(strict_types=1);

namespace Poseidon\Demo;

use Poseidon\Export\XlsxWriter;

/**
 * Génère un jeu d'échantillons SYNTHÉTIQUES (société fictive « Néréide Logistique ») couvrant
 * tous les formats : TXT, MD, CSV, DOCX, XLSX, ODT, PPTX, PDF, RTF, ZIP, ZIP dans ZIP,
 * ZIP chiffré, document Office protégé, fichier illisible.
 */
final class Samples
{
    public static function build(string $dir): void
    {
        self::rmdir($dir);
        @mkdir($dir . '/juridique/contrats', 0775, true);
        @mkdir($dir . '/finance', 0775, true);
        @mkdir($dir . '/archives', 0775, true);
        @mkdir($dir . '/notes', 0775, true);

        file_put_contents($dir . '/LISEZMOI.txt', "Jeu de données SYNTHÉTIQUE généré par Poseidon pour les tests.\nToutes les informations sont fictives.\n");

        file_put_contents($dir . '/notes/reunion_2026-09.md', <<<MD
        # Réunion juridique — septembre 2026

        - Revoir le **préavis** du contrat Néréide (90 jours).
        - La résiliation anticipée doit être notifiée par lettre recommandée.
        - Référence dossier : NR204511, puis TR118230.
        MD);

        file_put_contents($dir . '/notes/journal.log', "2026-10-01 INFO import terminé\n2026-10-02 WARN contrat introuvable : NR990001\n2026-10-03 INFO export OK\n");

        // Encodage Windows-1252 (fichier venant d'un vieux poste Windows).
        file_put_contents($dir . '/notes/ancien_poste.txt', mb_convert_encoding("Procès-verbal : le contrat est reconduit, préavis inchangé.\r\n", 'Windows-1252', 'UTF-8'));

        file_put_contents($dir . '/finance/clients.csv', "Client;Ville;Statut;Montant\nNéréide Logistique;Marseille;actif;12500\nTriton Transports;Brest;résilié;8300\nAmphitrite SA;Toulon;actif;4100\nProtée & Fils;Nantes;résilié;2250\n");

        file_put_contents($dir . '/finance/resiliations_T3.xlsx', XlsxWriter::build('T3', ['Référence', 'Client', 'Motif', 'Préavis (jours)', 'Montant'], [
            ['NR204511', 'Néréide Logistique', 'Résiliation pour faute', '0', '12500'],
            ['TR118230', 'Triton Transports', 'Fin de contrat', '90', '8300'],
            ['PF552190', 'Protée & Fils', 'Résiliation amiable', '30', '2250'],
        ]));

        file_put_contents($dir . '/juridique/contrats/Contrat-cadre_Nereide.docx', self::docx([
            'Contrat-cadre de prestations logistiques',
            'Article 9 — Durée du contrat',
            'Le présent contrat est conclu pour une durée de trois ans.',
            "Toute résiliation anticipée suppose un préavis de quatre-vingt-dix jours\tnotifié par lettre recommandée.",
            'Article 14 — Résiliation pour faute',
            'En cas de faute grave, le contrat peut être résilié sans préavis ni indemnité.',
        ], 'Néréide Logistique — confidentiel'));

        file_put_contents($dir . '/juridique/CGV_v4.pdf', self::pdf([
            'Conditions generales de vente - version 4',
            'Article 12 - Resiliation du contrat',
            'Le client peut resilier le contrat avec un preavis de 30 jours.',
        ]));

        file_put_contents($dir . '/juridique/avenant.odt', self::odt(['Avenant n°2 au contrat Triton', 'Le préavis est porté à soixante jours.']));
        file_put_contents($dir . '/notes/presentation.pptx', self::pptx(['Bilan des contrats 2026', 'Trois résiliations ce trimestre']));
        file_put_contents($dir . '/juridique/courrier.rtf', "{\\rtf1\\ansi{\\fonttbl{\\f0 Arial;}}\\f0 Objet : r\\'e9siliation du contrat\\par Madame, Monsieur,\\par}");

        // Archive avec une archive imbriquée.
        $inner = self::zip(['contrats/Avenant_02.docx' => self::docx(['Avenant 02', 'Le contrat Néréide est prolongé, préavis de 60 jours.'])]);
        file_put_contents($dir . '/archives/2025.zip', self::zip([
            'compte-rendu.txt' => "Compte rendu : aucun contrat résilié en 2025.\n",
            'clients/nereide.zip' => $inner,
            'images/logo.png' => "\x89PNG\r\n\x1A\n" . str_repeat("\0", 32),
        ]));

        // Archive chiffrée (protégée par mot de passe).
        $zip = new \ZipArchive();
        $zip->open($dir . '/archives/protege.zip', \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('secret.txt', 'contrat secret');
        $zip->setEncryptionName('secret.txt', \ZipArchive::EM_AES_256, 'motdepasse');
        $zip->close();

        // Document Office chiffré : en réalité un conteneur OLE (signature D0CF11E0).
        file_put_contents($dir . '/juridique/contrats/Contrat_protege.docx', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 504) . 'E' . "\0" . 'ncryptedPackage');

        // Fichier sans droit de lecture (ignoré sous Windows).
        file_put_contents($dir . '/notes/verrouille.txt', 'contrat verrouillé');
        @chmod($dir . '/notes/verrouille.txt', 0000);
    }

    /** @param list<string> $paragraphs */
    public static function docx(array $paragraphs, string $header = ''): string
    {
        $body = '';
        foreach ($paragraphs as $p) {
            $runs = implode('<w:tab/>', array_map(static fn (string $part): string => '<w:t xml:space="preserve">' . htmlspecialchars($part, ENT_XML1) . '</w:t>', explode("\t", $p)));
            $body .= '<w:p><w:r>' . $runs . '</w:r></w:p>';
        }
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body . '</w:body></w:document>',
        ];
        if ($header !== '') {
            $files['word/header1.xml'] = '<?xml version="1.0"?><w:hdr xmlns:w="x"><w:p><w:r><w:t>' . htmlspecialchars($header, ENT_XML1) . '</w:t></w:r></w:p></w:hdr>';
        }

        return self::zip($files);
    }

    /** @param list<string> $paragraphs */
    public static function odt(array $paragraphs): string
    {
        $body = implode('', array_map(static fn (string $p): string => '<text:p>' . htmlspecialchars($p, ENT_XML1) . '</text:p>', $paragraphs));

        return self::zip(['mimetype' => 'application/vnd.oasis.opendocument.text', 'content.xml' => '<?xml version="1.0"?><office:document-content xmlns:office="o" xmlns:text="t"><office:body><office:text>' . $body . '</office:text></office:body></office:document-content>']);
    }

    /** @param list<string> $slides */
    public static function pptx(array $slides): string
    {
        $files = [];
        foreach ($slides as $i => $text) {
            $files['ppt/slides/slide' . ($i + 1) . '.xml'] = '<?xml version="1.0"?><p:sld xmlns:p="p" xmlns:a="a"><a:p><a:r><a:t>' . htmlspecialchars($text, ENT_XML1) . '</a:t></a:r></a:p></p:sld>';
        }

        return self::zip($files);
    }

    /** PDF minimal valide (une page, police standard), lisible par pdftotext et par le lecteur PHP. */
    public static function pdf(array $lines): string
    {
        $content = "BT /F1 12 Tf 72 720 Td 16 TL\n";
        foreach ($lines as $line) {
            $content .= '(' . addcslashes($line, '()\\') . ") Tj T*\n";
        }
        $content .= 'ET';
        $stream = gzcompress($content);
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length ' . strlen($stream) . " /Filter /FlateDecode >>\nstream\n" . $stream . "\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $obj . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }

        return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    /** @param array<string, string> $files */
    public static function zip(array $files): string
    {
        $temp = (string) tempnam(sys_get_temp_dir(), 'poseidon_fx_');
        $zip = new \ZipArchive();
        $zip->open($temp, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $data) {
            $zip->addFromString($name, $data);
        }
        $zip->close();
        $data = (string) file_get_contents($temp);
        unlink($temp);

        return $data;
    }

    public static function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            @chmod($f->getPathname(), 0777);
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
