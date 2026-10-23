<?php

declare(strict_types=1);

namespace Poseidon\History;

use Poseidon\Config;
use Poseidon\Search\Query;
use Poseidon\Search\SearchOptions;
use Poseidon\Support\Path;

/**
 * Instantanés de recherche : à chaque recherche terminée, la liste des fichiers trouvés et leurs
 * compteurs est gardée (20 par recherche et par compte). Comparer deux instantanés donne ce qui a
 * changé : fichiers apparus, disparus, occurrences en hausse ou en baisse.
 */
final class SnapshotStore
{
    private const KEEP = 20;

    public function __construct(private Config $config)
    {
    }

    /**
     * @param list<array<string, mixed>> $matches Événements « match » de la recherche.
     * @param array<string, mixed>       $summary
     */
    public function save(string $user, Query $query, SearchOptions $options, array $matches, array $summary): string
    {
        $dir = $this->dir($user, $query, $options);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Impossible de créer le dossier des instantanés.');
        }
        $files = [];
        foreach ($matches as $m) {
            $files[$m['file']['relativePath']] = [
                'total' => (int) $m['total'],
                'counts' => array_map('intval', $m['counts']),
                'size' => (int) $m['file']['size'],
                'modified' => $m['file']['modified'],
            ];
        }
        $snapshot = [
            'at' => date(DATE_ATOM),
            'root' => (string) ($summary['root'] ?? Path::display($options->root)),
            'query' => $query->toArray(),
            'terms' => $query->terms,
            'matched' => count($files),
            'occurrences' => (int) ($summary['occurrences'] ?? 0),
            'files' => $files,
        ];
        // Horodatage à la microseconde : l'ordre des noms est l'ordre chronologique, même pour deux recherches dans la même seconde.
        $file = $dir . '/' . (new \DateTimeImmutable())->format('Ymd-His-u') . '.json';
        // Écrit à côté puis renommé : une comparaison lancée en même temps ne lit jamais un instantané incomplet.
        $tmp = $file . '.tmp';
        file_put_contents($tmp, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
        }

        $all = $this->files($dir);
        foreach (array_slice($all, self::KEEP) as $old) {
            @unlink($old);
        }

        return basename($file, '.json');
    }

    /** @return list<array{id: string, at: string, matched: int, occurrences: int}> Du plus récent au plus ancien. */
    public function list(string $user, Query $query, SearchOptions $options): array
    {
        $out = [];
        foreach ($this->files($this->dir($user, $query, $options)) as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                $out[] = ['id' => basename($file, '.json'), 'at' => $data['at'], 'matched' => $data['matched'], 'occurrences' => $data['occurrences']];
            }
        }

        return $out;
    }

    /**
     * Compare deux instantanés (par défaut : le plus récent et le précédent).
     *
     * @return array<string, mixed>|null null s'il n'y a pas deux instantanés à comparer.
     */
    public function compare(string $user, Query $query, SearchOptions $options, ?string $from = null, ?string $to = null): ?array
    {
        $dir = $this->dir($user, $query, $options);
        $files = $this->files($dir);
        $pick = static function (?string $id, int $default) use ($files, $dir): ?string {
            if ($id !== null && preg_match('/^\d{8}-\d{6}-\d{6}$/', $id) && is_file("{$dir}/{$id}.json")) {
                return "{$dir}/{$id}.json";
            }

            return $files[$default] ?? null;
        };
        $newFile = $pick($to, 0);
        $oldFile = $pick($from, 1);
        if ($newFile === null || $oldFile === null || $newFile === $oldFile) {
            return null;
        }
        $old = json_decode((string) file_get_contents($oldFile), true);
        $new = json_decode((string) file_get_contents($newFile), true);

        return self::diff($old, $new);
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @return array<string, mixed>
     */
    public static function diff(array $old, array $new): array
    {
        $appeared = [];
        $disappeared = [];
        $changed = [];
        foreach ($new['files'] as $path => $f) {
            if (!isset($old['files'][$path])) {
                $appeared[] = ['path' => $path, 'total' => $f['total']];
            } elseif ($old['files'][$path]['total'] !== $f['total'] || $old['files'][$path]['modified'] !== $f['modified']) {
                $changed[] = ['path' => $path, 'before' => $old['files'][$path]['total'], 'after' => $f['total'], 'modified' => $old['files'][$path]['modified'] !== $f['modified']];
            }
        }
        foreach ($old['files'] as $path => $f) {
            if (!isset($new['files'][$path])) {
                $disappeared[] = ['path' => $path, 'total' => $f['total']];
            }
        }
        usort($changed, static fn (array $a, array $b): int => abs($b['after'] - $b['before']) <=> abs($a['after'] - $a['before']));

        return [
            'from' => ['at' => $old['at'], 'matched' => $old['matched'], 'occurrences' => $old['occurrences']],
            'to' => ['at' => $new['at'], 'matched' => $new['matched'], 'occurrences' => $new['occurrences']],
            'appeared' => $appeared,
            'disappeared' => $disappeared,
            'changed' => $changed,
            'unchanged' => count($new['files']) - count($appeared) - count($changed),
        ];
    }

    /** @return list<string> Fichiers d'instantanés, du plus récent au plus ancien. */
    private function files(string $dir): array
    {
        $files = glob($dir . '/*.json') ?: [];
        rsort($files);

        return $files;
    }

    private function dir(string $user, Query $query, SearchOptions $options): string
    {
        $key = json_encode([
            strtolower($user),
            strtolower(Path::normalize($options->root)),
            $query->toArray(),
            $options->extensions,
            $options->intoArchives,
            $options->target,
            $options->nameFilter,
        ]);

        return (string) $this->config->get('snapshots_dir', POSEIDON_ROOT . '/var/snapshots') . '/' . sha1((string) $key);
    }
}
