# Stockage de données

Poseidon n'a pas de serveur de base de données. Il stocke trois choses, toutes dans `var/` (ignoré par Git) et toutes reconstructibles.

| Stockage | Emplacement | Format | Contenu | Reconstruction |
| --- | --- | --- | --- | --- |
| Cache de texte | `var/cache/ab/<clé>.gz` | `Document` sérialisé, gzip | Texte extrait des PDF/Office | Automatique à la lecture suivante |
| Index plein texte | `var/index/<sha1(racine)>.sqlite` | SQLite (WAL) | Fichiers connus + trigrammes du texte | `poseidon index <racine> --rebuild` |
| Clé de signature | `var/secret.key` | 64 caractères hexadécimaux | Clé HMAC des identifiants de fichiers | Régénérée si absente (les anciens liens deviennent invalides) |

## Modèle de l'index (SQLite)

```
meta (key TEXT PK, value TEXT)                     version, root, built_at
files (
  id INTEGER PK,
  disk TEXT, chain TEXT,                           chemin disque + chaîne d'archives (JSON) — UNIQUE (disk, chain)
  size INTEGER, mtime INTEGER,                     fraîcheur
  status TEXT,                                     ok | notext | notice
  reason TEXT, message TEXT,                       avertissement mémorisé (status = notice)
  generation INTEGER                               dernière indexation complète qui l'a vu
)
archive_notices (archive TEXT, reason, path, message)    avertissements produits en ouvrant une archive
content : FTS5 (text) — tokenize 'trigram remove_diacritics 1', content '', contentless_delete 1
          rowid = files.id
```

| Relation / contrainte | Détail |
| --- | --- |
| `content.rowid` = `files.id` | Le texte d'un fichier `ok` ; absent pour `notext` et `notice` |
| `UNIQUE (disk, chain)` | Un fichier ou une entrée d'archive n'apparaît qu'une fois |
| `archive_notices.archive` | Clé `disque␟chaîne` de l'archive (préfixe pour les archives imbriquées) |
| Index SQL | `archive_notices_key` sur `archive` |

## Cycle de vie

| Événement | Effet |
| --- | --- |
| `poseidon index` / bouton « Indexer » | Nouvelle génération ; fichiers inchangés marqués, nouveaux/modifiés extraits, disparus supprimés |
| Recherche avec index | Fichiers nouveaux ou modifiés ajoutés au passage (génération 0, confirmée à la prochaine indexation complète) |
| `--delete` / bouton × | Fichier SQLite supprimé |

Détails : [index-plein-texte.md](../02-technique/backend/index-plein-texte.md).
