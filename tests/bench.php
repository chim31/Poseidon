<?php

declare(strict_types=1);

/*
 * Banc d'essai de performance.
 *   php tests/bench.php [nombre de fichiers = 10000]
 * Génère une arborescence synthétique, puis mesure : parcours à froid, à chaud (cache),
 * construction de l'index, recherche indexée, recherche regex (sans index).
 */

require dirname(__DIR__) . '/bootstrap.php';

use Poseidon\Config;
use Poseidon\Demo\Samples;
use Poseidon\Export\XlsxWriter;
use Poseidon\Index\Index;
use Poseidon\Index\Indexer;
use Poseidon\Search\Query;
use Poseidon\Search\SearchEngine;
use Poseidon\Search\SearchOptions;

$count = max(100, (int) ($argv[1] ?? 10000));
$base = sys_get_temp_dir() . '/poseidon-bench-' . getmypid();
$dir = $base . '/arbre';
$config = Config::load()->with(['allowed_roots' => [], 'cache_dir' => $base . '/cache', 'index_dir' => $base . '/index']);

$words = ['contrat', 'facture', 'livraison', 'préavis', 'client', 'navire', 'port', 'cargaison', 'trident', 'marée', 'escale', 'quai', 'devis', 'avenant', 'commande', 'fournisseur', 'logistique', 'douane', 'transport', 'archive'];
mt_srand(42);
$sentence = static function (int $n) use ($words): string {
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = $words[mt_rand(0, count($words) - 1)];
    }

    return ucfirst(implode(' ', $out)) . '.';
};

echo "Génération de {$count} fichiers…\n";
$t = microtime(true);
for ($i = 0; $i < $count; $i++) {
    $sub = $dir . '/lot' . intdiv($i, 500) . '/sous' . ($i % 7);
    if (!is_dir($sub)) {
        mkdir($sub, 0775, true);
    }
    $kind = $i % 100;
    $text = '';
    for ($l = 0; $l < 40; $l++) {
        $text .= $sentence(12) . "\n";
    }
    if ($i % 997 === 0) {
        $text .= "Référence rare : NR" . str_pad((string) $i, 6, '0', STR_PAD_LEFT) . " sous le pavillon kraken.\n";
    }
    match (true) {
        $kind < 70 => file_put_contents("{$sub}/note{$i}.txt", $text),
        $kind < 80 => file_put_contents("{$sub}/doc{$i}.md", "# Note {$i}\n\n" . $text),
        $kind < 88 => file_put_contents("{$sub}/table{$i}.csv", "Ref;Texte\n" . implode("\n", array_map(static fn ($l, $k) => "R{$k};{$l}", explode("\n", trim($text)), array_keys(explode("\n", trim($text)))))),
        $kind < 94 => file_put_contents("{$sub}/rapport{$i}.docx", Samples::docx(explode("\n", trim($text)))),
        $kind < 97 => file_put_contents("{$sub}/suivi{$i}.xlsx", XlsxWriter::build('S', ['Ligne'], array_map(static fn ($l) => [$l], explode("\n", trim($text))))),
        $kind < 99 => file_put_contents("{$sub}/cgv{$i}.pdf", Samples::pdf(array_slice(explode("\n", trim(strtr($text, ['é' => 'e', 'è' => 'e']))), 0, 20))),
        default => file_put_contents("{$sub}/lot{$i}.zip", Samples::zip(["a{$i}.txt" => $text, "b{$i}.docx" => Samples::docx(explode("\n", trim($text)))])),
    };
}
printf("  %.1f s\n\n", microtime(true) - $t);

$engine = new SearchEngine($config);
$run = static function (string $label, array $params) use ($engine, $dir): void {
    $t = microtime(true);
    $res = $engine->collect(Query::fromArray($params), SearchOptions::fromArray(['root' => $dir, 'limit' => 100000] + $params));
    $s = $res['summary'];
    $skipped = $s['index'] === null ? '' : sprintf(' (index : %d fichiers, %d archives non relus)', $s['index']['skippedFiles'], $s['index']['skippedArchives']);
    printf("%-46s %7.2f s  %5d trouvés / %d analysés%s\n", $label, microtime(true) - $t, $s['matched'], $s['scanned'], $skipped);
};

$run('1. Parcours à froid (sans cache, sans index)', ['q' => 'kraken', 'index' => '0']);
$run('2. Parcours à chaud (cache, sans index)', ['q' => 'kraken', 'index' => '0']);
if (Index::available()) {
    $t = microtime(true);
    foreach ((new Indexer($config, $engine->extractors(), $engine->guard()))->build($dir) as $_) {
    }
    printf("%-46s %7.2f s\n", '3. Construction de l\'index', microtime(true) - $t);
    $run('4. Recherche indexée « kraken »', ['q' => 'kraken']);
    $run('5. Recherche indexée « contrat préavis » (fréquent)', ['q' => 'contrat préavis']);
    $run('6. Regex (index inutilisable)', ['q' => 'NR\d{6}', 'mode' => 'regex']);
    printf("\nTaille de l'index : %.1f Mo\n", Index::find($config, $dir)->stats()['sizeBytes'] / 1048576);
}

Samples::rmdir($base);
