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
 *   GET  /api/diff/folders?a=&b=       Comparaison de deux dossiers (ajoutés, modifiés, supprimés)
 *   GET  /api/git/diff-tree?repo=&from=&to=  Git diff complet entre deux références (to vide : dossier de travail)
 *   POST /api/extract                  Extraction vers CSV/JSON/TXT/MD/XLSX
 *   POST /api/login, /api/logout       Authentification (si POSEIDON_PASSWORD)
 */
final class Kernel
{
    private SearchEngine $engine;

    private FileLocator $locator;

    private \Poseidon\Auth\UserStore $users;

    private \Poseidon\Auth\AuditLog $audit;

    /** Sous-dossier d'installation sur le serveur web (« /poseidon »), vide à la racine du site. */
    private string $base = '';

    /** @var array{login: string, role: string, roots: list<string>}|null */
    private ?array $user = null;

    public function __construct(private Config $config)
    {
        $this->engine = new SearchEngine($config);
        $this->locator = $this->engine->locator();
        $this->users = new \Poseidon\Auth\UserStore($config);
        $this->audit = new \Poseidon\Auth\AuditLog($config);
    }

    public function handle(string $method, string $uri): void
    {
        $path = '/' . trim((string) parse_url($uri, PHP_URL_PATH), '/');
        // Installé dans un sous-dossier du serveur web (http://serveur/poseidon/…) : préfixe retiré.
        $scriptDir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
        if ($scriptDir !== '' && $scriptDir !== '.' && ($path === $scriptDir || str_starts_with($path, $scriptDir . '/'))) {
            $path = '/' . ltrim(substr($path, strlen($scriptDir)), '/');
            $this->base = $scriptDir;
        }
        if ($path === '/index.php' || str_starts_with($path, '/index.php/')) {
            $path = '/' . ltrim(substr($path, strlen('/index.php')), '/');
        }
        $this->securityHeaders();

        // Anti « DNS rebinding » : sans mot de passe, seuls les hôtes locaux ou déclarés sont servis.
        if (!$this->hostAllowed((string) ($_SERVER['HTTP_HOST'] ?? ''))) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Poseidon n'est pas encore protégé : sans compte ni mot de passe, il ne répond qu'à la machine locale (http://127.0.0.1).\n"
                . "Pour l'ouvrir au réseau : crée un compte (php bin/poseidon user add admin --role=admin) ou définis POSEIDON_PASSWORD.";

            return;
        }

        if ($path === '/projet' || str_starts_with($path, '/projet/')) {
            $this->projectDocs(substr($path, strlen('/projet')));

            return;
        }
        if (!str_starts_with($path, '/api/')) {
            // http://serveur/poseidon sans « / » final : les ressources relatives (assets/…) partiraient à la racine du site.
            $raw = (string) parse_url($uri, PHP_URL_PATH);
            if ($path === '/' && $this->base !== '' && !str_ends_with($raw, '/') && !str_ends_with($raw, '/index.php')) {
                header('Location: ' . $this->base . '/', true, 301);

                return;
            }
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
            $this->applyUserRoots();

            match ([$method, $path]) {
                ['POST', '/api/logout'] => $this->logout(),
                ['GET', '/api/browse'] => $this->browse(),
                ['GET', '/api/search'] => $this->search(),
                ['GET', '/api/preview'] => $this->preview(),
                ['GET', '/api/file'] => $this->file(),
                ['GET', '/api/text'] => $this->text(),
                ['GET', '/api/diff'] => $this->diff(),
                ['GET', '/api/diff/folders'] => $this->diffFolders(),
                ['GET', '/api/git/diff-tree'] => $this->gitDiffTree(),
                ['GET', '/api/index'] => $this->indexStatus(),
                ['POST', '/api/index'] => $this->requireAdmin() ?? $this->indexBuild(),
                ['POST', '/api/index/delete'] => $this->requireAdmin() ?? $this->indexDelete(),
                ['GET', '/api/templates'] => $this->json(['templates' => (new \Poseidon\Export\TemplateStore($this->config))->all()]),
                ['POST', '/api/templates'] => $this->templateSave(),
                ['POST', '/api/templates/delete'] => $this->requireAdmin() ?? $this->templateDelete(),
                ['GET', '/api/audit'] => $this->requireAdmin() ?? $this->json(['entries' => $this->audit->tail(max(1, min(1000, (int) ($_GET['limit'] ?? 300))))]),
                ['GET', '/api/report'] => $this->report(),
                ['GET', '/api/changes'] => $this->changes(),
                ['GET', '/api/git/log'] => $this->gitLog(),
                ['GET', '/api/git/diff'] => $this->gitDiff(),
                ['GET', '/api/git/search'] => $this->gitSearch(),
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
        $demoReady = false;
        if ($this->config->get('demo', true) !== false) {
            $demoReady = is_dir($demo);
            $parent = is_dir(dirname($demo)) ? dirname($demo) : POSEIDON_ROOT;
            if (!$demoReady && $this->authenticated() && is_writable($parent)) {
                try {
                    \Poseidon\Demo\Samples::build($demo);
                    $demoReady = true;
                } catch (\Throwable $e) {
                    error_log('[poseidon] échantillons de démonstration impossibles : ' . $e->getMessage());
                }
            }
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
            'git' => \Poseidon\Git\GitHistory::binary() !== null,
            'formats' => [...$this->engine->extractors()->supportedExtensions(), 'zip'],
            'allowedRoots' => array_map([Path::class, 'display'], $this->authenticated() && ($this->currentUser()['roots'] ?? []) !== [] ? $this->currentUser()['roots'] : $this->engine->guard()->allowedRoots()),
            'authRequired' => $this->passwordRequired(),
            'authMode' => $this->authMode(),
            'authenticated' => $this->authenticated(),
            'user' => $this->authenticated() ? array_intersect_key($this->currentUser(), ['login' => 1, 'role' => 1]) : null,
            'home' => Path::display((string) (getenv('HOME') ?: getenv('USERPROFILE') ?: POSEIDON_ROOT)),
            'demo' => $demoReady ? Path::display($demo) : null,
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
        $this->audit->log('recherche', $this->currentUser()['login'], ['racine' => $options->root, 'filtre' => $query->input, 'mode' => $query->mode]);
        $events = $this->engine->search($query, $options);
        // Valide la racine avant d'ouvrir le flux : une erreur reste une réponse JSON propre.
        $first = $events->current();

        set_time_limit(0);
        ignore_user_abort(true);
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $this->emit($first);
        $events->next();
        $matches = [];
        $summary = null;
        while ($events->valid()) {
            $event = $events->current();
            $this->emit($event);
            if ($event['type'] === 'match') {
                $matches[] = $event;
            } elseif ($event['type'] === 'done') {
                $summary = $event['summary'];
            }
            if (connection_aborted()) {
                return; // l'utilisateur a annulé : on arrête le parcours
            }
            $events->next();
        }

        // Instantané de la recherche et comparaison avec la précédente (mêmes filtre, options et compte).
        if ($summary !== null) {
            try {
                $snapshots = new \Poseidon\History\SnapshotStore($this->config);
                $snapshots->save($this->currentUser()['login'], $query, $options, $matches, $summary);
                $this->emit(['type' => 'changes', 'comparison' => $snapshots->compare($this->currentUser()['login'], $query, $options)]);
            } catch (\Throwable $e) {
                error_log('[poseidon] instantané impossible : ' . $e->getMessage());
            }
        }
    }

    /** @param array<string, mixed> $event */
    private function emit(array $event): void
    {
        echo json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        flush();
    }

    private function changes(): void
    {
        $query = Query::fromArray($_GET);
        $options = SearchOptions::fromArray($_GET);
        $store = new \Poseidon\History\SnapshotStore($this->config);
        $user = $this->currentUser()['login'];
        $this->json([
            'snapshots' => $store->list($user, $query, $options),
            'comparison' => $store->compare($user, $query, $options, isset($_GET['from']) ? (string) $_GET['from'] : null, isset($_GET['to']) ? (string) $_GET['to'] : null),
        ]);
    }

    /** @return array{0: \Poseidon\Filesystem\Source, 1: string, 2: string} Fichier, dépôt, chemin relatif au dépôt. */
    private function gitFile(): array
    {
        $source = $this->locator->locate($this->required('id'));
        if ($source->inArchive()) {
            throw new HttpError(422, 'Ce fichier est dans une archive : il n\'a pas d\'historique Git propre.');
        }
        if (\Poseidon\Git\GitHistory::binary() === null) {
            throw new HttpError(422, 'Git n\'est pas installé sur ce serveur.');
        }
        $repo = \Poseidon\Git\GitHistory::repository($source->diskPath) ?? throw new HttpError(404, 'Ce fichier n\'est pas dans un dépôt Git.');

        return [$source, $repo, (string) Path::relativeTo($source->diskPath, $repo)];
    }

    private function gitLog(): void
    {
        try {
            [$source, $repo, $rel] = $this->gitFile();
        } catch (HttpError $e) {
            $this->json(['available' => false, 'reason' => $e->getMessage(), 'commits' => []]);

            return;
        }
        $this->json(['available' => true, 'repository' => Path::display($repo), 'path' => $rel, 'commits' => \Poseidon\Git\GitHistory::log($repo, $rel)]);
    }

    private function gitDiff(): void
    {
        [$source, $repo, $rel] = $this->gitFile();
        $commit = $this->required('commit');
        \Poseidon\Git\GitHistory::assertRevision($commit);
        $max = (int) $this->config->get('max_file_size');
        $parent = \Poseidon\Git\GitHistory::parent($repo, $commit);
        $before = $parent === '' ? '' : \Poseidon\Git\GitHistory::show($repo, $parent, $rel, $max);
        $after = \Poseidon\Git\GitHistory::show($repo, $commit, $rel, $max);
        $this->audit->log('historique git', $this->currentUser()['login'], ['fichier' => $source->absolutePath(), 'commit' => $commit]);

        $ops = Diff::lines($this->versionLines($source, $before), $this->versionLines($source, $after));
        $hunks = Diff::hunks($ops);
        $this->json([
            'commit' => $commit,
            'parent' => $parent,
            'path' => $rel,
            'added' => count(array_filter($ops, static fn (array $o): bool => $o[0] === '+')),
            'removed' => count(array_filter($ops, static fn (array $o): bool => $o[0] === '-')),
            'identical' => $hunks === [],
            'hunks' => array_slice($hunks, 0, 500),
            'unified' => Diff::unified($rel . '@' . ($parent === '' ? 'vide' : substr($parent, 0, 8)), $rel . '@' . substr($commit, 0, 8), $hunks),
        ]);
    }

    /** @return list<string> Texte extrait d'une version (PDF, DOCX… comme fichiers texte). */
    private function versionLines(\Poseidon\Filesystem\Source $source, string $bytes): array
    {
        if ($bytes === '') {
            return [];
        }
        $version = new \Poseidon\Filesystem\Source($source->diskPath, [], $source->root, strlen($bytes), 0, static fn (): string => $bytes);
        try {
            $document = $this->engine->extractors()->extract($version, false);
        } catch (UnreadableFileException) {
            $document = null;
        }

        return $document === null ? ['(version non textuelle : ' . strlen($bytes) . ' octets)'] : array_column($document->lines, 1);
    }

    private function gitSearch(): void
    {
        $root = $this->engine->guard()->resolveRoot($this->required('root'));
        $q = trim($this->required('q'));
        if (\Poseidon\Git\GitHistory::binary() === null) {
            throw new HttpError(422, 'Git n\'est pas installé sur ce serveur.');
        }
        $repo = \Poseidon\Git\GitHistory::repository($root) ?? throw new HttpError(404, 'Cette racine n\'est pas dans un dépôt Git.');
        $this->audit->log('recherche git', $this->currentUser()['login'], ['racine' => $root, 'filtre' => $q]);
        $this->json([
            'repository' => Path::display($repo),
            'commits' => (new \Poseidon\Git\HistorySearch($this->engine->extractors()))->search($repo, (string) Path::relativeTo($root, $repo), Query::fromArray($_GET)),
        ]);
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
        $this->audit->log('indexation', $this->currentUser()['login'], ['racine' => (string) ($input['root'] ?? '')]);
        $indexer = new \Poseidon\Index\Indexer($this->config, $this->engine->extractors(), $this->engine->guard());
        $events = $indexer->build((string) ($input['root'] ?? ''), filter_var($input['rebuild'] ?? false, FILTER_VALIDATE_BOOL));
        $first = $events->current();

        set_time_limit(0);
        ignore_user_abort(true);
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
        $this->audit->log('modèle enregistré', $this->currentUser()['login'], ['modele' => $template['name']]);
        $this->json(['template' => $template]);
    }

    private function report(): void
    {
        set_time_limit(0);
        $query = Query::fromArray($_GET);
        $options = SearchOptions::fromArray($_GET);
        $this->audit->log('rapport', $this->currentUser()['login'], ['racine' => $options->root, 'filtre' => $query->input]);
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
        $this->audit->log('index supprimé', $this->currentUser()['login'], ['racine' => $root]);
        $this->json(['deleted' => \Poseidon\Index\Index::delete($this->config, $root)]);
    }

    private function preview(): void
    {
        $source = $this->locator->locate($this->required('id'));
        $this->audit->log('aperçu', $this->currentUser()['login'], ['fichier' => $source->absolutePath()]);
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
        $this->audit->log('téléchargement', $this->currentUser()['login'], ['fichier' => $source->absolutePath()]);
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
        $this->audit->log('texte', $this->currentUser()['login'], ['fichier' => $source->absolutePath()]);
        $document = $this->engine->extractors()->extract($source) ?? throw new HttpError(415, 'Ce fichier ne contient pas de texte exploitable.');
        $out = '';
        foreach ($document->lines as [$location, $line]) {
            $out .= $line . "\n";
        }
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; ' . self::filenameHeader(pathinfo($source->name(), PATHINFO_FILENAME) . '.txt'));
        echo $out;
    }

    /** Comparaison intégrale de deux dossiers (ajoutés, modifiés, supprimés), façon GitHub. */
    private function diffFolders(): void
    {
        // resolveRoot : correspondances réseau, chemin réel et racines autorisées du compte.
        $guard = $this->engine->guard();
        $dirA = $guard->resolveRoot($this->required('a'));
        $dirB = $guard->resolveRoot($this->required('b'));
        $this->audit->log('comparaison dossiers', $this->currentUser()['login'], ['a' => $dirA, 'b' => $dirB]);
        set_time_limit(600);

        $this->json(Diff::compareDirectories(
            $dirA,
            $dirB,
            $this->engine->extractors(),
            $this->diffContext(),
            (int) $this->config->get('max_file_size'),
            (int) $this->config->get('diff_max_files', 2000),
        ));
    }

    /**
     * Git diff complet entre deux références (HEAD~1, main, tag, hash) ou entre une référence et le dossier de travail.
     * Toutes les commandes passent par GitHistory (configuration du dépôt neutralisée, safe.directory respecté).
     */
    private function gitDiffTree(): void
    {
        if (\Poseidon\Git\GitHistory::binary() === null) {
            throw new HttpError(422, 'Git n\'est pas installé sur ce serveur.');
        }
        $root = $this->engine->guard()->resolveRoot($this->required('repo'));
        $repo = \Poseidon\Git\GitHistory::repository($root) ?? throw new HttpError(404, 'Ce dossier n\'est pas dans un dépôt Git.');
        // Le dépôt peut englober la racine autorisée : le diff reste limité au dossier saisi.
        $pathspec = (string) Path::relativeTo($root, $repo);

        $fromInput = trim((string) ($_GET['from'] ?? '')) ?: 'HEAD~1';
        $toInput = trim((string) ($_GET['to'] ?? ''));
        $from = \Poseidon\Git\GitHistory::resolve($repo, $fromInput);
        $to = $toInput === '' ? '' : \Poseidon\Git\GitHistory::resolve($repo, $toInput);
        $this->audit->log('git diff', $this->currentUser()['login'], ['depot' => $repo, 'de' => $fromInput, 'a' => $toInput === '' ? 'dossier de travail' : $toInput]);
        set_time_limit(600);

        $max = (int) $this->config->get('max_file_size');
        $limit = (int) $this->config->get('diff_max_files', 2000);
        $context = $this->diffContext();
        $changes = \Poseidon\Git\GitHistory::changes($repo, $from, $to, $pathspec);
        $truncated = count($changes) > $limit;
        $files = [];
        foreach (array_slice($changes, 0, $limit) as $change) {
            $before = $change['status'] === 'A' ? '' : $this->gitVersion($repo, $from, $change['oldPath'], $max);
            $after = $change['status'] === 'D' ? '' : ($to !== '' ? $this->gitVersion($repo, $to, $change['path'], $max) : $this->workingCopy($repo, $change['path'], $max));
            $source = new \Poseidon\Filesystem\Source($repo . '/' . $change['path'], [], $repo, strlen($after), 0);
            $files[] = Diff::fileEntry($change['path'], $change['status'], $this->versionLines($source, $before), $this->versionLines($source, $after), $context, $change['oldPath']);
        }

        $this->json([
            'stats' => Diff::stats($files),
            'repo' => Path::display($repo),
            'from' => $fromInput . ' (' . substr($from, 0, 8) . ')',
            'to' => $to === '' ? 'dossier de travail' : $toInput . ' (' . substr($to, 0, 8) . ')',
            'files' => $files,
            'unified' => implode("\n", array_column($files, 'unified')),
            'truncated' => $truncated,
            'skipped' => [],
        ]);
    }

    private function diffContext(): int
    {
        return max(0, min(20, (int) ($_GET['context'] ?? 3)));
    }

    /** Contenu d'un fichier à un commit ; une version illisible devient une ligne explicative. */
    private function gitVersion(string $repo, string $commit, string $rel, int $max): string
    {
        try {
            return \Poseidon\Git\GitHistory::show($repo, $commit, $rel, $max);
        } catch (UnreadableFileException $e) {
            return '(' . $e->getMessage() . ')';
        }
    }

    /** Contenu d'un fichier du dossier de travail ; un lien symbolique n'est jamais suivi (Git stocke sa cible). */
    private function workingCopy(string $repo, string $rel, int $max): string
    {
        $native = Path::toNative($repo . '/' . $rel);
        if (is_link($native)) {
            return (string) @readlink($native);
        }
        if (!is_file($native)) {
            return '';
        }
        if ($max > 0 && (int) @filesize($native) > $max) {
            return '(fichier trop volumineux pour être affiché)';
        }

        return (string) @file_get_contents($native);
    }

    private function diff(): void
    {
        $a = $this->locator->locate($this->required('a'));
        $b = $this->locator->locate($this->required('b'));
        $this->audit->log('comparaison', $this->currentUser()['login'], ['a' => $a->absolutePath(), 'b' => $b->absolutePath()]);
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
        $this->audit->log('extraction', $this->currentUser()['login'], ['racine' => (string) ($input['root'] ?? ''), 'filtre' => (string) ($input['q'] ?? ''), 'mode' => $spec->mode, 'format' => $spec->format, 'lignes' => count($data['rows'])]);

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
        $mode = $this->authMode();
        if ($mode === 'none') {
            $this->json(['authenticated' => true]);

            return;
        }
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $throttle = new \Poseidon\Auth\Throttle($this->config);
        if ($throttle->blocked($ip)) {
            throw new HttpError(429, 'Trop de tentatives : réessaie dans quelques minutes.');
        }
        usleep(random_int(200_000, 400_000)); // ralentit le forçage
        $password = (string) ($input['password'] ?? '');
        if ($mode === 'users') {
            $login = (string) ($input['login'] ?? '');
            $user = $this->users->verify($login, $password);
            if ($user === null) {
                $throttle->fail($ip);
                $this->audit->log('connexion refusée', $login === '' ? '?' : strtolower($login));
                throw new HttpError(401, 'Identifiant ou mot de passe incorrect.');
            }
            $name = $user['login'];
        } else {
            if (!hash_equals((string) $this->config->get('password'), $password)) {
                $throttle->fail($ip);
                $this->audit->log('connexion refusée', 'partagé');
                throw new HttpError(401, 'Mot de passe incorrect.');
            }
            $name = 'partagé';
        }
        $throttle->clear($ip);
        session_regenerate_id(true);
        $_SESSION['poseidon_user'] = $name;
        $this->audit->log('connexion', $name);
        $this->json(['authenticated' => true, 'user' => array_intersect_key($this->currentUser(), ['login' => 1, 'role' => 1])]);
    }

    private function logout(): void
    {
        $this->audit->log('déconnexion', $this->currentUser()['login']);
        @session_start();
        $_SESSION = [];
        session_destroy();
        $this->json(['authenticated' => false]);
    }

    private function templateDelete(): void
    {
        $name = (string) ($this->input()['name'] ?? '');
        $this->audit->log('modèle supprimé', $this->currentUser()['login'], ['modele' => $name]);
        $this->json(['deleted' => (new \Poseidon\Export\TemplateStore($this->config))->delete($name)]);
    }

    /** « none » (poste local), « password » (mot de passe partagé) ou « users » (comptes). */
    private function authMode(): string
    {
        if ($this->users->enabled()) {
            return 'users';
        }

        return (string) $this->config->get('password') !== '' ? 'password' : 'none';
    }

    /** @return array{login: string, role: string, roots: list<string>} */
    private function currentUser(): array
    {
        if ($this->user !== null) {
            return $this->user;
        }

        return $this->user = match ($this->authMode()) {
            'none' => ['login' => 'local', 'role' => 'admin', 'roots' => []],
            'password' => ['login' => 'partagé', 'role' => 'admin', 'roots' => []],
            default => $this->users->get((string) ($_SESSION['poseidon_user'] ?? '')) ?? ['login' => '?', 'role' => 'lecteur', 'roots' => []],
        };
    }

    /** Un compte limité à certaines racines ne voit qu'elles (recherche, aperçu, téléchargement). */
    private function applyUserRoots(): void
    {
        $roots = $this->currentUser()['roots'];
        if ($roots !== []) {
            $this->engine = new SearchEngine($this->config->with(['allowed_roots' => $roots]));
            $this->locator = $this->engine->locator();
        }
    }

    /** @return null Lève 403 si le compte n'est pas administrateur. */
    private function requireAdmin(): null
    {
        if ($this->currentUser()['role'] !== 'admin') {
            throw new HttpError(403, 'Action réservée aux administrateurs.');
        }

        return null;
    }

    private function page(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $html = (string) file_get_contents(POSEIDON_ROOT . '/public/app.html');
        // Point d'entrée réel (« /poseidon/index.php ») : l'interface appelle l'API à travers lui,
        // ce qui fonctionne sans module de réécriture (IIS, WAMP, Apache, Nginx, serveur intégré).
        $entry = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $meta = '<meta name="poseidon-entry" content="' . htmlspecialchars(str_ends_with($entry, '.php') ? $entry : '', ENT_QUOTES) . '">';
        $html = preg_replace('/<head>/i', "<head>\n  {$meta}", $html, 1) ?? $html;
        // Lancé depuis l'index.php à la racine du projet : les ressources statiques sont dans public/.
        $script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        if ($script !== false && $script === realpath(POSEIDON_ROOT . '/index.php')) {
            $html = str_replace(['href="assets/', 'src="assets/'], ['href="public/assets/', 'src="public/assets/'], $html);
        }
        echo $html;
    }

    /** Cahier des charges et roadmap (docs/web), servis en lecture seule. */
    private function projectDocs(string $relative): void
    {
        $relative = ltrim($relative, '/');
        if ($relative === '') {
            header('Location: ' . $this->base . '/projet/cahier-des-charges.html', true, 302);

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

    /**
     * Sans authentification, seule la machine elle-même peut utiliser Poseidon : adresse source locale
     * (un poste du réseau pourrait sinon envoyer « Host: localhost ») et hôte local (anti DNS rebinding).
     * L'ouverture au réseau sans mot de passe exige un choix explicite (POSEIDON_INSECURE_NO_AUTH=1).
     */
    private function hostAllowed(string $host): bool
    {
        if ($this->passwordRequired() || PHP_SAPI === 'cli' && !isset($_SERVER['REMOTE_ADDR'])) {
            return true;
        }
        $name = strtolower((string) preg_replace('/:\d+$/', '', trim($host)));
        $localName = in_array($name, ['localhost', '127.0.0.1', '[::1]', '::1'], true) || str_ends_with($name, '.localhost');
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $localClient = $remote === '127.0.0.1' || $remote === '::1' || str_starts_with($remote, '127.') || $remote === '::ffff:127.0.0.1';
        if ($localName && $localClient) {
            return true;
        }
        if ($this->config->get('insecure_no_auth', false) === true) {
            $extra = array_filter(array_map(static fn (string $h): string => strtolower(trim($h)), (array) $this->config->get('allowed_hosts', [])));

            return $localName || in_array($name, $extra, true);
        }

        return false;
    }

    private function passwordRequired(): bool
    {
        return $this->authMode() !== 'none';
    }

    private function authenticated(): bool
    {
        if ($this->authMode() === 'none') {
            return true;
        }
        $login = $_SESSION['poseidon_user'] ?? null;

        // Compte supprimé depuis la connexion : la session n'est plus valable.
        return is_string($login) && ($this->authMode() === 'password' ? $login === 'partagé' : $this->users->get($login) !== null);
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
