<?php

declare(strict_types=1);

/*
 * Suite de tests de Poseidon, sans dépendance (PHPUnit exige ext-dom).
 * Lancement : php tests/run.php
 */

require dirname(__DIR__) . '/bootstrap.php';


use Poseidon\Config;
use Poseidon\Export\DataExtractor;
use Poseidon\Export\ExtractionSpec;
use Poseidon\Export\Writer;
use Poseidon\Filesystem\FileLocator;
use Poseidon\Search\Diff;
use Poseidon\Search\Query;
use Poseidon\Search\SearchEngine;
use Poseidon\Search\SearchOptions;
use Poseidon\Support\Path;
use Poseidon\Support\Text;
use Poseidon\Demo\Samples as Fixtures;

$failures = 0;
$passed = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "  \033[32m✓\033[0m {$label}\n";
    } else {
        $failures++;
        echo "  \033[31m✗ {$label}\033[0m" . ($detail !== '' ? "\n      {$detail}" : '') . "\n";
    }
}

function same(string $label, mixed $expected, mixed $actual): void
{
    check($label, $expected === $actual, 'attendu ' . var_export($expected, true) . ', obtenu ' . var_export($actual, true));
}

function section(string $title): void
{
    echo "\n\033[1m{$title}\033[0m\n";
}

$dir = sys_get_temp_dir() . '/poseidon-tests-' . getmypid();
Fixtures::build($dir);
$cacheDir = $dir . '-cache';
$config = Config::load()->with(['allowed_roots' => [], 'cache_dir' => $cacheDir]);
$engine = new SearchEngine($config);

/** @return array<string, array<string, mixed>> Résultats indexés par chemin relatif. */
function search(SearchEngine $engine, string $root, string $q, array $opts = []): array
{
    $res = $engine->collect(Query::fromArray(['q' => $q] + $opts), SearchOptions::fromArray(['root' => $root] + $opts));
    $out = [];
    foreach ($res['matches'] as $m) {
        $out[$m['file']['relativePath']] = $m;
    }
    $out['__notices'] = $res['notices'];
    $out['__summary'] = $res['summary'];

    return $out;
}

try {
    section('Chemins (slash, anti-slash, UNC, lecteurs)');
    same('anti-slash Windows', 'C:/Users/Moi/Docs', Path::normalize('c:\\Users\\Moi\\Docs\\'));
    same('UNC conservé', '//srv-archives/juridique/2026', Path::normalize('\\\\srv-archives\\juridique\\2026'));
    same('UNC ne remonte pas au-dessus du partage', '//srv/share', Path::normalize('\\\\srv\\share\\..\\..'));
    same('segments . et ..', '/home/a/c', Path::normalize('/home/a/./b/../c'));
    same('slashs mélangés', 'D:/a/b/c', Path::normalize('D:/a\\b//c'));
    same('guillemets de l\'explorateur', 'C:/Program Files/x', Path::normalize('"C:\\Program Files\\x"'));
    same('file:// Windows', 'C:/Docs/a b', Path::normalize('file:///C:/Docs/a%20b'));
    same('smb://', '//nas/partage', Path::normalize('smb://nas/partage'));
    same('affichage UNC natif', '\\\\srv\\share\\a', Path::display('//srv/share/a'));
    same('relatif', 'b/c.txt', Path::relativeTo('/a/b/c.txt', '/a'));
    same('hors racine', null, Path::relativeTo('/ab/c.txt', '/a'));
    check('traversée bloquée', !Path::isInside('/a/../etc/passwd', '/a'));

    section('Filtre');
    same('découpage en mots', ['contrat', 'résiliation', 'préavis'], (new Query('contrat, résiliation préavis'))->terms);
    same('guillemets = un seul terme', ['préavis de 30 jours', 'contrat'], (new Query('"préavis de 30 jours" contrat'))->terms);
    check('accents ignorés', preg_match((new Query('preavis'))->patterns[0], 'un PRÉAVIS') === 1);
    check('accents respectés si demandé', preg_match((new Query('preavis', ignoreAccents: false))->patterns[0], 'un préavis') === 0);
    check('mot entier', preg_match((new Query('contrat', wholeWord: true))->patterns[0], 'contrats') === 0);
    check('casse respectée', preg_match((new Query('Contrat', caseSensitive: true))->patterns[0], 'contrat') === 0);
    check('regex invalide refusée', (static function (): bool {
        try {
            new Query('([', Query::REGEX);
        } catch (InvalidArgumentException) {
            return true;
        }

        return false;
    })());

    section('Recherche multi-formats');
    $r = search($engine, $dir, 'contrat préavis');
    foreach ([
        'juridique/contrats/Contrat-cadre_Nereide.docx' => 'DOCX',
        'juridique/CGV_v4.pdf' => 'PDF',
        'juridique/avenant.odt' => 'ODT',
        'finance/resiliations_T3.xlsx' => 'XLSX',
        'notes/reunion_2026-09.md' => 'Markdown',
        'notes/ancien_poste.txt' => 'TXT Windows-1252',
        'archives/2025.zip › clients/nereide.zip › contrats/Avenant_02.docx' => 'DOCX dans un ZIP dans un ZIP',
    ] as $path => $label) {
        check("trouvé : {$label}", isset($r[$path]), $path);
    }
    same('occurrences DOCX par terme', [4, 2], $r['juridique/contrats/Contrat-cadre_Nereide.docx']['counts'] ?? null);
    check('mode ET exclut un fichier incomplet', !isset($r['archives/2025.zip › compte-rendu.txt']));
    check('chemin absolu inclut la chaîne d\'archives', str_contains($r['archives/2025.zip › clients/nereide.zip › contrats/Avenant_02.docx']['file']['absolutePath'] ?? '', '2025.zip › clients/nereide.zip › contrats/Avenant_02.docx'));
    same('profondeur d\'archive', 2, $r['archives/2025.zip › clients/nereide.zip › contrats/Avenant_02.docx']['file']['archiveDepth'] ?? null);

    $any = search($engine, $dir, 'résiliation reconduit', ['mode' => 'any']);
    check('mode OU', isset($any['notes/ancien_poste.txt']) && isset($any['juridique/courrier.rtf']));
    $phrase = search($engine, $dir, 'préavis de 60 jours', ['mode' => 'phrase']);
    same('phrase exacte', ['archives/2025.zip › clients/nereide.zip › contrats/Avenant_02.docx'], array_values(array_filter(array_keys($phrase), static fn ($k) => !str_starts_with($k, '__'))));
    $pptx = search($engine, $dir, 'trimestre');
    check('PPTX', isset($pptx['notes/presentation.pptx']));
    $header = search($engine, $dir, 'confidentiel');
    check('en-tête DOCX', isset($header['juridique/contrats/Contrat-cadre_Nereide.docx']));
    $ext = search($engine, $dir, 'contrat', ['extensions' => 'pdf']);
    same('filtre d\'extension', ['juridique/CGV_v4.pdf'], array_values(array_filter(array_keys($ext), static fn ($k) => !str_starts_with($k, '__'))));
    $name = search($engine, $dir, 'nereide', ['target' => 'name']);
    check('recherche dans les noms', isset($name['juridique/contrats/Contrat-cadre_Nereide.docx']) && isset($name['archives/2025.zip › clients/nereide.zip']));
    $noZip = search($engine, $dir, 'prolongé', ['archives' => false]);
    check('archives désactivées', count($noZip) === 2);

    section('Fichiers protégés et illisibles : avertissements, pas de blocage');
    $reasons = array_count_values(array_column($r['__notices'], 'reason'));
    check('ZIP chiffré signalé', ($reasons['protected'] ?? 0) >= 2, json_encode($reasons));
    check('DOCX protégé signalé', (bool) array_filter($r['__notices'], static fn ($n) => str_contains($n['path'], 'Contrat_protege.docx')));
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0 && DIRECTORY_SEPARATOR === '/') {
        check('fichier sans droit signalé', ($reasons['denied'] ?? 0) >= 1);
    }
    check('la recherche se termine', ($r['__summary']['matched'] ?? 0) >= 7);

    section('Extraction de données');
    $extractor = new DataExtractor($engine, $engine->extractors(), new FileLocator($engine->guard()));
    $data = $extractor->run(new Query('[A-Z]{2}\d{6}', Query::REGEX), SearchOptions::fromArray(['root' => $dir]), new ExtractionSpec(ExtractionSpec::MATCHES, unique: true, withSource: false));
    $values = array_column($data['rows'], 2);
    sort($values);
    same('références extraites', ['NR204511', 'NR990001', 'PF552190', 'TR118230'], array_values(array_unique($values)));
    $cols = $extractor->run(new Query('résilié', Query::PHRASE, wholeWord: true), SearchOptions::fromArray(['root' => $dir, 'extensions' => 'csv']), new ExtractionSpec(ExtractionSpec::COLUMNS, columns: ['Client', 'D']));
    same('colonnes par en-tête et par lettre', [['clients.csv', 'finance/clients.csv', 'clients.csv', '3', 'Triton Transports', '8300'], ['clients.csv', 'finance/clients.csv', 'clients.csv', '5', 'Protée & Fils', '2250']], $cols['rows']);

    $xlsx = Writer::write('xlsx', ['A', 'B'], [['=1+1', '42']]);
    file_put_contents($dir . '/out.xlsx', $xlsx);
    $back = (new Poseidon\Extractor\SpreadsheetExtractor())->extract(new Poseidon\Filesystem\Source($dir . '/out.xlsx', [], $dir, strlen($xlsx), time()));
    same('XLSX écrit puis relu', ['=1+1', '42'], array_values($back->tables[0]['rows'][2] ?? []));
    check('CSV neutralise les formules', str_contains(Writer::write('csv', ['A'], [['=CMD()']]), "'=CMD()"));
    check('CSV neutralise aussi les en-têtes et les espaces en tête', str_contains(Writer::write('csv', ['=HYPERLINK()'], [['  @SUM(A1)']]), "'=HYPERLINK()") && str_contains(Writer::write('csv', ['A'], [['  @SUM(A1)']]), "'  @SUM(A1)"));
    check('CSV garde les nombres négatifs', str_contains(Writer::write('csv', ['A'], [['-42']]), "\n-42"));

    section('Identifiants signés');
    $id = $r['juridique/CGV_v4.pdf']['file']['id'];
    $locator = new FileLocator($engine->guard());
    check('identifiant valide relu', $locator->locate($id)->name() === 'CGV_v4.pdf');
    $forged = rtrim(strtr(base64_encode((string) json_encode(['/etc/passwd', [], '/'])), '+/', '-_'), '=') . '.AAAAAAAAAAAAAAAAAAAAAA';
    check('identifiant forgé refusé', (static function () use ($locator, $forged): bool {
        try {
            $locator->locate($forged);
        } catch (InvalidArgumentException) {
            return true;
        }

        return false;
    })());
    $zipId = $r['archives/2025.zip › clients/nereide.zip › contrats/Avenant_02.docx']['file']['id'];
    check('lecture d\'une entrée de ZIP imbriqué', str_starts_with($locator->locate($zipId)->bytes(), 'PK'));
    $restricted = new SearchEngine($config->with(['allowed_roots' => [$dir . '/notes']]));
    check('racine hors périmètre refusée', (static function () use ($restricted, $dir): bool {
        try {
            iterator_to_array($restricted->search(new Query('x'), SearchOptions::fromArray(['root' => $dir . '/finance'])));
        } catch (DomainException) {
            return true;
        }

        return false;
    })());

    section('Comparaison (diff)');
    $ops = Diff::lines(['a', 'b', 'c', 'd'], ['a', 'B', 'c', 'd', 'e']);
    same('opérations', [[' ', 'a'], ['-', 'b'], ['+', 'B'], [' ', 'c'], [' ', 'd'], ['+', 'e']], $ops);
    same('en-tête unifié', '@@ -1,4 +1,5 @@', Diff::hunks($ops)[0]['header']);
    same('fichiers identiques', [], Diff::hunks(Diff::lines(['x'], ['x'])));
    $big = Diff::lines(array_map(static fn ($i) => "a{$i}", range(1, 3000)), array_map(static fn ($i) => "b{$i}", range(1, 3000)));
    check('diff très différent borné (repli grossier)', count($big) === 6000 && memory_get_peak_usage() < 512 * 1024 * 1024);

    section('Protections contre les fichiers piégés');
    $sheet = Fixtures::zip(['xl/workbook.xml' => '<workbook><sheets><sheet name="S" r:id="rId1"/></sheets></workbook>', 'xl/_rels/workbook.xml.rels' => '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>', 'xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>ok</t></is></c><c r="ZZZZ1" t="inlineStr"><is><t>loin</t></is></c></row></sheetData></worksheet>']);
    file_put_contents($dir . '/piege.xlsx', $sheet);
    $doc = (new Poseidon\Extractor\SpreadsheetExtractor())->extract(new Poseidon\Filesystem\Source($dir . '/piege.xlsx', [], $dir, strlen($sheet), time()));
    same('colonne hors limites Excel ignorée', [0 => 'ok'], $doc->tables[0]['rows'][1] ?? null);

    section('PDF sans pdftotext (lecteur PHP de secours)');
    $doc = (new Poseidon\Extractor\PdfExtractor($config))->withPhp(Fixtures::pdf(['Bonjour (Poseidon)', 'Ligne deux']));
    same('texte du PDF', ['Bonjour (Poseidon)', 'Ligne deux'], array_column($doc->lines, 1));

    section('Utilitaires');
    same('colonne 27 => AB', 'AB', Text::columnLetter(27));
    same('AB => 27', 27, Text::columnIndex('AB'));
    check('détection binaire', Text::looksBinary("\x00\x01\x02abc"));
} finally {
    Fixtures::rmdir($dir);
    Fixtures::rmdir($cacheDir);
}

echo "\n" . ($failures === 0 ? "\033[32m{$passed} tests réussis\033[0m" : "\033[31m{$failures} échec(s)\033[0m, {$passed} réussi(s)") . "\n";
exit($failures === 0 ? 0 : 1);
