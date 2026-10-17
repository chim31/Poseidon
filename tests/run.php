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
    section('Syntaxe PHP de tous les fichiers');
    $bad = [];
    $files = [dirname(__DIR__) . '/bin/poseidon', dirname(__DIR__) . '/bootstrap.php'];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/src', FilesystemIterator::SKIP_DOTS)) as $f) {
        $files[] = $f->getPathname();
    }
    foreach ($files as $f) {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f) . ' 2>&1', $o, $code);
        if ($code !== 0) {
            $bad[] = $f;
        }
    }
    check(count($files) . ' fichiers PHP sans erreur de syntaxe', $bad === [], implode(', ', $bad));

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
    $extractor = new DataExtractor($engine, $engine->extractors(), $engine->locator());
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
    $locator = $engine->locator();
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

    if (DIRECTORY_SEPARATOR === '/' && function_exists('symlink')) {
        @mkdir($dir . '/perimetre', 0775, true);
        file_put_contents($dir . '/perimetre/ok.txt', 'contrat interne');
        symlink($dir . '/finance', $dir . '/perimetre/fuite');
        $fenced = new SearchEngine($config->with(['allowed_roots' => [$dir . '/perimetre']]));
        $res = $fenced->collect(new Query('résilié', Query::ANY), SearchOptions::fromArray(['root' => $dir . '/perimetre']));
        check('lien symbolique hors périmètre non suivi', $res['matches'] === [] && (bool) array_filter($res['notices'], static fn ($n) => str_contains($n['path'], 'fuite')));
        check('racine par lien symbolique refusée', (static function () use ($fenced, $dir): bool {
            try {
                iterator_to_array($fenced->search(new Query('x'), SearchOptions::fromArray(['root' => $dir . '/perimetre/fuite'])));
            } catch (DomainException) {
                return true;
            }

            return false;
        })());
    }

    section('Formats étendus : courriels, archives, OCR');
    $ext = search($engine, $dir, 'préavis');
    check('courriel .eml (objet encodé, corps quoted-printable)', ($ext['courriels/relance-neptune.eml']['counts'] ?? null) === [2]);
    check('pièce jointe PDF d\'un .eml', isset(search($engine, $dir, 'quai')['courriels/relance-neptune.eml › commande-neptune.pdf']));
    check('message Outlook .msg', isset($ext['courriels/triton.msg']));
    check('pièce jointe DOCX d\'un .msg', isset($ext['courriels/triton.msg › avenant-triton.docx']));
    check('archive tar.gz', isset($ext['archives/sauvegarde.tar.gz › notes/escale.md']));
    Poseidon\Archive\Bsdtar::override(false);
    check('archive tar.gz sans bsdtar (PharData, PHP pur)', isset(search(new SearchEngine($config->with(['cache_dir' => ''])), $dir . '/archives', 'escale')['sauvegarde.tar.gz › notes/escale.md']));
    Poseidon\Archive\Bsdtar::override(null);
    check('fichier .gz simple', isset(search($engine, $dir, 'NR204511')['notes/serveur.log.gz › serveur.log']));
    if (Poseidon\Archive\Bsdtar::binary() !== null) {
        check('archive 7z (bsdtar)', isset($ext['archives/historique.7z › vieux/contrat-1998.txt']));
    }
    $loc = $engine->locator();
    $readable = true;
    foreach (['archives/sauvegarde.tar.gz › notes/escale.md', 'courriels/triton.msg › avenant-triton.docx', 'archives/2025.zip › clients/nereide.zip › contrats/Avenant_02.docx'] + (Poseidon\Archive\Bsdtar::binary() !== null ? [3 => 'archives/historique.7z › vieux/contrat-1998.txt'] : []) as $path) {
        $readable = $readable && isset($ext[$path]) && strlen($loc->locate($ext[$path]['file']['id'])->bytes()) > 0;
    }
    check('relecture par identifiant dans tous les conteneurs (aperçu, téléchargement)', $readable && str_contains($loc->locate(search($engine, $dir, 'NR204511')['notes/serveur.log.gz › serveur.log']['file']['id'])->bytes(), 'NR204511'));
    $mail = Poseidon\Mail\MimeMessage::parse((string) file_get_contents($dir . '/courriels/relance-neptune.eml'));
    same('en-tête RFC 2047 décodé', 'Relance : préavis du contrat Neptune', $mail->headers['subject'] ?? null);
    same('expéditeur décodé', 'Amphitrite Durand <amphitrite@nereide.example>', $mail->headers['from'] ?? null);
    $big = str_repeat('grande ligne ', 600);
    $outlook = Poseidon\Mail\OutlookMessage::parse(Poseidon\Demo\CfbWriter::message('Objet é', 'A', 'B', 'corps', ['gros.txt' => $big]));
    same('pièce jointe .msg hors mini-flux (> 4 Ko)', $big, $outlook->attachments[0]['data'] ?? null);
    $forged = Poseidon\Demo\CfbWriter::message('x', 'a', 'b', 'c');
    $forged = substr_replace($forged, pack('V', 1), 512 + 4, 4); // secteur du répertoire qui pointe sur lui-même
    check('conteneur OLE forgé (chaîne cyclique) refusé sans boucler', (static function () use ($forged): bool {
        try {
            Poseidon\Mail\OutlookMessage::parse($forged);
        } catch (Poseidon\Filesystem\UnreadableFileException) {
            return true;
        }

        return false;
    })());
    $nested = "Subject: externe\r\nContent-Type: multipart/mixed; boundary=b\r\n\r\n--b\r\nContent-Type: text/plain\r\n\r\ncorps\r\n--b\r\nContent-Type: message/rfc822\r\n\r\nSubject: =?UTF-8?Q?interne_=C3=A9?=\r\n\r\ntexte interne\r\n--b--\r\n";
    same('message joint nommé par son objet (sans analyse récursive)', 'interne é.eml', Poseidon\Mail\MimeMessage::parse($nested)->attachments[0]['name'] ?? null);

    if (DIRECTORY_SEPARATOR === '/' && Poseidon\Support\Process::which('pdftoppm') !== null) {
        $fake = $dir . '-tesseract.sh';
        file_put_contents($fake, "#!/bin/sh\necho 'Texte reconnu : un kraken dans les abysses'\n");
        chmod($fake, 0755);
        @mkdir($dir . '/scans', 0775, true);
        file_put_contents($dir . '/scans/scan.pdf', Fixtures::pdf([]));
        file_put_contents($dir . '/scans/photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $ocrEngine = new SearchEngine($config->with(['ocr' => ['tesseract' => $fake], 'cache_dir' => '']));
        $ocr = search($ocrEngine, $dir . '/scans', 'kraken');
        check('OCR d\'une image (Tesseract simulé)', isset($ocr['photo.png']));
        check('OCR d\'un PDF scanné (pdftoppm + Tesseract simulé)', isset($ocr['scan.pdf']) && str_contains($ocr['scan.pdf']['hits'][0]['location'] ?? '', 'OCR'));
        Fixtures::rmdir($dir . '/scans');
        @unlink($fake);
    }

    section('Index plein texte (résultats identiques avec et sans index)');
    if (!Poseidon\Index\Index::available()) {
        echo "  (pdo_sqlite / FTS5 indisponible : section ignorée)\n";
    } else {
        $idxConfig = $config->with(['index_dir' => $dir . '-index']);
        $idxEngine = new SearchEngine($idxConfig);
        $indexer = new Poseidon\Index\Indexer($idxConfig, $idxEngine->extractors(), $idxEngine->guard());
        $build = static function (bool $rebuild = false) use ($indexer, $dir): array {
            $summary = [];
            foreach ($indexer->build($dir, $rebuild) as $e) {
                if ($e['type'] === 'done') {
                    $summary = $e['summary'];
                }
            }

            return $summary;
        };
        $first = $build();
        check('construction de l\'index', $first['files'] >= 18 && $first['indexed'] === $first['files']);
        $fingerprint = static function (SearchEngine $engine, array $params): array {
            $res = $engine->collect(Query::fromArray($params), SearchOptions::fromArray(['root' => $GLOBALS['dir']] + $params));
            $files = [];
            foreach ($res['matches'] as $m) {
                $files[$m['file']['relativePath']] = $m['counts'];
            }
            ksort($files);
            $reasons = array_column($res['notices'], 'reason');
            sort($reasons);

            return ['files' => $files, 'notices' => $reasons, 'index' => $res['summary']['index']];
        };
        $GLOBALS['dir'] = $dir;
        $queries = [
            ['q' => 'contrat préavis'],
            ['q' => 'résiliation reconduit', 'mode' => 'any'],
            ['q' => 'préavis de 60 jours', 'mode' => 'phrase'],
            ['q' => 'CONTRAT', 'caseSensitive' => '1'],
            ['q' => 'preavis', 'ignoreAccents' => '0'],
            ['q' => 'contrat', 'wholeWord' => '1'],
            ['q' => '[A-Z]{2}\d{6}', 'mode' => 'regex'],
            ['q' => 'de', 'mode' => 'any'],
            ['q' => 'contrat', 'extensions' => 'pdf,docx'],
            ['q' => 'nereide', 'target' => 'both'],
            ['q' => 'introuvablexyz'],
        ];
        $allSame = true;
        $skippedSome = false;
        foreach ($queries as $params) {
            $with = $fingerprint($idxEngine, $params);
            $without = $fingerprint($idxEngine, $params + ['index' => '0']);
            if ($with['files'] !== $without['files'] || $with['notices'] !== $without['notices']) {
                $allSame = false;
                echo '      écart pour ' . json_encode($params, JSON_UNESCAPED_UNICODE) . "\n";
            }
            $skippedSome = $skippedSome || (($with['index']['skippedFiles'] ?? 0) + ($with['index']['skippedArchives'] ?? 0)) > 0;
        }
        check('mêmes fichiers, compteurs et avertissements sur 11 requêtes', $allSame);
        check('fichiers et archives non relus grâce à l\'index', $skippedSome);
        $regex = $fingerprint($idxEngine, ['q' => '[A-Z]{2}\d{6}', 'mode' => 'regex']);
        check('regex : index non utilisable, parcours complet', $regex['index']['usable'] === false);

        file_put_contents($dir . '/notes/journal.log', "\nmise à jour : trident levé\n", FILE_APPEND);
        touch($dir . '/notes/journal.log', time() + 5);
        file_put_contents($dir . '/notes/nouveau.txt', 'le trident de Poséidon');
        $found = $fingerprint($idxEngine, ['q' => 'trident']);
        check('fichier modifié et fichier ajouté trouvés', isset($found['files']['notes/journal.log'], $found['files']['notes/nouveau.txt']));
        unlink($dir . '/notes/nouveau.txt');
        $again = $build();
        check('fichier supprimé retiré de l\'index', $again['removed'] >= 1 && $again['indexed'] <= 2);
        check('fichier supprimé introuvable', !isset($fingerprint($idxEngine, ['q' => 'trident'])['files']['notes/nouveau.txt']));
        Fixtures::rmdir($dir . '-index');
    }

    section('Comparaison (diff)');
    $ops = Diff::lines(['a', 'b', 'c', 'd'], ['a', 'B', 'c', 'd', 'e']);
    same('opérations', [[' ', 'a'], ['-', 'b'], ['+', 'B'], [' ', 'c'], [' ', 'd'], ['+', 'e']], $ops);
    same('en-tête unifié', '@@ -1,4 +1,5 @@', Diff::hunks($ops)[0]['header']);
    same('fichiers identiques', [], Diff::hunks(Diff::lines(['x'], ['x'])));
    $big = Diff::lines(array_map(static fn ($i) => "a{$i}", range(1, 3000)), array_map(static fn ($i) => "b{$i}", range(1, 3000)));
    check('diff très différent borné (repli grossier)', count($big) === 6000 && memory_get_peak_usage() < 512 * 1024 * 1024);

    section('Recherche avancée : proximité, flou, groupes nommés');
    $doc = new Poseidon\Extractor\Document([['l1', 'Le contrat est signé.'], ['l2', 'Bien plus loin, sans rapport, il faudra parler du préavis un jour.'], ['l3', 'La résiliation du contrat'], ['l4', 'impose un préavis de 30 jours.']]);
    $near = (new Poseidon\Search\Matcher(new Query('contrat préavis', Query::NEAR, distance: 4)))->match($doc);
    same('proximité : seules les occurrences groupées comptent (même sur deux lignes)', [1, 1], $near->counts);
    same('proximité : lignes-preuves l3 et l4', ['l3', 'l4'], array_column($near->hits, 'location'));
    $far = (new Poseidon\Search\Matcher(new Query('contrat préavis', Query::NEAR, distance: 1)))->match($doc);
    same('proximité trop courte : aucun résultat', [0, 0], $far->counts);
    $fuzzy = new Query('contart résilation', Query::ANY, fuzzy: true);
    same('flou : fautes de frappe tolérées', [2, 1], (new Poseidon\Search\Matcher($fuzzy))->match($doc)->counts);
    check('flou : mot trop différent refusé', (new Poseidon\Search\Matcher(new Query('carton', fuzzy: true)))->match($doc)->counts === [0]);
    $named = $extractor->run(new Query('(?<code>[A-Z]{2})(?<numero>\d{6})', Query::REGEX), SearchOptions::fromArray(['root' => $dir, 'extensions' => 'md']), new ExtractionSpec(ExtractionSpec::MATCHES, withSource: false, unique: true));
    same('groupes nommés → colonnes', ['Emplacement', 'Terme', 'Valeur', 'code', 'numero'], $named['columns']);
    same('valeurs des groupes nommés', ['NR', '204511'], array_slice($named['rows'][0] ?? [], 3));
    check('proximité dans les fichiers (« préavis de quatre-vingt-dix »)', $engine->collect(new Query('préavis quatre-vingt-dix', Query::NEAR, distance: 1), SearchOptions::fromArray(['root' => $dir, 'index' => '0']))['summary']['matched'] === 1);
    same('distance de frappe : inversion = 1 faute', 1, Query::typoDistance('contart', 'contrat'));

    section('Modèles d\'extraction et rapport');
    $tplConfig = $config->with(['templates_dir' => $dir . '-templates']);
    $store = new Poseidon\Export\TemplateStore($tplConfig);
    $store->save('Références dossiers', ['q' => '[A-Z]{2}\d{6}', 'mode' => 'regex', 'extensions' => 'md,log', 'extract' => 'matches', 'format' => 'xlsx', 'unique' => true, 'root' => '/ne/pas/garder']);
    $tpl = $store->get('Références dossiers');
    same('modèle relu (sans la racine)', ['q' => '[A-Z]{2}\d{6}', 'mode' => 'regex', 'extensions' => 'md,log', 'extract' => 'matches', 'format' => 'xlsx', 'unique' => true], Poseidon\Export\TemplateStore::flatten($tpl));
    check('modèle listé puis supprimé', count($store->all()) === 1 && $store->delete('Références dossiers') && $store->all() === []);
    check('modèle invalide refusé', (static function () use ($store): bool {
        try {
            $store->save('x', ['q' => '([', 'mode' => 'regex']);
        } catch (InvalidArgumentException) {
            return true;
        }

        return false;
    })());
    Fixtures::rmdir($dir . '-templates');
    $rq = new Query('contrat <script>');
    $html = Poseidon\Report\HtmlReport::render($rq, SearchOptions::fromArray(['root' => $dir]), $engine->collect(new Query('contrat'), SearchOptions::fromArray(['root' => $dir])));
    check('rapport HTML autonome (aucun script, contenu échappé, surlignage)', !str_contains($html, '<script') && str_contains($html, '&lt;script&gt;') && str_contains($html, '<mark') && str_contains($html, 'Rapport de recherche'));

    section('Comptes, audit et limitation des connexions');
    $authConfig = $config->with(['users_file' => $dir . '-auth/users.json', 'audit_file' => $dir . '-auth/audit.log', 'state_dir' => $dir . '-auth/state']);
    $users = new Poseidon\Auth\UserStore($authConfig);
    check('aucun compte au départ', !$users->enabled());
    $users->save('Lea', 'un-mot-de-passe-long', 'lecteur', [$dir . '/notes']);
    check('compte créé, mot de passe haché', $users->enabled() && !str_contains((string) file_get_contents($dir . '-auth/users.json'), 'un-mot-de-passe-long'));
    same('connexion valide (identifiant insensible à la casse)', ['login' => 'lea', 'role' => 'lecteur', 'roots' => [Path::normalize($dir . '/notes')]], $users->verify('LEA', 'un-mot-de-passe-long'));
    same('mauvais mot de passe refusé', null, $users->verify('lea', 'faux'));
    same('compte inconnu refusé', null, $users->verify('inconnu', 'un-mot-de-passe-long'));
    check('mot de passe trop court refusé', (static function () use ($users): bool {
        try {
            $users->save('bob', 'court');
        } catch (InvalidArgumentException) {
            return true;
        }

        return false;
    })());
    $audit = new Poseidon\Auth\AuditLog($authConfig);
    $audit->log('recherche', 'lea', ['racine' => '/data', 'filtre' => 'contrat']);
    $audit->log('téléchargement', 'admin', ['fichier' => '/data/a.pdf']);
    same('journal d\'audit (plus récent d\'abord, filtre par compte)', ['recherche'], array_column($audit->tail(10, 'lea'), 'action'));
    $throttle = new Poseidon\Auth\Throttle($authConfig);
    for ($i = 0; $i < 5; $i++) {
        $throttle->fail('10.0.0.1');
    }
    check('5 échecs bloquent l\'adresse, pas les autres', $throttle->blocked('10.0.0.1') && !$throttle->blocked('10.0.0.2'));
    $throttle->clear('10.0.0.1');
    check('succès : compteur remis à zéro', !$throttle->blocked('10.0.0.1'));
    check('compte supprimé', $users->delete('lea') && !$users->enabled());
    Fixtures::rmdir($dir . '-auth');

    section('Protections contre les fichiers piégés');
    $sheet = Fixtures::zip(['xl/workbook.xml' => '<workbook><sheets><sheet name="S" r:id="rId1"/></sheets></workbook>', 'xl/_rels/workbook.xml.rels' => '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>', 'xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>ok</t></is></c><c r="ZZZZ1" t="inlineStr"><is><t>loin</t></is></c></row></sheetData></worksheet>']);
    file_put_contents($dir . '/piege.xlsx', $sheet);
    $doc = (new Poseidon\Extractor\SpreadsheetExtractor())->extract(new Poseidon\Filesystem\Source($dir . '/piege.xlsx', [], $dir, strlen($sheet), time()));
    same('colonne hors limites Excel ignorée', [0 => 'ok'], $doc->tables[0]['rows'][1] ?? null);

    section('PDF sans pdftotext (lecteur PHP de secours)');
    $doc = (new Poseidon\Extractor\PdfExtractor($config))->withPhp(Fixtures::pdf(['Bonjour (Poseidon)', 'Ligne deux']));
    same('texte du PDF', ['Bonjour (Poseidon)', 'Ligne deux'], array_column($doc->lines, 1));

    section('Accès réseau sans authentification');
    $call = static function (Config $cfg, string $remote, string $host): string {
        $_SERVER['REMOTE_ADDR'] = $remote;
        $_SERVER['HTTP_HOST'] = $host;
        $_SERVER['HTTP_X_POSEIDON'] = '1';
        ob_start();
        set_error_handler(static fn (int $no, string $msg): bool => str_contains($msg, 'headers') || str_contains($msg, 'Session') || str_contains($msg, 'session'));
        (new Poseidon\Http\Kernel($cfg))->handle('GET', '/api/info');
        restore_error_handler();
        $out = (string) ob_get_clean();
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_HOST'], $_SERVER['HTTP_X_POSEIDON']);

        return $out;
    };
    $open = $config->with(['users_file' => $dir . '-none/users.json', 'password' => '', 'demo' => false]);
    check('poste local accepté', str_contains($call($open, '127.0.0.1', 'localhost:8080'), '"name":"Poseidon"'));
    check('poste du réseau refusé, même avec « Host: localhost »', str_contains($call($open, '192.168.1.20', 'localhost:8080'), 'pas encore protégé'));
    check('nom d\'hôte extérieur refusé (DNS rebinding)', str_contains($call($open, '127.0.0.1', 'evil.example:8080'), 'pas encore protégé'));
    check('avec mot de passe : poste du réseau servi', str_contains($call($open->with(['password' => 'secret-partage-long']), '192.168.1.20', 'poseidon.lan'), '"authRequired":true'));
    check('ouverture explicite sans mot de passe (POSEIDON_INSECURE_NO_AUTH)', str_contains($call($open->with(['insecure_no_auth' => true, 'allowed_hosts' => ['poste-42']]), '192.168.1.20', 'poste-42:8080'), '"name":"Poseidon"'));

    section('Comparaison entre deux recherches (instantanés)');
    $snapConfig = $config->with(['snapshots_dir' => $dir . '-snap']);
    $snaps = new Poseidon\History\SnapshotStore($snapConfig);
    $sq = new Query('contrat');
    $so = SearchOptions::fromArray(['root' => $dir, 'index' => '0']);
    $first = $engine->collect($sq, $so);
    $snaps->save('test', $sq, $so, $first['matches'], $first['summary']);
    same('une seule recherche : rien à comparer', null, $snaps->compare('test', $sq, $so));
    file_put_contents($dir . '/notes/ajout.txt', 'un contrat de plus');
    file_put_contents($dir . '/notes/journal.log', "contrat contrat contrat\n", FILE_APPEND);
    touch($dir . '/notes/journal.log', time() + 60);
    $second = $engine->collect($sq, $so);
    $snaps->save('test', $sq, $so, $second['matches'], $second['summary']);
    $cmp = $snaps->compare('test', $sq, $so);
    same('fichier apparu', ['notes/ajout.txt'], array_column($cmp['appeared'] ?? [], 'path'));
    check('fichier modifié avec compteur en hausse', in_array('notes/journal.log', array_column($cmp['changed'] ?? [], 'path'), true) && ($cmp['changed'][0]['after'] ?? 0) > ($cmp['changed'][0]['before'] ?? 0));
    same('instantanés séparés par compte', null, $snaps->compare('autre', $sq, $so));
    unlink($dir . '/notes/ajout.txt');
    Fixtures::rmdir($dir . '-snap');

    section('Historique Git');
    if (Poseidon\Git\GitHistory::binary() !== null) {
        $repo = $dir . '-git';
        @mkdir($repo, 0775, true);
        $git = static fn (string ...$a) => Poseidon\Support\Process::run([Poseidon\Git\GitHistory::binary(), '-C', $repo, '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', '-c', 'commit.gpgsign=false', ...$a]);
        $git('init', '-q');
        file_put_contents($repo . '/contrat.docx', Fixtures::docx(['Article 1', 'Préavis de 30 jours.']));
        $git('add', '.'); $git('commit', '-q', '-m', 'Version initiale');
        file_put_contents($repo . '/contrat.docx', Fixtures::docx(['Article 1', 'Préavis de 90 jours.', 'Clause kraken.']));
        $git('add', '.'); $git('commit', '-q', '-m', 'Préavis allongé');
        $log = Poseidon\Git\GitHistory::log($repo, 'contrat.docx');
        same('historique d\'un fichier', ['Préavis allongé', 'Version initiale'], array_column($log, 'subject'));
        $hist = (new Poseidon\Git\HistorySearch($engine->extractors()))->search($repo, '', new Query('kraken'));
        same('Poseidon trouve le commit qui a ajouté le terme dans le DOCX', ['Préavis allongé'], array_column($hist, 'subject'));
        same('compteurs avant / après', ['path' => 'contrat.docx', 'before' => 0, 'after' => 1], $hist[0]['files'][0] ?? null);
        file_put_contents($repo . '/notes.md', "kraken aperçu\n");
        $git('add', '.'); $git('commit', '-q', '-m', 'Note texte');
        check('fichier texte trouvé par git log -S', in_array('Note texte', array_column((new Poseidon\Git\HistorySearch($engine->extractors()))->search($repo, '', new Query('kraken')), 'subject'), true));
        $before = Poseidon\Git\GitHistory::show($repo, $log[1]['hash'], 'contrat.docx', 10_000_000);
        check('version passée relue (DOCX valide)', str_starts_with($before, 'PK'));
        check('identifiant de commit invalide refusé', (static function (): bool {
            try {
                Poseidon\Git\GitHistory::assertRevision('HEAD; rm -rf /');
            } catch (InvalidArgumentException) {
                return true;
            }

            return false;
        })());
        Fixtures::rmdir($repo);
    } else {
        echo "  (git introuvable : section ignorée)\n";
    }

    section('Configuration');
    $withRoots = Config::load()->with(['allowed_roots' => ['/a', '/b']]);
    same('une liste vide remplace une liste', [], $withRoots->with(['allowed_roots' => []])->get('allowed_roots'));
    same('une liste remplace une liste', ['/c'], $withRoots->with(['allowed_roots' => ['/c']])->get('allowed_roots'));
    same('un sous-tableau se fusionne', [5, 'fra+eng'], [Config::load()->with(['ocr' => ['max_pages' => 5]])->get('ocr.max_pages'), Config::load()->with(['ocr' => ['max_pages' => 5]])->get('ocr.languages')]);

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
