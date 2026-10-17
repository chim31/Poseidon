<?php

declare(strict_types=1);

namespace Poseidon\Export;

use Poseidon\Config;

/**
 * Modèles d'extraction enregistrés : un « SELECT » (filtre + options + extraction) à rejouer
 * sur n'importe quelle racine. Un fichier JSON par modèle dans var/templates.
 */
final class TemplateStore
{
    private const QUERY_KEYS = ['q', 'mode', 'caseSensitive', 'wholeWord', 'ignoreAccents', 'distance', 'fuzzy'];
    private const OPTION_KEYS = ['extensions', 'archives', 'target', 'name'];
    private const EXTRACT_KEYS = ['extract', 'format', 'columns', 'headerRow', 'allRows', 'withSource', 'unique'];

    public function __construct(private Config $config)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $out = [];
        foreach (glob($this->dir() . '/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && isset($data['name'])) {
                $out[] = $data;
            }
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function get(string $name): ?array
    {
        $file = $this->file($name);
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) ? $data : null;
    }

    /**
     * Enregistre un modèle à partir des paramètres d'une recherche et d'une extraction (la racine n'est pas gardée).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function save(string $name, array $params, string $description = ''): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw new \InvalidArgumentException('Donne un nom de modèle (80 caractères au plus).');
        }
        // Valide les paramètres avant d'écrire quoi que ce soit.
        \Poseidon\Search\Query::fromArray($params);
        ExtractionSpec::fromArray($params);

        $pick = static fn (array $keys): array => array_intersect_key($params, array_flip($keys));
        $template = [
            'name' => $name,
            'description' => mb_substr(trim($description), 0, 300),
            'query' => $pick(self::QUERY_KEYS),
            'options' => $pick(self::OPTION_KEYS),
            'extraction' => $pick(self::EXTRACT_KEYS),
            'savedAt' => date(DATE_ATOM),
        ];
        $dir = $this->dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Impossible de créer le dossier des modèles : ' . $dir);
        }
        file_put_contents($this->file($name), json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

        return $template;
    }

    public function delete(string $name): bool
    {
        $file = $this->file($name);

        return is_file($file) && @unlink($file);
    }

    /**
     * Paramètres complets (recherche + extraction) d'un modèle, prêts pour Query/SearchOptions/ExtractionSpec.
     *
     * @return array<string, mixed>
     */
    public static function flatten(array $template): array
    {
        return (array) ($template['query'] ?? []) + (array) ($template['options'] ?? []) + (array) ($template['extraction'] ?? []);
    }

    private function dir(): string
    {
        return (string) $this->config->get('templates_dir', POSEIDON_ROOT . '/var/templates');
    }

    private function file(string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name))), '-');

        return $this->dir() . '/' . ($slug === '' ? substr(sha1($name), 0, 12) : $slug) . '.json';
    }
}
