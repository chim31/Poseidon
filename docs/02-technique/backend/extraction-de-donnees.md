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
| `matches` | occurrence trouvée | [Fichier, Chemin], Emplacement, Terme, Valeur, Groupe 1…n (groupes capturés d'une regex) |
| `lines` | ligne contenant le filtre | [Fichier, Chemin], Emplacement, Ligne |
| `columns` | ligne de tableau correspondante | [Fichier, Chemin], Feuille, Ligne, colonnes choisies |
| `files` | fichier trouvé | Fichier, Chemin relatif, Chemin absolu, Occurrences, une colonne par terme |

Résolution d'une colonne en mode `columns` : d'abord par nom d'en-tête (insensible à la casse), sinon par lettre. Une colonne absente d'un fichier reste vide. Les colonnes de plusieurs fichiers s'additionnent dans l'ordre de première apparition.

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
