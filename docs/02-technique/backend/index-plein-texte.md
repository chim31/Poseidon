# Index plein texte

Accélère les recherches répétées sur une même arborescence : Poseidon ne relit que les fichiers qui **peuvent** contenir le filtre.

## Principe

| Élément | Choix |
| --- | --- |
| Stockage | Une base SQLite par racine : `var/index/<sha1(racine)>.sqlite` (configurable : `index_dir`) |
| Texte | Table virtuelle FTS5, tokeniseur **trigramme** avec `remove_diacritics` : recherche de fragments (`contrat` trouve `contrats`), sans accents, sans casse |
| Sans contenu | `content=''` + `contentless_delete=1` : le texte n'est pas dupliqué, seul l'index des trigrammes est stocké |
| Fichiers | Table `files` : chemin disque, chaîne d'archives, taille, date, état (`ok`, `notext`, `notice`), raison et message d'avertissement |
| Avertissements d'archives | Table `archive_notices` : entrées chiffrées, limites… rejoués quand l'archive n'est pas rouverte |
| Prérequis | `pdo_sqlite` avec SQLite ≥ 3.43 ; sinon Poseidon fonctionne sans index (`Index::available()`) |

## L'index ne décide jamais seul

L'index fournit un **sur-ensemble** de candidats ; le `Matcher` vérifie toujours le texte réel. Les options fines (casse, accents respectés, mot entier, phrase) sont appliquées par le `Matcher` : le résultat est identique avec ou sans index, ce que la suite de tests vérifie sur 11 requêtes.

| Situation | Comportement |
| --- | --- |
| Fichier inchangé (même taille, même date) et non candidat | Compté comme analysé, **non relu** |
| Fichier inchangé et candidat | Relu (depuis le cache de texte), vérifié |
| Fichier nouveau ou modifié | Lu, vérifié, **ajouté à l'index** au passage |
| Archive inchangée sans candidat | **Non ouverte** ; ses avertissements sont rejoués (filtrés comme le parcours) |
| Fichier inchangé connu comme protégé | Avertissement rejoué sans relecture |
| Mode regex, ou fragment de moins de 3 caractères | Index inutilisable : parcours complet (résumé `index.usable = false`) |
| Recherche dans les noms seulement | Index non utilisé (aucune lecture de contenu) |

Traduction du filtre : chaque terme est découpé en fragments de lettres/chiffres, mis entre guillemets et reliés par `AND` ; les termes sont reliés par `AND` (modes ET et phrase) ou `OR` (mode OU).

## Classes

| Classe | Rôle |
| --- | --- |
| `Index\Index` | `find()` (racine ou dossier parent), `create()`, `delete()`, `fresh(Source)`, `store()`, `candidates(Query)`, `archiveNotices()`, `prune()`, `stats()` |
| `Index\Indexer` | Construction et mise à jour complètes : parcours de tout, extraction des seuls fichiers nouveaux/modifiés, suppression des fichiers disparus (générations) |
| `Search\SearchEngine` | Utilise l'index s'il existe ; option `useIndex` (`index=0` dans l'API, `--no-index` en CLI) |
| `Filesystem\Scanner`, `Archive\ZipWalker` | Rappel `descend(Source)` : décide d'ouvrir ou non une archive |

## Utilisation

```sh
php bin/poseidon index ~/Documents            # construire / mettre à jour
php bin/poseidon index ~/Documents --status   # état
php bin/poseidon index ~/Documents --rebuild  # tout reconstruire
php bin/poseidon index ~/Documents --delete   # supprimer
php bin/poseidon search ~/Documents kraken    # utilise l'index automatiquement
```

Dans l'interface : bloc **Index** du rail (« Indexer ce dossier », « Mettre à jour l'index », suppression) ; la statistique « Non relus (index) » apparaît après une recherche indexée.

## Mesures

Voir [performances.md](../../01-general/performances.md).
