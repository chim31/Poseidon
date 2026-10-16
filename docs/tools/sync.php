<?php

declare(strict_types=1);

/*
 * Synchronise le suivi du projet à partir de la source unique docs/web/data/projet.json :
 *   - docs/web/data/projet.js                      (lu par cahier-des-charges.html et roadmap.html, y compris en file://)
 *   - docs/03-suivi/suivi-cahier-des-charges.md    (version Markdown du suivi)
 *
 * Usage : php docs/tools/sync.php
 * À lancer après chaque modification de projet.json, dans le même commit.
 */

$root = dirname(__DIR__);
$data = json_decode((string) file_get_contents($root . '/web/data/projet.json'), true, 512, JSON_THROW_ON_ERROR);

// 1. Données pour les pages web.
file_put_contents(
    $root . '/web/data/projet.js',
    "// Généré par docs/tools/sync.php depuis projet.json — ne pas modifier à la main.\nwindow.POSEIDON_PROJET = "
    . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\n"
);

// 2. Suivi Markdown.
$statuses = ['Terminé', 'En cours', 'À faire', 'Bloqué'];
$count = static function (array $items) use ($statuses): array {
    $c = array_fill_keys($statuses, 0);
    foreach ($items as $i) {
        $c[$i['statut']] = ($c[$i['statut']] ?? 0) + 1;
    }

    return $c;
};
$all = array_merge(...array_map(static fn (array $l): array => $l['exigences'], $data['lots']));
$global = $count($all);
$pct = static fn (array $c): int => (int) round(100 * $c['Terminé'] / max(1, array_sum($c)));
$bar = static fn (int $p): string => str_repeat('█', intdiv($p, 5)) . str_repeat('░', 20 - intdiv($p, 5));

$md = "# Suivi du cahier des charges\n\n";
$md .= "> Généré par `php docs/tools/sync.php` depuis [`docs/web/data/projet.json`](../web/data/projet.json) — modifier le JSON, pas ce fichier.\n";
$md .= "> Version web : [cahier-des-charges.html](../web/cahier-des-charges.html) · [roadmap.html](../web/roadmap.html)\n\n";
$md .= "**Mise à jour :** {$data['meta']['miseAJour']} · **Version :** {$data['meta']['version']}\n\n";
$md .= "## Tableau de bord\n\n";
$md .= sprintf("Avancement global : **%d %%** `%s` — %d exigences : %d terminées, %d en cours, %d à faire, %d bloquées.\n\n", $pct($global), $bar($pct($global)), count($all), $global['Terminé'], $global['En cours'], $global['À faire'], $global['Bloqué']);
$md .= "| Lot | Intitulé | Terminé | En cours | À faire | Avancement |\n| --- | --- | ---: | ---: | ---: | --- |\n";
foreach ($data['lots'] as $lot) {
    $c = $count($lot['exigences']);
    $md .= sprintf("| %s | %s | %d | %d | %d | %d %% |\n", $lot['id'], $lot['titre'], $c['Terminé'], $c['En cours'], $c['À faire'], $pct($c));
}
foreach ($data['lots'] as $lot) {
    $md .= "\n## {$lot['id']} — {$lot['titre']}\n\n_{$lot['objectif']}_\n\n";
    $md .= "| ID | Exigence | Statut | Priorité | Responsable | Date | Référence |\n| --- | --- | --- | --- | --- | --- | --- |\n";
    foreach ($lot['exigences'] as $e) {
        $md .= sprintf("| %s | **%s** — %s | %s | %s | %s | %s | %s |\n", $e['id'], $e['titre'], str_replace('|', '\|', $e['description']), $e['statut'], $e['priorite'], $e['responsable'], $e['date'] ?: '—', $e['ref'] ?: '—');
    }
}
file_put_contents($root . '/03-suivi/suivi-cahier-des-charges.md', $md);

echo sprintf("Suivi synchronisé : %d exigences, %d %% terminées.\n", count($all), $pct($global));
