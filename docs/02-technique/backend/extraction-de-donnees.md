# Extraction de données

Transformer une recherche en jeu de données, comme un `SELECT` avec mapping.

## Analogie

| Base de données | Poseidon |
| --- | --- |
| Table | Les fichiers trouvés sous la racine |
| `WHERE` | Le filtre (mots, phrase, motif) |
| `SELECT colonnes` | Le mode d'extraction et le mapping de colonnes |
| `DISTINCT` | Option « Supprimer les doublons » |
| Résultat | Un nouveau document CSV / XLSX / JSON / TXT / MD |

## `Export\ExtractionSpec`

| Champ | Valeurs | Rôle |
| --- | --- | --- |
| `mode` | `matches`, `lines`, `columns`, `files` | Quoi extraire |
| `format` | `csv`, `xlsx`, `json`, `txt`, `md` | Format de sortie |
| `columns` | lettres (`A`, `AB`) ou en-têtes (`Client`) | Mapping (mode `columns`) |
| `headerRow` | entier, 0 = aucune | Ligne d'en-tête des tableaux |
| `allRows` | booléen | Toutes les lignes, pas seulement celles qui correspondent |
| `withSource` | booléen | Colonnes Fichier + Chemin relatif |
| `unique` | booléen | Dédoublonnage (hors colonnes source) |

## Modes

| Mode | Une ligne par… | Colonnes |
| --- | --- | --- |
| `matches` | occurrence trouvée | [Fichier, Chemin], Emplacement, Terme, Valeur, puis **une colonne par groupe nommé** `(?<nom>…)` (ou Groupe 1…n pour des groupes non nommés) |
| `lines` | ligne contenant le filtre | [Fichier, Chemin], Emplacement, Ligne |
| `columns` | ligne de tableau correspondante | [Fichier, Chemin], Feuille, Ligne, colonnes choisies |
| `files` | fichier trouvé | Fichier, Chemin relatif, Chemin absolu, Occurrences, une colonne par terme |

Résolution d'une colonne en mode `columns` : d'abord par nom d'en-tête (insensible à la casse), sinon par lettre. Une colonne absente d'un fichier reste vide. Les colonnes de plusieurs fichiers s'additionnent dans l'ordre de première apparition.

## Groupes nommés → colonnes

`(?<montant>\d[\d ]*,\d{2}) ?(?<devise>€|EUR)` en mode regex produit les colonnes `montant` et `devise`. Syntaxes reconnues : `(?<nom>…)`, `(?P<nom>…)`, `(?'nom'…)`. Les groupes sont relus à la position exacte de chaque occurrence (les assertions arrière restent justes).

## Modèles d'extraction (`Export\TemplateStore`)

Un modèle enregistre un « SELECT » complet — filtre, mode, options de recherche, formats, extraction (mode, colonnes, format…) — **sans la racine**, pour être rejoué sur n'importe quel dossier.

| Action | Interface | CLI | API |
| --- | --- | --- | --- |
| Enregistrer | Volet Extraire → « Enregistrer ces réglages comme modèle » | `--save-template="Nom"` avec `extract` | `POST /api/templates` |
| Rejouer | Liste « Modèle » du volet Extraire | `extract <racine> --template="Nom" --out=…` | — (l'interface remplit le formulaire) |
| Lister / supprimer | Liste + × | `templates`, `templates --delete="Nom"` | `GET /api/templates`, `POST /api/templates/delete` |

Stockage : `var/templates/<nom>.json` (`templates_dir`). Les paramètres sont validés à l'enregistrement (regex invalide refusée). En CLI, les options passées en ligne de commande priment sur le modèle.

## Rapport (`Report\HtmlReport`)

Fichier HTML unique, sans script ni ressource externe : filtre et options, pavillons et totaux par terme, synthèse, tableau des fichiers, lignes-preuves surlignées, fichiers ignorés. Feuille d'impression incluse : « Imprimer → Enregistrer en PDF » donne un PDF propre. Interface : bouton **Rapport** ; CLI : `poseidon report <racine> <filtre> --out=rapport.html` ; API : `GET /api/report?…`.

## `Export\DataExtractor`

`run(Query, SearchOptions, ExtractionSpec): {columns, rows, notices, summary}` : relance la recherche (rapide grâce au cache), relit chaque document trouvé, applique le mode, dédoublonne, aligne toutes les lignes sur la largeur des colonnes.

## `Export\Writer` et `XlsxWriter`

| Format | Particularités |
| --- | --- |
| CSV | UTF-8 avec BOM, séparateur `;` (ouverture directe dans Excel en français), formules neutralisées |
| XLSX | Écrit à la main (SpreadsheetML + `ZipArchive`) : en-tête abyssal en gras, ligne figée, filtre automatique, nombres reconnus |
| JSON | Tableau d'objets `{colonne: valeur}` |
| TXT | Valeurs séparées par des tabulations |
| MD | Tableau Markdown |

## Exemples

| Besoin | Réglages |
| --- | --- |
| Toutes les références `AB123456` de l'arborescence | filtre `[A-Z]{2}\d{6}`, mode regex, extraire les occurrences, dédoublonner, XLSX |
| Clients résiliés et montants | filtre `résilié`, extraire des colonnes `Client, Montant`, CSV |
| Inventaire des fichiers mentionnant un projet | filtre `Néréide`, extraire la liste des fichiers |
| Montants avec leur devise | regex `(\d[\d ]*,\d{2}) ?(€|EUR)` → colonnes Groupe 1 (montant) et Groupe 2 (devise) |
