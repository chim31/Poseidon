# Socle transversal

Briques partagées par le moteur, l'API et la CLI.

## Sommaire

- [Amorçage et autoload](#amorçage-et-autoload)
- [Configuration](#configuration)
- [Chemins](#chemins)
- [Sécurité](#sécurité)
- [Gestion des erreurs et avertissements](#gestion-des-erreurs-et-avertissements)
- [Cache](#cache)
- [Processus externes](#processus-externes)
- [Texte et encodages](#texte-et-encodages)
- [Journalisation](#journalisation)
- [Internationalisation](#internationalisation)

## Amorçage et autoload

`bootstrap.php` définit `POSEIDON_ROOT`, `POSEIDON_VERSION` et un autoloader PSR-4 : `Poseidon\Search\Query` → `src/Search/Query.php`. Il est inclus par `public/index.php`, `bin/poseidon` et `tests/run.php`.

## Configuration

`Poseidon\Config::load()` lit `config/poseidon.php`, puis fusionne `config/poseidon.local.php` s'il existe. `Config::get('zip.max_depth')` accède aux clés imbriquées ; `Config::with([...])` crée une variante (tests). Clés et variables d'environnement : [démarrage § 4](../01-general/demarrage.md#4-configuration).

## Chemins

`Poseidon\Support\Path` est le seul endroit qui manipule les chemins.

| Méthode | Effet | Exemple |
| --- | --- | --- |
| `normalize()` | Forme interne avec `/`, UNC en `//`, lecteur en majuscule, `.`/`..` résolus, guillemets, `file://`, `smb://` | `c:\Docs\..\X` → `C:/X` |
| `display()` | Forme native pour l'affichage | `//srv/a` → `\\srv\a` |
| `toNative()` | Forme pour le système de fichiers courant | |
| `relativeTo()` / `isInside()` | Relatif à une racine, insensible à la casse pour Windows/UNC | |
| `extension()` | Extension en minuscules | |

Sur un UNC, `..` ne remonte jamais au-dessus de `\\serveur\partage`.

## Sécurité

| Mesure | Où | Détail |
| --- | --- | --- |
| Racines autorisées | `RootGuard` | Liste blanche ; vérifiée à la recherche **et** à chaque ouverture de fichier |
| Identifiants signés | `Support\Token`, `Source::id()` | HMAC-SHA256 tronqué (128 bits) avec `var/secret.key` (généré au premier lancement, `0600`, ignoré par Git) |
| Authentification | `Http\Kernel` | `POSEIDON_PASSWORD`, comparaison `hash_equals`, délai aléatoire, `session_regenerate_id`, cookie `HttpOnly` + `SameSite=Strict` (+ `Secure` en HTTPS) |
| Anti-CSRF | `Http\Kernel` | Toute l'API exige l'en-tête `X-Poseidon: 1` (impossible à poser depuis un site tiers sans pré-requête CORS, refusée), sauf les liens de téléchargement à identifiant signé |
| Anti-DNS-rebinding | `Http\Kernel` | Sans mot de passe : seuls `localhost`, `127.0.0.1`, `::1` et `allowed_hosts` (`POSEIDON_HOSTS`) sont servis, sinon 421 |
| En-têtes | `Http\Kernel` | CSP `default-src 'self'`, `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer` ; fichiers servis avec `CSP: sandbox` |
| Exports | `Export\Writer` | Toute cellule CSV (en-têtes compris) commençant par `= + - @ \| %`, même après des espaces, préfixée d'une apostrophe |
| Archives | `ZipWalker`, `OfficeArchive` | Profondeur, taille, volume, ratio, nombre d'entrées bornés ; parties XML Office bornées (150 Mo, ratio 200) |
| PDF (lecteur PHP) | `PdfExtractor` | Décompression bornée : 50 Mo par flux, 200 Mo par document |
| Tableurs | `SpreadsheetExtractor` | Colonnes ≤ 16 384, lignes ≤ 1 048 576 |
| Diff | `Search\Diff` | Au-delà de 1 000 différences, repli grossier (mémoire bornée) |
| Exécution externe | `Support\Process` | `proc_open` avec tableau d'arguments (pas de shell), délai maximal |

## Gestion des erreurs et avertissements

- Un problème **sur un fichier** lève `UnreadableFileException` avec une raison (`protected`, `denied`, `corrupt`, `too_large`, `timeout`, `limit`, `unsupported`). Le moteur la convertit en `Notice` et **continue** : un fichier ne bloque jamais un parcours.
- Un problème **sur la requête** (racine introuvable, regex invalide, chemin interdit) lève `InvalidArgumentException` ou `DomainException`, converties en 400 / 403 par l'API et en message `Erreur :` (code 2) par la CLI.
- Les autres exceptions donnent un 500 et sont journalisées.

## Cache

`ExtractorRegistry` met en cache le `Document` extrait des formats coûteux (PDF, DOCX, XLSX, ODS, ODT, ODP, PPTX, DOC, XLS, PPT) :

- clé : `sha1(chemin disque | chaîne d'archives | taille | date)` → un fichier modifié est relu ;
- stockage : `var/cache/ab/<clé>.gz` (sérialisation gzip, désérialisation restreinte à la classe `Document`) ;
- désactivation : `cache_dir` vide ; purge : supprimer les sous-dossiers de `var/cache`.

## Processus externes

`Support\Process::run()` lance un binaire sans shell, lit stdout/stderr en non bloquant et **tue** le processus au-delà du délai. `Process::which()` cherche un exécutable dans le `PATH` (avec `.exe`, `.bat`, `.cmd` sous Windows).

## Texte et encodages

`Support\Text` : `toUtf8()` (BOM UTF-8/16, UTF-8 valide, sinon Windows-1252), `looksBinary()`, `stripXml()` / `xmlDecode()`, `lines()`, conversions de colonnes Excel (`A` ↔ 0, `AB` ↔ 27).

## Journalisation

Pas de journal applicatif à ce stade : les erreurs internes passent par `error_log()` (sortie du serveur PHP). Un journal d'audit (qui a cherché quoi) est prévu avec les comptes (phase 8).

## Internationalisation

Interface, messages et documentation en français. Les nombres et dates sont formatés en `fr-FR`. Une traduction anglaise est envisageable en isolant les chaînes (non planifié).
