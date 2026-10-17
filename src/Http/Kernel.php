<?php

declare(strict_types=1);

namespace Poseidon\Http;

use Poseidon\Config;
use Poseidon\Export\DataExtractor;
use Poseidon\Export\ExtractionSpec;
use Poseidon\Export\Writer;
use Poseidon\Filesystem\FileLocator;
use Poseidon\Filesystem\UnreadableFileException;
use Poseidon\Search\Diff;
use Poseidon\Search\Matcher;
use Poseidon\Search\Query;
use Poseidon\Search\SearchEngine;
use Poseidon\Search\SearchOptions;
use Poseidon\Support\Path;

/**
 * Point d'entrée HTTP : routes de l'API JSON et pages de l'interface.
 *
 *   GET  /api/info                     Version, formats, racines, authentification
 *   GET  /api/browse?path=             Sous-dossiers (sélecteur de racine)
 *   GET  /api/search?root=&q=&...      Recherche en flux NDJSON
 *   GET  /api/preview?id=&q=&...       Texte extrait + surlignage (+ tableaux)
 *   GET  /api/file?id=&inline=1        Fichier d'origine (téléchargement)
 *   GET  /api/text?id=                 Texte extrait en .txt
 *   GET  /api/diff?a=&b=               Comparaison façon git diff
 *   POST /api/extract                  Extraction vers CSV/JSON/TXT/MD/XLSX
 *   POST /api/login, /api/logout       Authentification (si POSEIDON_PASSWORD)
 */
final class Kernel
{
    private SearchEngine $engine;

    private FileLocator $locator;

    public function __construct(private Config $config)
    {
        $this->engine = new SearchEngine($config);
        $this->locator = $this->engine->locator();
    }

    public function handle(string $method, string $uri): void
    {
        $path = '/' . trim((string) parse_url($uri, PHP_URL_PATH), '/');
        $this->securityHeaders();

        // Anti « DNS rebinding » : sans mot de passe, seuls les hôtes locaux ou déclarés sont servis.
        if (!$this->hostAllowed((string) ($_SERVER['HTTP_HOST'] ?? ''))) {
            http_response_code(421);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Hôte non autorisé. Accède à Poseidon via 127.0.0.1 ou localhost, ou déclare cet hôte dans POSEIDON_HOSTS (ou protège l'instance avec POSEIDON_PASSWORD).";

            return;
        }

        if ($path === '/projet' || str_starts_with($path, '/projet/')) {
            $this->projectDocs(substr($path, strlen('/projet')));

            return;
        }
        if (!str_starts_with($path, '/api/')) {
            $this->page();

            return;
        }

        $this->startSession();
        try {
            // En-tête obligatoire sur toute l'API (sauf liens de téléchargement, protégés par identifiant signé) :
            // un site tiers ne peut pas le poser sans requête préalable CORS, refusée.
            $direct = $method === 'GET' && in_array($path, ['/api/file', '/api/text', '/api/report'], true);
            if (!$direct && ($_SERVER['HTTP_X_POSEIDON'] ?? '') !== '1') {
                throw new HttpError(403, 'Requête refusée (en-tête de sécurité manquant).');
            }
            if ($path === '/api/login' && $method === 'POST') {
                $this->login();

                return;
            }
            if ($path === '/api/info') {
                $this->json($this->info());

                return;
            }
            if (!$this->authenticated()) {
                throw new HttpError(401, 'Authentification requise.');
            }
            session_write_close(); // libère le verrou de session : les requêtes suivantes ne sont pas bloquées

            match ([$method, $path]) {
                ['POST', '/api/logout'] => $this->logout(),
                ['GET', '/api/browse'] => $this->browse(),
                ['GET', '/api/search'] => $this->search(),
                ['GET', '/api/preview'] => $this->preview(),
                ['GET', '/api/file'] => $this->file(),
                ['GET', '/api/text'] => $this->text(),
                ['GET', '/api/diff'] => $this->diff(),
                ['GET', '/api/index'] => $this->indexStatus(),
                ['POST', '/api/index'] => $this->indexBuild(),
                ['POST', '/api/index/delete'] => $this->indexDelete(),
                ['GET', '/api/templates'] => $this->json(['templates' => (new \Poseidon\Export\TemplateStore($this->config))->all()]),
                ['POST', '/api/templates'] => $this->templateSave(),
                ['POST', '/api/templates/delete'] => $this->json(['deleted' => (new \Poseidon\Export\TemplateStore($this->config))->delete((string) ($this->input()['name'] ?? ''))]),
                ['GET', '/api/report'] => $this->report(),
                ['POST', '/api/extract'] => $this->extract(),
                default => throw new HttpError(404, 'Route inconnue : ' . $path),
            };
        } catch (HttpError $e) {
            $this->json(['error' => $e->getMessage()], $e->status);
        } catch (UnreadableFileException $e) {
            $this->json(['error' => $e->getMessage(), 'reason' => $e->reason], 422);
        } catch (\InvalidArgumentException | \DomainException $e) {
            $this->json(['error' => $e->getMessage()], $e instanceof \DomainException ? 403 : 400);
        } catch (\Throwable $e) {
            error_log('[poseidon] ' . $e);
            $this->json(['error' => 'Erreur interne : ' . $e->getMessage()], 500);
        }
    }

    /** @return array<string, mixed> */
    private function info(): array
    {
        $demo = POSEIDON_ROOT . '/samples/demo';
        if (!is_dir($demo) && $this->authenticated()) {
            \Poseidon\Demo\Samples::build($demo);
        }

        return [
            'name' => 'Poseidon',
            'version' => POSEIDON_VERSION,
            'php' => PHP_VERSION,
            'os' => PHP_OS_FAMILY,
            'pdfEngine' => $this->engine->extractors()->pdfEngine(),
            'indexAvailable' => \Poseidon\Index\Index::available(),
            'ocr' => $this->engine->extractors()->ocrAvailable(),
            'archives7z' => \Poseidon\Archive\Bsdtar::binary() !== null,
            'formats' => [...$this->engine->extractors()->supportedExtensions(), 'zip'],
            'allowedRoots' => array_map([Path::class, 'display'], $this->engine->guard()->allowedRoots()),
            'authRequired' => $this->passwordRequired(),
            'authenticated' => $this->authenticated(),
            'home' => Path::display((string) (getenv('HOME') ?: getenv('USERPROFILE') ?: POSEIDON_ROOT)),
            'demo' => Path::display(POSEIDON_ROOT . '/samples/demo'),
        ];
    }

    private function browse(): void
    {
        $input = trim((string) ($_GET['path'] ?? ''));
        $guard = $this->engine->guard();
        if ($input === '') {
            $roots = $guard->allowedRoots();
            if ($roots === []) {
                $roots = DIRECTORY_SEPARATOR === '\\'
                    ? array_values(array_filter(array_map(static fn (string $l): string => $l . ':/', range('C', 'Z')), static fn (string $d): bool => is_dir($d)))
                    : ['/'];
            }
            $this->json(['path' => '', 'parent' => null, 'dirs' => array_map(static fn (string $r): array => ['name' => Path::display($r), 'path' => Path::display($r)], $roots)]);

            return;
        }

        $root = $guard->resolveRoot($input);
        $dirs = [];
        foreach (@scandir(Path::toNative($root)) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            $full = $root === '/' ? '/' . $entry : $root . '/' . $entry;
            if (is_dir(Path::toNative($full))) {
                $dirs[] = ['name' => $entry, 'path' => Path::display($full)];
            }
            if (count($dirs) >= 500) {
                break;
            }
        }
        $parent = dirname($root);
        $canGoUp = $parent !== $root;
        if ($canGoUp && $guard->isRestricted()) {
            try {
                $guard->assertAllowed($parent);
            } catch (\DomainException) {
                $canGoUp = false;
            }
        }
        $this->json(['path' => Path::display($root), 'parent' => $canGoUp ? Path::display($parent) : null, 'dirs' => $dirs]);
    }

    private function search(): void
    {
        $query = Query::fromArray($_GET);
        $options = SearchOptions::fromArray($_GET);
        $events = $this->engine->search($query, $options);
        // Valide la racine avant d'ouvrir le flux : une erreur reste une réponse JSON propre.
        $first = $events->current();

        set_time_limit(0);
        ignore_user_abort(false);
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $this->emit($first);
        $events->next();
        while ($events->valid()) {
            $this->emit($events->current());
            if (connection_aborted()) {
                return; // l'utilisateur a annulé : on arrête le parcours
            }
            $events->next();
        }
    }

    /** @param array<string, mixed> $event */
    private function emit(array $event): void
    {
        echo json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        flush();
    }

    private function indexStatus(): void
    {
        $root = $this->engine->guard()->resolveRoot($this->required('root'));
        $index = \Poseidon\Index\Index::find($this->config, $root);
        $this->json([
            'available' => \Poseidon\Index\Index::available(),
            'exists' => $index !== null,
            'stats' => $index?->stats(),
        ]);
    }

    private function indexBuild(): void
    {
        $input = $this->input();
        $indexer = new \Poseidon\Index\Indexer($this->config, $this->engine->extractors(), $this->engine->guard());
        $events = $indexer->build((string) ($input['root'] ?? ''), filter_var($input['rebuild'] ?? false, FILTER_VALIDATE_BOOL));
        $first = $events->current();

        set_time_limit(0);
        ignore_user_abort(false);
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        $this->emit($first);
        $events->next();
        while ($events->valid()) {
            $event = $events->current();
            if ($event['type'] !== 'notice') {
                $this->emit($event);
            }
            if (connection_aborted()) {
                return;
            }
            $events->next();
        }
    }

    private function templateSave(): void
    {
        $input = $this->input();
        $template = (new \Poseidon\Export\TemplateStore($this->config))->save((string) ($input['templateName'] ?? ''), $input, (string) ($input['templateDescription'] ?? ''));
        $this->json(['template' => $template]);
    }

    private function report(): void
    {
        set_time_limit(0);
        $query = Query::fromArray($_GET);
        $options = SearchOptions::fromArray($_GET);
        $html = \Poseidon\Report\HtmlReport::render($query, $options, $this->engine->collect($query, $options));
        // Rapport autonome : styles en ligne autorisés, aucun script.
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'");
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: ' . (($_GET['inline'] ?? '') === '1' ? 'inline' : 'attachment') . '; ' . self::filenameHeader('rapport-poseidon-' . date('Ymd-His') . '.html'));
        echo $html;
    }

    private function indexDelete(): void
    {
        $root = $this->engine->guard()->resolveRoot((string) ($this->input()['root'] ?? ''));
        $this->json(['deleted' => \Poseidon\Index\Index::delete($this->config, $root)]);
    }

    private function preview(): void
    {
        $source = $this->locator->locate($this->required('id'));
        $document = $this->engine->extractors()->extract($source);
        if ($document === null) {
            $this->json(['file' => $source->toArray(), 'binary' => true, 'lines' => [], 'tables' => []]);

            return;
        }

        $spansByLine = [];
        $counts = [];
        if (trim((string) ($_GET['q'] ?? '')) !== '') {
            $matcher = new Matcher(Query::fromArray($_GET), PHP_INT_MAX);
            $counts = $matcher->match($document)->counts;
            foreach ($matcher->spans($document) as $i => $spans) {
                $spansByLine[$i] = array_map(static fn (array $sp): array => [$sp[0], $sp[1], $sp[2]], $spans);
            }
        }

        $limit = 5000;
        $lines = [];
        foreach (array_slice($document->lines, 0, $limit, true) as $i => [$location, $line]) {
            $lines[] = ['location' => $location, 'text' => mb_substr($line, 0, 2000), 'spans' => $spansByLine[$i] ?? []];
        }

        $tables = [];
        foreach ($document->tables as $table) {
            $rows = [];
            $maxCol = 0;
            foreach (array_slice($table['rows'], 0, 200, true) as $n => $cells) {
                $rows[] = ['n' => $n, 'cells' => $cells];
                $maxCol = max($maxCol, $cells === [] ? 0 : max(array_keys($cells)) + 1);
            }
            $tables[] = ['name' => $table['name'], 'columns' => min($maxCol, 60), 'rows' => $rows, 'totalRows' => count($table['rows'])];
        }

        $this->json([
            'file' => $source->toArray(),
            'counts' => $counts,
            'lines' => $lines,
            'totalLines' => count($document->lines),
            'truncated' => count($document->lines) > $limit,
            'tables' => $tables,
            'warnings' => $document->warnings,
        ]);
    }

    private function file(): void
    {
        $source = $this->locator->locate($this->required('id'));
        $data = $source->bytes();
        $ext = $source->extension();
        $inline = ($_GET['inline'] ?? '') === '1' && in_array($ext, ['pdf', 'txt', 'md', 'log', 'csv', 'png', 'jpg', 'jpeg'], true);

        header('Content-Type: ' . FileLocator::mimeType($ext));
        header('Content-Length: ' . strlen($data));
        header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; ' . self::filenameHeader($source->name()));
        echo $data;
    }

    private function text(): void
    {
        $source = $this->locator->locate($this->required('id'));
        $document = $this->engine->extractors()->extract($source) ?? throw new HttpError(415, 'Ce fichier ne contient pas de texte exploitable.');
        $out = '';
        foreach ($document->lines as [$location, $line]) {
            $out .= $line . "\n";
        }
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; ' . self::filenameHeader(pathinfo($source->name(), PATHINFO_FILENAME) . '.txt'));
        echo $out;
    }

    private function diff(): void
    {
        $a = $this->locator->locate($this->required('a'));
        $b = $this->locator->locate($this->required('b'));
        $docA = $this->engine->extractors()->extract($a) ?? throw new HttpError(415, $a->name() . ' ne contient pas de texte.');
        $docB = $this->engine->extractors()->extract($b) ?? throw new HttpError(415, $b->name() . ' ne contient pas de texte.');
        $ops = Diff::lines(array_column($docA->lines, 1), array_column($docB->lines, 1));
        $hunks = Diff::hunks($ops, max(0, min(20, (int) ($_GET['context'] ?? 3))));
        $added = count(array_filter($ops, static fn (array $o): bool => $o[0] === '+'));
        $removed = count(array_filter($ops, static fn (array $o): bool => $o[0] === '-'));

        $this->json([
            'a' => $a->toArray(),
            'b' => $b->toArray(),
            'added' => $added,
            'removed' => $removed,
            'identical' => $hunks === [],
            'hunks' => array_slice($hunks, 0, 500),
            'unified' => Diff::unified($a->name(), $b->name(), $hunks),
        ]);
    }

    private function extract(): void
    {
        $input = $this->input();
        set_time_limit(0);
        $spec = ExtractionSpec::fromArray($input);
        $extractor = new DataExtractor($this->engine, $this->engine->extractors(), $this->locator);
        $data = $extractor->run(Query::fromArray($input), SearchOptions::fromArray($input + ['limit' => 50000]), $spec);
        $content = Writer::write($spec->format, $data['columns'], $data['rows']);

        header('Content-Type: ' . Writer::mime($spec->format));
        header('Content-Length: ' . strlen($content));
        header('X-Poseidon-Rows: ' . count($data['rows']));
        header('X-Poseidon-Notices: ' . count($data['notices']));
        header('Content-Disposition: attachment; ' . self::filenameHeader('poseidon-extraction-' . date('Ymd-His') . '.' . $spec->format));
        echo $content;
    }

    private function login(): void
    {
        $input = $this->input();
        $expected = (string) $this->config->get('password');
        if ($expected === '') {
            $this->json(['authenticated' => true]);

            return;
        }
        usleep(random_int(200_000, 400_000)); // ralentit le forçage
        if (!hash_equals($expected, (string) ($input['password'] ?? ''))) {
            throw new HttpError(401, 'Mot de passe incorrect.');
        }
        session_regenerate_id(true);
        $_SESSION['poseidon_auth'] = true;
        $this->json(['authenticated' => true]);
    }

    private function logout(): void
    {
        @session_start();
        $_SESSION = [];
        session_destroy();
        $this->json(['authenticated' => false]);
    }

    private function page(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        readfile(POSEIDON_ROOT . '/public/app.html');
    }

    /** Cahier des charges et roadmap (docs/web), servis en lecture seule. */
    private function projectDocs(string $relative): void
    {
        $relative = ltrim($relative, '/');
        if ($relative === '') {
            header('Location: /projet/cahier-des-charges.html', true, 302);

            return;
        }
        $base = (string) realpath(POSEIDON_ROOT . '/docs/web');
        $file = realpath($base . '/' . $relative);
        $types = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css', 'js' => 'text/javascript', 'json' => 'application/json', 'woff2' => 'font/woff2', 'svg' => 'image/svg+xml'];
        $ext = strtolower(pathinfo((string) $file, PATHINFO_EXTENSION));
        if ($file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file) || !isset($types[$ext])) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Document introuvable.';

            return;
        }
        header('Content-Type: ' . $types[$ext]);
        readfile($file);
    }

    private function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; connect-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'");
    }

    private function startSession(): void
    {
        if (!$this->passwordRequired() || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('poseidon');
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
        session_start();
    }

    private function hostAllowed(string $host): bool
    {
        if ($this->passwordRequired() || PHP_SAPI === 'cli') {
            return true;
        }
        $name = strtolower((string) preg_replace('/:\d+$/', '', trim($host)));
        if (in_array($name, ['localhost', '127.0.0.1', '[::1]', '::1'], true) || str_ends_with($name, '.localhost')) {
            return true;
        }
        $extra = array_filter(array_map(static fn (string $h): string => strtolower(trim($h)), (array) $this->config->get('allowed_hosts', [])));

        return in_array($name, $extra, true);
    }

    private function passwordRequired(): bool
    {
        return (string) $this->config->get('password') !== '';
    }

    private function authenticated(): bool
    {
        return !$this->passwordRequired() || (($_SESSION['poseidon_auth'] ?? false) === true);
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        $raw = (string) file_get_contents('php://input');
        $data = json_decode($raw, true);

        return is_array($data) ? $data : $_POST;
    }

    private function required(string $key): string
    {
        $value = (string) ($_GET[$key] ?? '');
        if ($value === '') {
            throw new HttpError(400, "Paramètre manquant : {$key}");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function filenameHeader(string $name): string
    {
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'fichier';

        return 'filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
    }
}
