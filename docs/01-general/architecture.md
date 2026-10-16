# Architecture

Comment Poseidon est construit : composants, flux de données, choix structurants et contraintes non fonctionnelles.

## Sommaire

1. [Vue d'ensemble](#1-vue-densemble)
2. [Composants](#2-composants)
3. [Flux d'une recherche](#3-flux-dune-recherche)
4. [Flux d'une extraction](#4-flux-dune-extraction)
5. [Arborescence du dépôt](#5-arborescence-du-dépôt)
6. [Choix structurants](#6-choix-structurants)
7. [Contraintes non fonctionnelles](#7-contraintes-non-fonctionnelles)

## 1. Vue d'ensemble

```
            Navigateur (app.html + app.js)                Terminal
                      │  fetch / NDJSON                      │ argv
                      ▼                                      ▼
              public/index.php ──► Http\Kernel        Cli\Application ◄── bin/poseidon(.bat)
                                         │                   │
                                         └─────────┬─────────┘
                                                   ▼
                                       Search\SearchEngine  ── Search\Query / Matcher
                                       │        │       │
                          Filesystem\Scanner  Extractor\  Export\DataExtractor ── Writer / XlsxWriter
                          (ls -R)   │         Registry           Search\Diff
                                    ▼           │  (cache var/cache)
                          Archive\ZipWalker     ├─ PlainText, Csv, Rtf
                          (ZIP dans ZIP)        ├─ Word, Spreadsheet, OpenDocument (ZIP+XML)
                                                ├─ Pdf (pdftotext | PHP)
                                                └─ LegacyOffice (antiword | chaînes)
                                    ▲
               Filesystem\RootGuard (racines autorisées, UNC, montages)
               Filesystem\FileLocator + Support\Token (identifiants signés)
```

Poseidon est un programme PHP sans framework. Le même moteur sert deux façades : l'API HTTP (pour l'interface web) et la ligne de commande.

## 2. Composants

| Composant | Rôle | Document |
| --- | --- | --- |
| `Filesystem\Scanner` | Parcours récursif en flux (`ls -R`), dossiers exclus, illisibles signalés | [parcours-et-archives.md](../02-technique/backend/parcours-et-archives.md) |
| `Archive\ZipWalker`, `ZipHandle` | Entrées de ZIP, récursion, protections anti-bombe, entrées chiffrées | idem |
| `Filesystem\RootGuard` | Normalise la racine, applique la liste blanche, mappe les partages | idem |
| `Filesystem\FileLocator` | Retrouve un fichier depuis un identifiant signé | idem |
| `Extractor\*` | Texte (et tableaux) de chaque format, cache | [extracteurs.md](../02-technique/backend/extracteurs.md) |
| `Search\Query`, `Matcher` | Compilation du filtre, comptage, lignes-preuves (`grep`) | [moteur-de-recherche.md](../02-technique/backend/moteur-de-recherche.md) |
| `Search\SearchEngine` | Orchestration, flux d'événements | idem |
| `Search\Diff` | Comparaison Myers, format unifié | idem |
| `Export\*` | Extraction de données et écriture des documents | [extraction-de-donnees.md](../02-technique/backend/extraction-de-donnees.md) |
| `Http\Kernel` | Routes, sécurité, flux NDJSON, `/projet/` | [http-et-cli.md](../02-technique/backend/http-et-cli.md) |
| `Cli\Application` | Commandes `search`, `extract`, `diff`, `cat`, `serve`, `info`, `demo` | idem |
| `public/app.html` + `assets/` | Interface « Pavillons de signaux » | [interface.md](../02-technique/interface.md) |

## 3. Flux d'une recherche

1. L'interface envoie `GET /api/search?root=…&q=…&mode=…`.
2. `Kernel` construit `Query` (termes, motifs PCRE) et `SearchOptions` (racine, extensions, archives, cible).
3. `SearchEngine` résout la racine via `RootGuard` (normalisation, liste blanche, existence) **avant** d'ouvrir le flux : une erreur reste une réponse JSON propre.
4. `Scanner` produit des `Source` (fichiers ou entrées d'archive) et des `Notice` (illisible, protégé…).
5. Pour chaque `Source` : filtre de nom → `ExtractorRegistry` (cache d'abord) → `Matcher` (pré-filtre sur le texte complet, puis comptage ligne par ligne).
6. Chaque fichier correspondant est émis immédiatement (`{"type":"match",…}`) ; une progression part toutes les 250 ms ; un résumé termine le flux.
7. L'interface affiche au fil de l'eau ; si l'utilisateur annule, la connexion se ferme et le serveur arrête le parcours (`connection_aborted()`).

## 4. Flux d'une extraction

1. `POST /api/extract` avec les paramètres de la recherche + la spécification (`extract`, `columns`, `format`…).
2. `DataExtractor` relance la recherche (le cache rend l'opération rapide), relit chaque document trouvé via `FileLocator`, applique le mode (occurrences, lignes, colonnes, fichiers).
3. `Writer` sérialise (CSV avec BOM et `;`, XLSX natif, JSON, TXT, MD) ; la réponse est un téléchargement.

## 5. Arborescence du dépôt

```
Poseidon/
├── bootstrap.php            Autoloader PSR-4 + constantes
├── bin/poseidon(.bat)       Ligne de commande
├── poseidon.bat / .sh       Lanceurs de l'interface web
├── config/poseidon.php      Configuration (surcharge : poseidon.local.php)
├── public/                  Racine web : index.php, app.html, assets/
├── src/                     Code PHP (namespace Poseidon\)
│   ├── Archive/  Cli/  Demo/  Export/  Extractor/  Filesystem/  Http/  Search/  Support/
├── tests/run.php            Suite de tests
├── var/                     Cache et clé secrète (non suivis)
├── docs/                    Documentation, pages web de suivi
├── context.md  PRODUCT.md  DESIGN.md
└── .impeccable/             Contrat de direction et configuration du design
```

## 6. Choix structurants

| Choix | Pourquoi | Alternative écartée |
| --- | --- | --- |
| Générateurs PHP de bout en bout | Mémoire constante, premiers résultats immédiats | Collecte complète puis affichage |
| Entrées de ZIP lues en mémoire, ZIP imbriqués via fichier temporaire | `ZipArchive` ne sait ouvrir qu'un fichier ; les fichiers ordinaires ne touchent jamais le disque | Extraction complète de l'archive |
| Analyse XML par expressions régulières | Pas d'ext-dom ; formats Office réguliers | `DOMDocument`, `XMLReader` |
| Identifiants signés plutôt que chemins en clair | Empêche la lecture arbitraire via l'API | Chemin en paramètre |
| NDJSON plutôt que WebSocket ou SSE | Simple, compatible `fetch`, annulable | SSE (GET uniquement), WebSocket (serveur dédié) |
| Pas de base de données | Rien à installer ; le cache disque suffit à ce stade | SQLite dès la v0.1 (prévu en phase 5 pour l'index) |

## 7. Contraintes non fonctionnelles

| Domaine | Mesure en place |
| --- | --- |
| Performance | Flux, pré-filtre sur texte complet, cache d'extraction, dossiers exclus, plusieurs processus pour le serveur intégré sous Linux |
| Sécurité | `RootGuard`, identifiants HMAC, mot de passe, session `HttpOnly`/`SameSite=Strict`, en-tête anti-CSRF `X-Poseidon`, CSP `self`, téléchargements en `sandbox`, neutralisation des formules CSV |
| Robustesse | Exceptions par fichier converties en avertissements, délais (`pdf_timeout`), limites ZIP, boucles de liens symboliques détectées |
| Portabilité | PHP ≥ 8.2, chemins normalisés, lanceurs Windows et Unix |
| Évolutivité | Index FTS5, parallélisation et CI prévus en phase 5 ([roadmap](../web/roadmap.html)) |
