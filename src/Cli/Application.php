<?php

declare(strict_types=1);

namespace Poseidon\Cli;

use Poseidon\Config;
use Poseidon\Export\DataExtractor;
use Poseidon\Export\ExtractionSpec;
use Poseidon\Export\Writer;
use Poseidon\Filesystem\FileLocator;
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
                        )) . PHP_EOL;
                    }

                    return $s['matched'] > 0 ? 0 : 1;
            }
        }

        return 1;
    }

    /** @param list<string> $args @param array<string, string|bool> $opts */
    private function extract(array $args, array $opts): int
    {
        if (count($args) < 2) {
            throw new \InvalidArgumentException('Usage : poseidon extract <racine> <filtre> --out=resultat.csv [--extract=matches|lines|columns|files] [--columns=A,C]');
        }
        $engine = new SearchEngine($this->config);
        $out = (string) ($opts['out'] ?? '');
        $format = (string) ($opts['format'] ?? ($out !== '' ? Path::extension($out) : 'csv'));
        $spec = ExtractionSpec::fromArray(['extract' => $opts['extract'] ?? 'matches', 'format' => $format ?: 'csv'] + $opts);
        $extractor = new DataExtractor($engine, $engine->extractors(), new FileLocator($engine->guard()));
        $data = $extractor->run($this->query($args[1], $opts), $this->options($args[0], $opts), $spec);

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
        $port = (int) ($opts['port'] ?? 8080);
        $public = POSEIDON_ROOT . '/public';
        echo $this->paint('1;36', 'Poseidon') . " écoute sur http://{$host}:{$port}  (Ctrl+C pour arrêter)" . PHP_EOL;
        if ($host !== '127.0.0.1' && $host !== 'localhost' && (string) $this->config->get('password') === '') {
            fwrite(STDERR, $this->paint('33', 'Attention : interface ouverte sur le réseau sans mot de passe. Définis POSEIDON_PASSWORD (recommandé) ; sinon seuls localhost et les hôtes de POSEIDON_HOSTS seront servis.') . PHP_EOL);
        }
        if (DIRECTORY_SEPARATOR === '/') {
            // Plusieurs processus : l'aperçu reste réactif pendant qu'une recherche tourne.
            putenv('PHP_CLI_SERVER_WORKERS=' . max(2, (int) ($opts['workers'] ?? 4)));
        }
        passthru(escapeshellarg(PHP_BINARY) . ' -d variables_order=EGPCS -S ' . escapeshellarg("{$host}:{$port}") . ' -t ' . escapeshellarg($public) . ' ' . escapeshellarg($public . '/index.php'), $code);

        return (int) $code;
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
        echo 'Racines permises : ' . ($roots === [] ? 'toutes (aucune restriction)' : implode(', ', array_map([Path::class, 'display'], $roots))) . PHP_EOL;
        echo 'Formats          : ' . implode(', ', $engine->extractors()->supportedExtensions()) . ', zip' . PHP_EOL;

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
          serve   [--host=] [--port=]      Lancer l'interface web
          info                             Configuration et formats pris en charge
          demo    [dossier]                Générer les échantillons synthétiques de démonstration

        Options de recherche
          --mode=all|any|phrase|regex      Tous les mots (défaut), un des mots, phrase exacte, expression régulière
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

        Options d'extraction
          --out=fichier.(csv|json|txt|md|xlsx)
          --extract=matches|lines|columns|files
          --columns=A,C,Montant            Colonnes à extraire (mode columns)
          --header-row=1                   Ligne d'en-tête (0 = aucune)
          --all-rows                       Toutes les lignes des tableaux, pas seulement celles qui correspondent
          --unique                         Dédoublonner

        Exemples
          poseidon search ~/Documents "contrat résiliation" --ext=pdf,docx
          poseidon search "\\\\srv\\juridique" "\"préavis de 3 mois\"" --mode=any
          poseidon extract ./exports "[A-Z]{2}\\d{6}" --mode=regex --out=references.xlsx
          poseidon extract ./finance "résilié" --extract=columns --columns=Client,Montant --out=resilies.csv

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
