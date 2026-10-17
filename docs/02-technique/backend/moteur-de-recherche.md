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
| `near` | saisie découpée en mots | **tous** les termes, à moins de `distance` mots d'une occurrence du premier terme, **même sur plusieurs lignes** |

Options supplémentaires : `distance` (proximité, 5 par défaut, 0 à 100) et `fuzzy` (recherche floue).

### Recherche floue

Avec `fuzzy`, chaque terme d'un seul mot (3 lettres ou plus, hors regex) est comparé aux **mots** du texte, sans casse ni accents, par la distance de Damerau-Levenshtein restreinte (`Query::typoDistance`) : une lettre en trop, en moins, remplacée ou **deux lettres inversées** comptent pour une faute. Tolérance : 0 faute sous 4 lettres, 1 faute de 4 à 7 lettres, 2 fautes à partir de 8. `contart` trouve `contrat`, `résilation` trouve `résiliation`. L'index n'est pas utilisé en mode flou.

### Points d'entrée communs

`Query::find($texte, $terme)` (positions en octets), `Query::count()`, `Query::isFuzzy()` : le `Matcher`, l'aperçu, l'extraction et la recherche dans les noms passent tous par là, pour rester cohérents.

Découpage : espaces ; `"…"` ou `« … »` gardent une expression groupée ; ponctuation collée retirée (`contrat,` → `contrat`) ; doublons supprimés.

Compilation (une regex par terme, drapeau `u`, `i` sauf casse respectée) :

- **accents ignorés** : chaque lettre devient une classe (`e` → `[eèéêëēė]`), ce qui garde les positions exactes pour le surlignage ;
- **mot entier** : `(?<![\p{L}\p{N}_])…(?![\p{L}\p{N}_])` (fonctionne avec les lettres accentuées, contrairement à `\b`).

## `Search\Matcher`

`match(Document): MatchResult`

1. Pré-filtre sur le texte complet : en modes `all` et `near`, abandon dès qu'un terme manque ; abandon si aucun terme n'est présent.
2. `spans(Document)` : toutes les correspondances par ligne (`[début, longueur, terme, octet]`) ; en mode proximité, seules les occurrences groupées sont gardées (positions comptées en mots depuis le début du document, lignes enchaînées).
3. Comptage à partir de ces positions.
4. Conservation des `max_hits_per_file` premières lignes-preuves ; les lignes de plus de 400 caractères sont recadrées autour de la première correspondance.

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
