# Façades HTTP et CLI

## `Http\Kernel`

Point d'entrée : `public/index.php` (contrôleur frontal ; avec le serveur intégré, les fichiers statiques existants sont servis directement).

| Étape | Détail |
| --- | --- |
| En-têtes de sécurité | Sur toutes les réponses |
| `/projet/…` | Pages de suivi `docs/web` (liste blanche d'extensions, confinement au dossier) |
| Hors `/api/` | `public/app.html` |
| Session | Démarrée seulement si un mot de passe est configuré ; libérée (`session_write_close`) avant tout traitement long pour ne pas bloquer les autres requêtes |
| `POST` | Exige `X-Poseidon: 1` |
| Routage | `match ([$method, $path])` → méthode privée |
| Erreurs | `HttpError` (code choisi), `UnreadableFileException` (422), `InvalidArgumentException` (400), `DomainException` (403), autres (500 + `error_log`) |

Recherche en flux : la racine est validée avant les en-têtes NDJSON ; tampons de sortie vidés ; `flush()` après chaque événement ; arrêt si `connection_aborted()`.

Routes : voir [api.md](../../01-general/api.md).

## `Cli\Application`

`bin/poseidon` (Unix) et `bin/poseidon.bat` (Windows) appellent `Application::run($argv)`.

| Commande | Alias | Méthode |
| --- | --- | --- |
| `search <racine> <filtre>` | `s` | Sortie façon grep, couleurs si terminal (`NO_COLOR` respecté) |
| `extract <racine> <filtre> --out=` | `x` | `DataExtractor` + `Writer` ; format déduit de l'extension |
| `diff <A> <B>` | `d` | `Diff` coloré ; code 1 si différent |
| `cat <fichier>` | | Texte extrait avec emplacements |
| `serve [--host] [--port] [--workers] [--open]` | | `php -S` ; port suivant libre si le port demandé est occupé ; `--open` ouvre le navigateur une fois `/api/info` répond « Poseidon » ; 4 processus sous Linux ; avertit si ouvert sans mot de passe |
| `info` | | Version, moteur PDF, racines, formats |
| `demo [dossier]` | | Génère les échantillons synthétiques |

Options : `--clé=valeur`, `--drapeau`, `-l`. Les options en tirets sont aussi disponibles en camelCase pour `ExtractionSpec` (`--header-row` → `headerRow`).

## Lanceurs

| Fichier | Rôle |
| --- | --- |
| `poseidon.bat` | Windows : vérifie PHP et les extensions, ouvre le navigateur, lance `serve` (port en argument) |
| `bin\poseidon.bat` | Windows : CLI, transmet tous les arguments |
| `poseidon.sh` | Linux/macOS/WSL : équivalent de `poseidon.bat` |

PHP est cherché dans `%POSEIDON_PHP%`, puis `php\php.exe` à côté du projet (version portable), puis le `PATH`.
