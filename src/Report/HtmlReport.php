<?php

declare(strict_types=1);

namespace Poseidon\Report;

use Poseidon\Search\Query;
use Poseidon\Search\SearchOptions;

/**
 * Rapport de recherche autonome (un seul fichier HTML, sans script ni ressource externe),
 * imprimable en PDF depuis le navigateur : synthèse, fichiers, lignes-preuves, fichiers ignorés.
 */
final class HtmlReport
{
    /** Pavillons du code des signaux (mêmes ordre et couleurs que l'interface). */
    private const FLAGS = [
        ['A', '#1d5fa8', '<rect width="15" height="20" fill="#fff"/><path d="M15 0h15l-6 10 6 10H15z" fill="#1d5fa8"/>'],
        ['B', '#c8302b', '<path d="M0 0h30l-6 10 6 10H0z" fill="#c8302b"/>'],
        ['I', '#f2c230', '<rect width="30" height="20" fill="#f2c230"/><circle cx="15" cy="10" r="5" fill="#14202e"/>'],
        ['L', '#14202e', '<rect width="15" height="10" fill="#f2c230"/><rect x="15" width="15" height="10" fill="#14202e"/><rect y="10" width="15" height="10" fill="#14202e"/><rect x="15" y="10" width="15" height="10" fill="#f2c230"/>'],
        ['C', '#1d5fa8', '<rect width="30" height="4" fill="#1d5fa8"/><rect y="4" width="30" height="4" fill="#fff"/><rect y="8" width="30" height="4" fill="#c8302b"/><rect y="12" width="30" height="4" fill="#fff"/><rect y="16" width="30" height="4" fill="#1d5fa8"/>'],
        ['D', '#f2c230', '<rect width="30" height="5" fill="#f2c230"/><rect y="5" width="30" height="10" fill="#1d5fa8"/><rect y="15" width="30" height="5" fill="#f2c230"/>'],
        ['E', '#c8302b', '<rect width="30" height="10" fill="#1d5fa8"/><rect y="10" width="30" height="10" fill="#c8302b"/>'],
        ['F', '#c8302b', '<rect width="30" height="20" fill="#fff"/><path d="M15 1 28 10 15 19 2 10z" fill="#c8302b"/>'],
        ['G', '#f2c230', '<rect width="5" height="20" fill="#f2c230"/><rect x="5" width="5" height="20" fill="#1d5fa8"/><rect x="10" width="5" height="20" fill="#f2c230"/><rect x="15" width="5" height="20" fill="#1d5fa8"/><rect x="20" width="5" height="20" fill="#f2c230"/><rect x="25" width="5" height="20" fill="#1d5fa8"/>'],
        ['H', '#c8302b', '<rect width="15" height="20" fill="#fff"/><rect x="15" width="15" height="20" fill="#c8302b"/>'],
        ['J', '#1d5fa8', '<rect width="30" height="6.66" fill="#1d5fa8"/><rect y="6.66" width="30" height="6.67" fill="#fff"/><rect y="13.33" width="30" height="6.67" fill="#1d5fa8"/>'],
        ['K', '#f2c230', '<rect width="15" height="20" fill="#f2c230"/><rect x="15" width="15" height="20" fill="#1d5fa8"/>'],
    ];

    private const MODES = [
        Query::ALL => 'Tous les mots', Query::ANY => 'Un des mots', Query::PHRASE => 'Phrase exacte',
        Query::REGEX => 'Motif (regex)', Query::NEAR => 'Proximité',
    ];

    /**
     * @param array{matches: list<array<string, mixed>>, notices: list<array<string, mixed>>, summary: array<string, mixed>} $result
     */
    public static function render(Query $query, SearchOptions $options, array $result): string
    {
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $s = $result['summary'];
        $terms = $query->terms;
        $date = (new \DateTimeImmutable())->format('d/m/Y à H:i');

        $optionsText = array_filter([
            self::MODES[$query->mode] . ($query->mode === Query::NEAR ? " ({$query->distance} mots)" : ''),
            $query->caseSensitive ? 'casse respectée' : null,
            $query->wholeWord ? 'mot entier' : null,
            $query->ignoreAccents ? null : 'accents respectés',
            $query->fuzzy ? 'fautes de frappe tolérées' : null,
            $options->extensions !== [] ? 'formats : ' . implode(', ', $options->extensions) : null,
            $options->intoArchives ? null : 'archives non ouvertes',
            $options->nameFilter !== null ? 'nom : ' . $options->nameFilter : null,
        ]);

        $termList = '';
        foreach ($terms as $i => $term) {
            $termList .= '<li>' . self::flag($i) . ' <span class="letter">' . self::FLAGS[$i % 12][0] . '</span> <b>' . $e($term) . '</b> <span class="num">' . number_format((int) ($s['termTotals'][$i] ?? 0), 0, ',', ' ') . '</span></li>';
        }

        $rows = '';
        $details = '';
        foreach ($result['matches'] as $n => $m) {
            $f = $m['file'];
            $cells = '';
            foreach ($terms as $i => $_) {
                $cells .= '<td class="num">' . (int) ($m['counts'][$i] ?? 0) . '</td>';
            }
            $rows .= '<tr><td><a href="#f' . $n . '">' . $e($f['name']) . '</a><div class="path">' . $e($f['relativePath']) . '</div></td>' . $cells . '<td class="num tot">' . (int) $m['total'] . '</td></tr>';

            $lines = '';
            foreach ($m['hits'] as $hit) {
                $lines .= '<li><span class="loc">' . $e($hit['location']) . '</span><span class="txt">' . self::highlight($hit['text'], $hit['spans']) . '</span></li>';
            }
            $more = $m['hitLines'] > count($m['hits']) ? '<p class="more">… et ' . ($m['hitLines'] - count($m['hits'])) . ' autres lignes.</p>' : '';
            $details .= '<section class="file" id="f' . $n . '"><h3>' . $e($f['name']) . '</h3><p class="path">' . $e($f['absolutePath']) . '</p>'
                . ($lines !== '' ? '<ol class="lines">' . $lines . '</ol>' : '<p class="more">Correspondance sur le nom du fichier.</p>') . $more . '</section>';
        }

        $notices = '';
        foreach ($result['notices'] as $n) {
            $notices .= '<li><b>' . $e($n['reason']) . '</b> <span class="path">' . $e($n['path']) . '</span> — ' . $e($n['message']) . '</li>';
        }

        $head = '';
        foreach ($terms as $i => $term) {
            $head .= '<th class="num" title="' . $e($term) . '">' . self::flag($i) . '</th>';
        }

        $fig = static fn (mixed $v): string => number_format((int) $v, 0, ',', ' ');

        return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Rapport Poseidon — ' . $e($query->input) . '</title><style>' . self::CSS . '</style></head><body>'
            . '<header class="band"><div class="wrap"><p class="brand">POSEIDON</p><h1>Rapport de recherche</h1>'
            . '<p class="meta">' . $e($date) . ' · racine <code>' . $e($s['root'] ?? $options->root) . '</code></p></div></header>'
            . '<main class="wrap">'
            . '<section><h2>Filtre</h2><p class="filter"><code>' . $e($query->input) . '</code></p><p class="opts">' . $e(implode(' · ', $optionsText)) . '</p><ul class="terms">' . $termList . '</ul></section>'
            . '<section class="figures"><div><strong>' . $fig($s['matched'] ?? 0) . '</strong><span>fichiers trouvés</span></div><div><strong>' . $fig($s['occurrences'] ?? 0) . '</strong><span>occurrences</span></div><div><strong>' . $fig($s['scanned'] ?? 0) . '</strong><span>fichiers analysés</span></div><div><strong>' . $fig($s['inArchives'] ?? 0) . '</strong><span>dans des archives</span></div><div><strong>' . $fig($s['notices'] ?? 0) . '</strong><span>ignorés</span></div></section>'
            . ($rows === '' ? '<p class="empty">Aucun fichier ne correspond.</p>' : '<section><h2>Fichiers</h2><table><thead><tr><th>Fichier</th>' . $head . '<th class="num">Total</th></tr></thead><tbody>' . $rows . '</tbody></table></section><section><h2>Preuves</h2>' . $details . '</section>')
            . ($notices === '' ? '' : '<section><h2>Fichiers ignorés</h2><ul class="notices">' . $notices . '</ul></section>')
            . ($s['truncated'] ?? false ? '<p class="empty">Résultats tronqués : la limite a été atteinte.</p>' : '')
            . '</main><footer class="wrap foot">Généré par Poseidon ' . $e(POSEIDON_VERSION) . ' en ' . $fig($s['durationMs'] ?? 0) . ' ms.</footer></body></html>';
    }

    private static function flag(int $i): string
    {
        return '<svg class="flag" viewBox="0 0 30 20" preserveAspectRatio="none" aria-hidden="true">' . self::FLAGS[$i % 12][2] . '</svg>';
    }

    /** @param list<array{0:int,1:int,2:int}> $spans */
    private static function highlight(string $text, array $spans): string
    {
        $chars = mb_str_split($text);
        $out = '';
        $pos = 0;
        foreach ($spans as [$start, $length, $term]) {
            if ($start < $pos) {
                continue;
            }
            $out .= htmlspecialchars(implode('', array_slice($chars, $pos, $start - $pos)), ENT_QUOTES, 'UTF-8');
            $out .= '<mark style="box-shadow:inset 0 -2px 0 ' . self::FLAGS[$term % 12][1] . '">' . htmlspecialchars(implode('', array_slice($chars, $start, $length)), ENT_QUOTES, 'UTF-8') . '</mark>';
            $pos = $start + $length;
        }

        return $out . htmlspecialchars(implode('', array_slice($chars, $pos)), ENT_QUOTES, 'UTF-8');
    }

    private const CSS = <<<'CSS'
:root{--abyss:#0b2340;--paper:#f6f8f9;--foam:#e9eff3;--ink:#14202e;--ink2:#3a4859;--mute:#566273;--line:#d3dce3;--mark:#ffe38a}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:15px/1.55 "Archivo","Segoe UI",system-ui,sans-serif;font-variant-numeric:tabular-nums}
code,.path,.loc,.num{font-family:"JetBrains Mono",ui-monospace,Consolas,monospace}
.wrap{max-width:1040px;margin:0 auto;padding:0 28px}
.band{background:var(--abyss);color:#f3f1e9;padding:28px 0 32px}.brand{margin:0;font-weight:800;letter-spacing:.06em;font-size:14px;color:#b3c3d8}
h1{margin:6px 0 8px;font-size:34px;font-weight:800;letter-spacing:-.01em}.meta{margin:0;color:#b3c3d8}.meta code{color:#f3f1e9}
h2{font-size:20px;margin:36px 0 12px;color:var(--abyss)}h3{margin:0 0 2px;font-size:16px}
.filter code{font-size:16px;background:var(--foam);border:1px solid var(--line);border-radius:4px;padding:3px 8px}.opts{color:var(--ink2);margin:8px 0}
.terms{list-style:none;padding:0;display:flex;flex-wrap:wrap;gap:8px 20px}.terms li{display:flex;align-items:center;gap:7px}.letter{font-family:ui-monospace,monospace;font-size:11px;color:var(--mute)}
.flag{width:22px;height:15px;border-radius:1.5px;box-shadow:0 0 0 1px rgba(20,32,46,.22);vertical-align:middle}
.figures{display:grid;grid-template-columns:repeat(5,1fr);border:1px solid var(--line);border-radius:8px;background:#fff;margin-top:24px}
.figures div{padding:14px 16px;border-right:1px solid #e6ecf0}.figures div:last-child{border:0}.figures strong{display:block;font-size:26px;line-height:1.1}.figures span{color:var(--mute);font-size:13px}
table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--line);font-size:14px}th,td{padding:8px 10px;border-bottom:1px solid #e6ecf0;text-align:left;vertical-align:top}
th{background:var(--foam);font-size:12px;letter-spacing:.05em;text-transform:uppercase;color:var(--mute)}.num{text-align:right}.tot{font-weight:700}
a{color:var(--abyss)}.path{font-size:12px;color:var(--mute);overflow-wrap:anywhere;margin:2px 0 0}
.file{background:#fff;border:1px solid var(--line);border-radius:8px;padding:14px 16px;margin-bottom:12px;break-inside:avoid}
.lines{list-style:none;padding:0;margin:10px 0 0;font-size:13px}.lines li{display:grid;grid-template-columns:96px 1fr;gap:10px;padding:2px 0}
.loc{color:var(--mute);font-size:11.5px;text-align:right}.txt{overflow-wrap:anywhere}mark{background:var(--mark);color:var(--ink);border-radius:2px;padding:0 1px}
.more,.empty{color:var(--ink2);font-style:italic}.notices{padding-left:18px;color:var(--ink2);font-size:14px}.foot{color:var(--mute);font-size:13px;padding:28px 28px 40px}
@media (max-width:720px){.figures{grid-template-columns:1fr 1fr}.lines li{grid-template-columns:1fr}.loc{text-align:left}}
@media print{body{background:#fff}.band{background:#fff;color:var(--ink);border-bottom:3px solid var(--abyss)}.brand,.meta{color:var(--mute)}.meta code{color:var(--ink)}
a{text-decoration:none}.file,table{border-color:#bbb}mark{-webkit-print-color-adjust:exact;print-color-adjust:exact}.flag{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
CSS;
}
