# Moteur de recherche

`grep -R -i | wc`, version Poseidon : filtre, comptage, preuves, et comparaison de fichiers.

## `Search\Query`

Construit à partir de la saisie : `new Query($input, $mode, $caseSensitive, $wholeWord, $ignoreAccents)` ou `Query::fromArray($_GET)`.

| Mode | Termes | Correspondance d'un fichier |
| --- | --- | --- |
| `phrase` | la saisie entière | la phrase apparaît (espaces multiples tolérés) |
| `all` (défaut) | saisie découpée en mots | **tous** les termes apparaissent |
| `any` | saisie découpée en mots | **au moins un** terme apparaît |
| `regex` | la saisie = motif PCRE | le motif correspond (validé à la construction) |

Découpage : espaces ; `"…"` ou `« … »` gardent une expression groupée ; ponctuation collée retirée (`contrat,` → `contrat`) ; doublons supprimés.

Compilation (une regex par terme, drapeau `u`, `i` sauf casse respectée) :

- **accents ignorés** : chaque lettre devient une classe (`e` → `[eèéêëēė]`), ce qui garde les positions exactes pour le surlignage ;
- **mot entier** : `(?<![\p{L}\p{N}_])…(?![\p{L}\p{N}_])` (fonctionne avec les lettres accentuées, contrairement à `\b`).

## `Search\Matcher`

`match(Document): MatchResult`

1. Pré-filtre sur le texte complet : en mode `all`, abandon dès qu'un terme manque ; abandon si aucun terme n'est présent.
2. Comptage ligne par ligne, avec les positions (`[début, longueur, terme]` en caractères).
3. Conservation des `max_hits_per_file` premières lignes-preuves ; les lignes de plus de 400 caractères sont recadrées autour de la première correspondance.

`MatchResult` : `counts` (par terme), `hits`, `hitLines`, `total()`.

## `Search\SearchOptions`

`root`, `extensions`, `intoArchives`, `target` (`content` / `name` / `both`), `maxResults`, `nameFilter` (motif shell `*` `?`, insensible à la casse).

## `Search\SearchEngine`

`search(Query, SearchOptions): Generator<array>` émet les événements `start`, `match`, `notice`, `progress` (toutes les 250 ms), `done` (résumé : analysés, trouvés, dans des archives, occurrences, totaux par terme, extensions, avertissements, tronqué, durée).

`collect()` agrège tout en mémoire (CLI, tests, extraction).

## `Search\Diff`

| Méthode | Rôle |
| --- | --- |
| `lines(array $a, array $b)` | Opérations `[' '|'-'|'+', ligne]` ; préfixe/suffixe communs retirés puis algorithme de Myers O(ND) ; au-delà de 25 millions de cellules, bloc supprimé + bloc ajouté |
| `hunks(array $ops, int $context = 3)` | Blocs avec contexte et en-têtes `@@ -a,n +b,m @@` |
| `unified($nomA, $nomB, $hunks)` | Texte au format `git diff` |

La comparaison porte sur le **texte extrait** : on peut comparer un PDF et un DOCX, deux versions d'un tableur, etc.

## Exemple

```php
$engine = new SearchEngine(Config::load());
$query = new Query('contrat "préavis de 30 jours"', Query::ALL);
foreach ($engine->search($query, SearchOptions::fromArray(['root' => 'D:\\Juridique'])) as $event) {
    if ($event['type'] === 'match') {
        printf("%s : %d\n", $event['file']['relativePath'], $event['total']);
    }
}
```
