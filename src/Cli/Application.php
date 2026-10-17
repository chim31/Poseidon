<?php

declare(strict_types=1);

namespace Poseidon\Cli;

use Poseidon\Config;
use Poseidon\Export\DataExtractor;
use Poseidon\Export\ExtractionSpec;
use Poseidon\Export\Writer;
use Poseidon\Filesystem\FileLocator;
use Poseidon\Filesystem\Scanner;
use Poseidon\Filesystem\Source;
use Poseidon\Filesystem\UnreadableFileException;
use Poseidon\Search\Diff;
use Poseidon\Search\Query;
use Poseidon\Search\SearchEngine;
use Poseidon\Search\SearchOptions;
use Poseidon\Support\Path;

/**
 * Interface en ligne de commande. Sortie colorée si le terminal le permet.
 */
final class Application
{
    private bool $color;

    public function __construct(private Config $config)
    {
        $this->color = function_exists('stream_isatty') && @stream_isatty(STDOUT) && getenv('NO_COLOR') === false;
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        [$command, $args, $opts] = self::parse(array_slice($argv, 1));

        try {
            return match ($command) {
                'search', 's' => $this->search($args, $opts),
                'extract', 'x' => $this->extract($args, $opts),
                'diff', 'd' => $this->diff($args),
                'cat' => $this->cat($args),
                'serve' => $this->serve($opts),
                'info' => $this->info(),
                'demo' => $this->demo($args),
                'index', 'i' => $this->index($args, $opts),
                'report', 'r' => $this->report($args, $opts),
                'templates', 't' => $this->templates($args, $opts),
                'user', 'users' => $this->user($args, $opts),
                'audit' => $this->auditLog($opts),
                'help', '', '--help', '-h' => $this->help(),
                default => throw new \InvalidArgumentException("Commande inconnue : {$command}. Tape « poseidon help »."),
            };
        } catch (\Throwable $e) {
            fwrite(STDERR, $this->paint('31', 'Erreur : ') . $e->getMessage() . PHP_EOL);

            return 2;
        }
    }

    /** @param list<string> $args @param array<string, string|bool> $opts */
    private function search(array $args, array $opts): int
    {
        if (count($args) < 2) {
            throw new \InvalidArgumentException('Usage : poseidon search <racine> <filtre> [--mode=all|any|phrase|regex] [--ext=pdf,docx] [--json]');
        }
        $engine = new SearchEngine($this->config);
        $query = $this->query($args[1], $opts);
        $options = $this->options($args[0], $opts);
        $json = isset($opts['json']);
        $quiet = isset($opts['l']) || isset($opts['files-only']);
        $matches = [];

        foreach ($engine->search($query, $options) as $event) {
            switch ($event['type']) {
                case 'match':
                    if ($json) {
                        $matches[] = $event;
                        break;
                    }
                    $file = $event['file'];
                    $counts = implode(' ', array_map(fn (string $t, int $c): string => $this->paint('33', $t) . '=' . $c, $query->terms, $event['counts']));
                    echo $this->paint('1;36', $file['relativePath']) . '  ' . $this->paint('2', '(' . $event['total'] . ')') . '  ' . $counts . PHP_EOL;
                    if (!$quiet) {
                        foreach ($event['hits'] as $hit) {
                            echo '   ' . $this->paint('32', str_pad($hit['location'], 14)) . ' ' . $this->highlight($hit['text'], $hit['spans']) . PHP_EOL;
                        }
                    }
                    break;
                case 'notice':
                    if (!$json && !isset($opts['no-warnings'])) {
                        fwrite(STDERR, $this->paint('35', '⚠ ' . $event['reason']) . ' ' . $event['path'] . ' — ' . $event['message'] . PHP_EOL);
                    } elseif ($json) {
                        $matches[] = $event;
                    }
                    break;
                case 'done':
                    $s = $event['summary'];
                    if ($json) {
                        echo json_encode(['results' => $matches, 'summary' => $s], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
                    } else {
                        echo PHP_EOL . $this->paint('1', sprintf(
                            '%d fichier(s) trouvé(s) sur %d analysé(s) · %d occurrence(s) · %d dans des archives · %d avertissement(s) · %s ms',
                            $s['matched'], $s['scanned'], $s['occurrences'], $s['inArchives'], $s['notices'], $s['durationMs']
                        )) . ($s['index'] !== null ? $this->paint('2', sprintf(' · index : %d fichiers et %d archives non relus', $s['index']['skippedFiles'], $s['index']['skippedArchives'])) : '') . PHP_EOL;
                    }

                    return $s['matched'] > 0 ? 0 : 1;
            }
        }

        return 1;
    }

    /** @param list<string> $args @param array<string, string|bool> $opts */
    private function extract(array $args, array $opts): int
    {
        $templates = new \Poseidon\Export\TemplateStore($this->config);
        if (isset($opts['template'])) {
            $template = $templates->get((string) $opts['template']) ?? throw new \InvalidArgumentException('Modèle introuvable : ' . $opts['template'] . '. Liste : poseidon templates');
            $saved = \Poseidon\Export\TemplateStore::flatten($template);
            // Les options de la ligne de commande priment sur le modèle.
            $opts += array_filter([
                'mode' => $saved['mode'] ?? null, 'ext' => $saved['extensions'] ?? null, 'extract' => $saved['extract'] ?? null,
                'format' => $saved['format'] ?? null, 'columns' => $saved['columns'] ?? null, 'headerRow' => $saved['headerRow'] ?? null,
                'distance' => $saved['distance'] ?? null, 'target' => $saved['target'] ?? null, 'name' => $saved['name'] ?? null,
            ], static fn ($v): bool => $v !== null && $v !== '');
            foreach (['caseSensitive' => 'case', 'wholeWord' => 'word', 'fuzzy' => 'fuzzy', 'allRows' => 'allRows', 'unique' => 'unique'] as $key => $flag) {
                if (filter_var($saved[$key] ?? false, FILTER_VALIDATE_BOOL)) {
                    $opts[$flag] = true;
                }
            }
            if (isset($saved['ignoreAccents']) && !filter_var($saved['ignoreAccents'], FILTER_VALIDATE_BOOL)) {
                $opts['accents'] = true;
            }
            if (count($args) === 1) {
                $args[] = (string) ($saved['q'] ?? '');
            }
        }
        if (count($args) < 2) {
            throw new \InvalidArgumentException('Usage : poseidon extract <racine> <filtre> --out=resultat.csv [--extract=matches|lines|columns|files] [--columns=A,C] [--template=nom] [--save-template=nom]');
        }
        $engine = new SearchEngine($this->config);
        $out = (string) ($opts['out'] ?? '');
        $format = (string) ($opts['format'] ?? ($out !== '' ? Path::extension($out) : 'csv'));
        $spec = ExtractionSpec::fromArray(['extract' => $opts['extract'] ?? 'matches', 'format' => $format ?: 'csv'] + $opts);
        $query = $this->query($args[1], $opts);
        $extractor = new DataExtractor($engine, $engine->extractors(), $engine->locator());
        $data = $extractor->run($query, $this->options($args[0], $opts), $spec);
        if (isset($opts['save-template'])) {
            $templates->save((string) $opts['save-template'], $query->toArray() + [
                'extensions' => (string) ($opts['ext'] ?? ''), 'target' => (string) ($opts['target'] ?? 'content'),
                'extract' => $spec->mode, 'format' => $spec->format, 'columns' => implode(',', $spec->columns),
                'headerRow' => $spec->headerRow, 'allRows' => $spec->allRows, 'withSource' => $spec->withSource, 'unique' => $spec->unique,
            ]);
            fwrite(STDERR, 'Modèle enregistré : ' . $opts['save-template'] . PHP_EOL);
        }

        foreach ($data['notices'] as $n) {
            fwrite(STDERR, $this->paint('35', '⚠ ' . $n['reason']) . ' ' . $n['path'] . ' — ' . $n['message'] . PHP_EOL);
        }
        $content = Writer::write($spec->format, $data['columns'], $data['rows']);
        if ($out === '' || $out === '-') {
            echo $content;
        } else {
            file_put_contents($out, $content);
            fwrite(STDERR, sprintf("%d ligne(s) extraite(s) vers %s\n", count($data['rows']), $out));
        }

        return $data['rows'] === [] ? 1 : 0;
    }

    /** @param list<string> $args */
    private function diff(array $args): int
    {
        if (count($args) < 2) {
            throw new \InvalidArgumentException('Usage : poseidon diff <fichierA> <fichierB>');
        }
        $engine = new SearchEngine($this->config);
        $texts = [];
        foreach ([$args[0], $args[1]] as $file) {
            $texts[] = array_column($this->document($engine, $file)->lines, 1);
        }
        $hunks = Diff::hunks(Diff::lines($texts[0], $texts[1]));
        foreach (explode("\n", rtrim(Diff::unified(basename($args[0]), basename($args[1]), $hunks))) as $line) {
            echo match ($line[0] ?? '') {
                '+' => $this->paint('32', $line),
                '-' => $this->paint('31', $line),
                '@' => $this->paint('36', $line),
                default => $line,
            } . PHP_EOL;
        }

        return $hunks === [] ? 0 : 1;
    }

    /** @param list<string> $args */
    private function cat(array $args): int
    {
        if ($args === []) {
            throw new \InvalidArgumentException('Usage : poseidon cat <fichier>');
        }
        $engine = new SearchEngine($this->config);
        foreach ($this->document($engine, $args[0])->lines as [$location, $line]) {
            echo $this->paint('2', str_pad($location, 14)) . ' ' . $line . PHP_EOL;
        }

        return 0;
    }

    private function document(SearchEngine $engine, string $file): \Poseidon\Extractor\Document
    {
        $path = Path::normalize($file);
        if (!is_file(Path::toNative($path))) {
            throw new \InvalidArgumentException('Fichier introuvable : ' . $file);
        }
        $source = new Source($path, [], dirname($path), (int) filesize(Path::toNative($path)), (int) filemtime(Path::toNative($path)));
        try {
            return $engine->extractors()->extract($source) ?? throw new \InvalidArgumentException('Format non textuel : ' . $file);
        } catch (UnreadableFileException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /** @param array<string, string|bool> $opts */
    private function serve(array $opts): int
    {
        $host = (string) ($opts['host'] ?? '127.0.0.1');
        $wanted = (int) ($opts['port'] ?? 8080);
        $public = POSEIDON_ROOT . '/public';

        // Port déjà pris par une autre application ? On prend le suivant libre.
        $port = $wanted;
        while (!self::portFree($host, $port)) {
            if (++$port > $wanted + 20) {
                throw new \RuntimeException(sprintf('Aucun port libre entre %d et %d. Lance Poseidon avec un autre port : poseidon.bat 9000', $wanted, $wanted + 20));
            }
        }
        if ($port !== $wanted) {
            fwrite(STDERR, $this->paint('33', sprintf('Le port %d est déjà utilisé par une autre application : Poseidon utilise le port %d.', $wanted, $port)) . PHP_EOL);
        }

        $browse = $host === '0.0.0.0' ? '127.0.0.1' : $host;
        $url = "http://{$browse}:{$port}/";
        echo $this->paint('1;36', 'Poseidon') . " écoute sur {$url}  (Ctrl+C pour arrêter)" . PHP_EOL;
        flush();
        if ($host !== '127.0.0.1' && $host !== 'localhost' && (string) $this->config->get('password') === '' && !(new \Poseidon\Auth\UserStore($this->config))->enabled()) {
            fwrite(STDERR, $this->paint('33', 'Attention : interface ouverte sur le réseau sans mot de passe. Crée un compte (poseidon user add) ou définis POSEIDON_PASSWORD : d\'ici là, seule cette machine peut l\'utiliser.') . PHP_EOL);
        }
        if (DIRECTORY_SEPARATOR === '/') {
            // Plusieurs processus : l'aperçu reste réactif pendant qu'une recherche tourne.
            putenv('PHP_CLI_SERVER_WORKERS=' . max(2, (int) ($opts['workers'] ?? 4)));
        }

        $server = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', "{$host}:{$port}", '-t', $public, $public . '/index.php'], [STDIN, STDOUT, STDERR], $pipes);
        if (!is_resource($server)) {
            throw new \RuntimeException('Impossible de démarrer le serveur PHP.');
        }

        // Le navigateur ne s'ouvre qu'une fois Poseidon réellement en écoute sur ce port.
        if (isset($opts['open'])) {
            if (self::waitForPoseidon($browse, $port)) {
                self::openBrowser($url);
            } else {
                fwrite(STDERR, $this->paint('31', "Poseidon ne répond pas sur {$url} : vérifie les messages ci-dessus.") . PHP_EOL);
            }
        }

        return proc_close($server);
    }

    /** Vrai si rien n'écoute déjà sur ce port et qu'on peut s'y attacher. */
    private static function portFree(string $host, int $port): bool
    {
        $probe = $host === '0.0.0.0' ? '127.0.0.1' : $host;
        $client = @fsockopen($probe, $port, $errno, $errstr, 0.3);
        if ($client !== false) {
            fclose($client);

            return false; // une application répond déjà
        }
        $socket = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    /** Attend que /api/info réponde « Poseidon » (et pas une autre application). */
    private static function waitForPoseidon(string $host, int $port, float $timeout = 8.0): bool
    {
        $context = stream_context_create(['http' => ['header' => "X-Poseidon: 1\r\n", 'timeout' => 1, 'ignore_errors' => true]]);
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $body = @file_get_contents("http://{$host}:{$port}/api/info", false, $context);
            if (is_string($body) && str_contains($body, '"name":"Poseidon"')) {
                return true;
            }
            usleep(150_000);
        }

        return false;
    }

    private static function openBrowser(string $url): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start "" "' . $url . '"', 'r'));
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            exec('open ' . escapeshellarg($url) . ' >/dev/null 2>&1 &');
        } else {
            exec('(xdg-open ' . escapeshellarg($url) . ' || wslview ' . escapeshellarg($url) . ') >/dev/null 2>&1 &');
        }
    }

    /**
     * @param list<string>                $args
     * @param array<string, string|bool> $opts
     */
    private function report(array $args, array $opts): int
    {
        if (count($args) < 2) {
            throw new \InvalidArgumentException('Usage : poseidon report <racine> <filtre> --out=rapport.html');
        }
        $engine = new SearchEngine($this->config);
        $query = $this->query($args[1], $opts);
        $options = $this->options($args[0], $opts);
        $html = \Poseidon\Report\HtmlReport::render($query, $options, $engine->collect($query, $options));
        $out = (string) ($opts['out'] ?? 'rapport-poseidon.html');
        file_put_contents($out, $html);
        fwrite(STDERR, "Rapport écrit dans {$out} (ouvre-le dans un navigateur ; Ctrl+P pour un PDF)." . PHP_EOL);

        return 0;
    }

    /**
     * @param list<string>                $args
     * @param array<string, string|bool> $opts
     */
    private function user(array $args, array $opts): int
    {
        $store = new \Poseidon\Auth\UserStore($this->config);
        $action = $args[0] ?? 'list';
        $login = $args[1] ?? '';
        $roots = array_values(array_filter(explode(';', (string) ($opts['roots'] ?? ''))));
        switch ($action) {
            case 'list':
                $all = $store->all();
                if ($all === []) {
                    echo "Aucun compte : accès sans identifiant (ou mot de passe partagé POSEIDON_PASSWORD).\nCréer un compte : poseidon user add <identifiant> --role=admin\n";

                    return 0;
                }
                foreach ($all as $name => $u) {
                    printf("%-24s %-8s %s\n", $name, $u['role'], ($u['roots'] ?? []) === [] ? 'toutes les racines autorisées' : implode(' ; ', array_map([Path::class, 'display'], $u['roots'])));
                }

                return 0;
            case 'add':
            case 'update':
                if ($login === '') {
                    throw new \InvalidArgumentException('Usage : poseidon user add <identifiant> [--role=admin|lecteur] [--roots="C:\\Docs;D:\\Archives"] [--password=…]');
                }
                $existing = $store->get($login);
                $password = isset($opts['password']) ? (string) $opts['password'] : ($existing === null || $action === 'add' ? self::askPassword() : '');
                $store->save($login, $password, (string) ($opts['role'] ?? $existing['role'] ?? 'lecteur'), $roots !== [] || isset($opts['roots']) ? $roots : ($existing['roots'] ?? []));
                (new \Poseidon\Auth\AuditLog($this->config))->log('compte ' . ($existing === null ? 'créé' : 'modifié'), 'cli', ['compte' => strtolower($login)]);
                echo 'Compte ' . ($existing === null ? 'créé' : 'mis à jour') . ' : ' . strtolower($login) . PHP_EOL;

                return 0;
            case 'passwd':
                if ($store->get($login) === null) {
                    throw new \InvalidArgumentException('Compte introuvable : ' . $login);
                }
                $u = $store->get($login);
                $store->save($login, isset($opts['password']) ? (string) $opts['password'] : self::askPassword(), $u['role'], $u['roots']);
                echo 'Mot de passe changé.' . PHP_EOL;

                return 0;
            case 'delete':
                $done = $store->delete($login);
                (new \Poseidon\Auth\AuditLog($this->config))->log('compte supprimé', 'cli', ['compte' => strtolower($login)]);
                echo ($done ? 'Compte supprimé.' : 'Compte introuvable.') . PHP_EOL;

                return $done ? 0 : 1;
            default:
                throw new \InvalidArgumentException('Usage : poseidon user list|add|update|passwd|delete <identifiant>');
        }
    }

    /** Saisie du mot de passe sans écho quand le terminal le permet. */
    private static function askPassword(): string
    {
        fwrite(STDERR, 'Mot de passe (10 caractères minimum) : ');
        $silent = DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && @stream_isatty(STDIN);
        if ($silent) {
            @shell_exec('stty -echo');
        }
        $password = trim((string) fgets(STDIN));
        if ($silent) {
            @shell_exec('stty echo');
            fwrite(STDERR, PHP_EOL);
        }

        return $password;
    }

    /** @param array<string, string|bool> $opts */
    private function auditLog(array $opts): int
    {
        $entries = array_reverse((new \Poseidon\Auth\AuditLog($this->config))->tail((int) ($opts['tail'] ?? 50), isset($opts['user']) ? (string) $opts['user'] : null));
        foreach ($entries as $e) {
            $details = array_diff_key($e, array_flip(['time', 'user', 'ip', 'action']));
            printf("%s  %-14s %-15s %-18s %s\n", $e['time'], $e['user'], $e['ip'], $e['action'], implode(' · ', array_map(static fn ($k, $v) => "{$k}=" . (is_scalar($v) ? $v : json_encode($v)), array_keys($details), $details)));
        }
        if ($entries === []) {
            echo 'Journal vide.' . PHP_EOL;
        }

        return 0;
    }

    /**
     * @param list<string>                $args
     * @param array<string, string|bool> $opts
     */
    private function templates(array $args, array $opts): int
    {
        $store = new \Poseidon\Export\TemplateStore($this->config);
        if (isset($opts['delete'])) {
            echo ($store->delete((string) $opts['delete']) ? 'Modèle supprimé.' : 'Modèle introuvable.') . PHP_EOL;

            return 0;
        }
        $all = $store->all();
        if ($all === []) {
            echo "Aucun modèle. Enregistre-en un : poseidon extract <racine> <filtre> --out=x.csv --save-template=\"Mon modèle\"" . PHP_EOL;

            return 0;
        }
        foreach ($all as $t) {
            printf("%-28s %-12s %-8s %s%s\n", $t['name'], $t['extraction']['extract'] ?? 'matches', $t['extraction']['format'] ?? 'csv', $t['query']['q'] ?? '', ($t['description'] ?? '') !== '' ? '  — ' . $t['description'] : '');
        }

        return 0;
    }

    /**
     * @param list<string>                $args
     * @param array<string, string|bool> $opts
     */
    private function index(array $args, array $opts): int
    {
        if ($args === []) {
            throw new \InvalidArgumentException('Usage : poseidon index <racine> [--rebuild] [--status] [--delete]');
        }
        $engine = new SearchEngine($this->config);
        $root = $engine->guard()->resolveRoot($args[0]);
        if (!\Poseidon\Index\Index::available()) {
            throw new \RuntimeException('Index indisponible : active l\'extension PHP pdo_sqlite (SQLite 3.43+).');
        }
        if (isset($opts['delete'])) {
            echo (\Poseidon\Index\Index::delete($this->config, $root) ? 'Index supprimé.' : 'Aucun index pour cette racine.') . PHP_EOL;

            return 0;
        }
        if (isset($opts['status'])) {
            $index = \Poseidon\Index\Index::find($this->config, $root);
            if ($index === null) {
                echo 'Aucun index pour ' . Path::display($root) . PHP_EOL;

                return 1;
            }
            $st = $index->stats();
            printf("Index de %s : %d fichiers (%d avec texte, %d ignorés), %s, construit le %s\n", $st['root'], $st['files'], $st['withText'], $st['notices'], Scanner::humanSize($st['sizeBytes']), $st['builtAt'] ?? '—');

            return 0;
        }

        $indexer = new \Poseidon\Index\Indexer($this->config, $engine->extractors(), $engine->guard());
        foreach ($indexer->build($args[0], isset($opts['rebuild'])) as $event) {
            match ($event['type']) {
                'progress' => fwrite(STDERR, sprintf("\r%d fichiers parcourus, %d indexés…", $event['scanned'], $event['indexed'])),
                'notice' => isset($opts['no-warnings']) ? null : fwrite(STDERR, "\r" . $this->paint('35', '⚠ ' . $event['reason']) . ' ' . $event['path'] . ' — ' . $event['message'] . PHP_EOL),
                'done' => printf("\r%s : %d fichiers, %d (ré)indexés, %d inchangés, %d retirés, %d avertissements, %s ms\n", $event['summary']['root'], $event['summary']['files'], $event['summary']['indexed'], $event['summary']['unchanged'], $event['summary']['removed'], $event['summary']['notices'], $event['summary']['durationMs']),
                default => null,
            };
        }

        return 0;
    }

    /** @param list<string> $args */
    private function demo(array $args): int
    {
        $dir = $args[0] ?? POSEIDON_ROOT . '/samples/demo';
        \Poseidon\Demo\Samples::build($dir);
        echo 'Échantillons synthétiques créés dans ' . Path::display($dir) . PHP_EOL;

        return 0;
    }

    private function info(): int
    {
        $engine = new SearchEngine($this->config);
        $roots = $engine->guard()->allowedRoots();
        echo 'Poseidon ' . POSEIDON_VERSION . ' — PHP ' . PHP_VERSION . ' (' . PHP_OS_FAMILY . ')' . PHP_EOL;
        echo 'Moteur PDF       : ' . $engine->extractors()->pdfEngine() . PHP_EOL;
        echo 'OCR              : ' . ($engine->extractors()->ocrAvailable() ? 'Tesseract' : 'indisponible (installer tesseract-ocr)') . PHP_EOL;
        echo 'Archives 7z/RAR  : ' . (\Poseidon\Archive\Bsdtar::binary() ?? 'indisponible (installer bsdtar / libarchive)') . PHP_EOL;
        echo 'Index            : ' . (\Poseidon\Index\Index::available() ? 'SQLite FTS5' : 'indisponible (activer pdo_sqlite)') . PHP_EOL;
        echo 'Racines permises : ' . ($roots === [] ? 'toutes (aucune restriction)' : implode(', ', array_map([Path::class, 'display'], $roots))) . PHP_EOL;
        echo 'Formats          : ' . implode(', ', $engine->extractors()->supportedExtensions()) . PHP_EOL;
        echo 'Conteneurs       : zip, jar, eml, msg, gz, tar, tar.gz' . (\Poseidon\Archive\Bsdtar::binary() !== null ? ', 7z, rar, iso, cab, tar.xz, tar.bz2' : '') . PHP_EOL;

        return 0;
    }

    private function help(): int
    {
        echo <<<TXT
        Poseidon — recherche et extraction dans les fichiers (ls -R + grep -Ri + cat, en mieux)

        Commandes
          search  <racine> <filtre>        Rechercher (alias : s)
          extract <racine> <filtre>        Extraire vers un nouveau document (alias : x)
          diff    <fichierA> <fichierB>    Comparer deux fichiers façon git diff (alias : d)
          cat     <fichier>                Afficher le texte extrait d'un fichier
          serve   [--host=] [--port=] [--open]  Lancer l'interface web (port suivant si occupé)
          info                             Configuration et formats pris en charge
          demo    [dossier]                Générer les échantillons synthétiques de démonstration
          index   <racine> [--rebuild|--status|--delete]  Index plein texte (recherches instantanées)
          report  <racine> <filtre> --out=rapport.html    Rapport HTML (imprimable en PDF)
          templates [--delete=nom]         Modèles d'extraction enregistrés
          user    list|add|update|passwd|delete <identifiant> [--role=admin|lecteur] [--roots="…;…"]
          audit   [--tail=50] [--user=nom]  Journal d'audit (qui a cherché, ouvert, extrait quoi)

        Options de recherche
          --mode=all|any|phrase|regex|near Tous les mots (défaut), un des mots, phrase exacte, regex, proximité
          --distance=5                     Proximité : nombre maximal de mots entre les termes
          --fuzzy                          Tolérer les fautes de frappe (mots proches)
          --case                           Respecter la casse
          --word                           Mot entier uniquement
          --accents                        Respecter les accents (ignorés par défaut)
          --ext=pdf,docx,xlsx              Limiter aux extensions
          --name="rapport_*"               Filtre sur le nom du fichier
          --target=content|name|both       Chercher dans le contenu, le nom, ou les deux
          --no-zip                         Ne pas ouvrir les archives
          -l, --files-only                 N'afficher que les fichiers
          --json                           Sortie JSON
          --no-warnings                    Masquer les avertissements (fichiers protégés...)
          --no-index                       Ignorer l'index et tout relire

        Options d'extraction
          --out=fichier.(csv|json|txt|md|xlsx)
          --extract=matches|lines|columns|files
          --columns=A,C,Montant            Colonnes à extraire (mode columns)
          --header-row=1                   Ligne d'en-tête (0 = aucune)
          --all-rows                       Toutes les lignes des tableaux, pas seulement celles qui correspondent
          --unique                         Dédoublonner
          --template=nom                   Rejouer un modèle (filtre, options et extraction)
          --save-template=nom              Enregistrer cette extraction comme modèle

        Exemples
          poseidon search ~/Documents "contrat résiliation" --ext=pdf,docx
          poseidon search "\\\\srv\\juridique" "\"préavis de 3 mois\"" --mode=any
          poseidon extract ./exports "[A-Z]{2}\\d{6}" --mode=regex --out=references.xlsx
          poseidon extract ./finance "résilié" --extract=columns --columns=Client,Montant --out=resilies.csv
          poseidon extract ./factures "(?<montant>\\d+,\\d{2}) ?€" --mode=regex --out=montants.xlsx --save-template=Montants
          poseidon extract ./factures-novembre --template=Montants --out=novembre.xlsx
          poseidon search ./juridique "contrat préavis" --mode=near --distance=8
          poseidon report ./juridique "résiliation" --out=rapport.html

        TXT;

        return 0;
    }

    /** @param array<string, string|bool> $opts */
    private function query(string $filter, array $opts): Query
    {
        return new Query(
            $filter,
            (string) ($opts['mode'] ?? Query::ALL),
            isset($opts['case']),
            isset($opts['word']),
            !isset($opts['accents']),
            (int) ($opts['distance'] ?? 5),
            isset($opts['fuzzy']),
        );
    }

    /** @param array<string, string|bool> $opts */
    private function options(string $root, array $opts): SearchOptions
    {
        return SearchOptions::fromArray([
            'root' => $root,
            'extensions' => (string) ($opts['ext'] ?? ''),
            'archives' => !isset($opts['no-zip']),
            'target' => (string) ($opts['target'] ?? 'content'),
            'name' => (string) ($opts['name'] ?? ''),
            'limit' => (int) ($opts['limit'] ?? 50000),
            'index' => !isset($opts['no-index']),
        ]);
    }

    /**
     * @param list<string> $argv
     * @return array{0: string, 1: list<string>, 2: array<string, string|bool>}
     */
    private static function parse(array $argv): array
    {
        $command = '';
        $args = [];
        $opts = [];
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--') && strlen($arg) > 2) {
                [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
                $opts[$key] = $value;
                // Alias en notation tiret pour ExtractionSpec.
                $opts[lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $key))))] = $value;
            } elseif ($arg !== '-' && str_starts_with($arg, '-') && strlen($arg) === 2) {
                $opts[substr($arg, 1)] = true;
            } elseif ($command === '') {
                $command = $arg;
            } else {
                $args[] = $arg;
            }
        }

        return [$command, $args, $opts];
    }

    /** @param list<array{0:int,1:int,2:int}> $spans */
    private function highlight(string $text, array $spans): string
    {
        if (!$this->color) {
            return $text;
        }
        $out = '';
        $pos = 0;
        foreach ($spans as [$start, $length]) {
            if ($start < $pos) {
                continue;
            }
            $out .= mb_substr($text, $pos, $start - $pos) . "\033[1;30;43m" . mb_substr($text, $start, $length) . "\033[0m";
            $pos = $start + $length;
        }

        return $out . mb_substr($text, $pos);
    }

    private function paint(string $code, string $text): string
    {
        return $this->color ? "\033[{$code}m{$text}\033[0m" : $text;
    }
}
