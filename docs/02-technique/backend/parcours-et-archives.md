# Parcours et archives

`ls -R` version Poseidon : de la racine saisie jusqu'aux entrées de ZIP imbriqués.

## `Filesystem\RootGuard`

| Méthode | Rôle |
| --- | --- |
| `resolveRoot(string $input): string` | Normalise, applique `network_mounts`, refuse un UNC sous Linux non monté (message explicite), vérifie la liste blanche, l'existence et la lisibilité ; retourne le chemin réel |
| `assertAllowed(string $path)` | Lève `DomainException` si le chemin sort des racines autorisées |
| `allowedRoots()`, `isRestricted()` | Pour l'API et le navigateur de dossiers |

## `Filesystem\Scanner`

`scan(string $root, array $extensions = [], bool $intoArchives = true): Generator<Source|Notice>`

- Parcours **itératif** avec une pile (pas de récursion PHP), ordre alphabétique, dossiers exclus (`excluded_dirs`).
- Boucles de liens symboliques évitées par l'ensemble des `realpath` visités.
- Dossier illisible → `Notice(denied)` ; fichier trop gros → `Notice(too_large)` ; fichier illisible → `Notice(denied)`.
- Une racine qui est un fichier est acceptée (recherche dans un seul fichier ou une seule archive).
- Les archives sont produites elles-mêmes (pour la recherche par nom) puis parcourues par `ZipWalker`.

## `Filesystem\Source`

Un fichier à analyser : `diskPath` (fichier réel ou archive racine) + `innerPath` (chaîne d'entrées). Fournit `name()`, `extension()`, `absolutePath()` (`C:\a\b.zip › dossier/c.docx`), `relativePath()`, `archiveDepth()`, `bytes()` (paresseux), `cacheKey()`, `id()` (jeton signé) et `toArray()` (format JSON de l'API).

## `Archive\ZipWalker`

`walk(Source $archive, array $filter): Generator<Source|Notice>`

| Protection | Configuration | Effet |
| --- | --- | --- |
| Profondeur | `zip.max_depth` (5) | Archive au-delà non ouverte, `Notice(limit)` |
| Nombre d'entrées | `zip.max_entries` (20 000) | Archive ignorée |
| Taille d'une entrée | `zip.max_entry_size` (100 Mo) | Entrée ignorée |
| Ratio de compression | `zip.max_ratio` (200) au-delà de 1 Mo | Entrée ignorée (bombe ZIP probable) |
| Volume total | `zip.max_total_size` (1 Go) par archive racine | Suite de l'archive ignorée |
| Chiffrement | `encryption_method ≠ 0` | `Notice(protected)` (20 max par archive, puis un récapitulatif) |

Les entrées `__MACOSX/` et les dossiers sont ignorés. Les entrées dont l'extension n'est pas demandée ne sont pas lues (sauf les archives, toujours ouvertes pour descendre).

## `Archive\ZipHandle`

- `open(Source)` : ouvre une archive sur disque, ou une archive imbriquée via un fichier temporaire supprimé à la fermeture (`ZipArchive` n'ouvre que des fichiers).
- `readChain(diskPath, chain)` : lit une entrée au bout d'une chaîne `a.zip › b.zip › c.txt` (téléchargement, aperçu, extraction).
- Codes d'erreur `ZipArchive` traduits en messages français.

## `Filesystem\FileLocator`

`locate(string $id): Source` : vérifie la signature du jeton (`Token::verify`), revérifie les racines autorisées, contrôle l'existence et reconstruit la `Source` (avec lecteur de chaîne d'archives). `mimeType()` donne le type MIME de téléchargement.

## Exemple

```php
$scanner = new Scanner($config, new ZipWalker($config));
foreach ($scanner->scan('/srv/docs', ['pdf', 'docx']) as $item) {
    echo $item instanceof Notice ? "⚠ {$item->path}\n" : $item->relativePath() . "\n";
}
```
