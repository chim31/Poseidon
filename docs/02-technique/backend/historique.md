# Historique : instantanés de recherche et Git

Deux façons de voir ce qui a changé : entre deux recherches identiques, et dans l'historique Git d'un dossier.

## Instantanés de recherche (`History\SnapshotStore`)

À chaque recherche terminée (interface ou CLI), Poseidon garde un instantané : fichiers trouvés, compteurs par terme, taille et date de chaque fichier.

| Élément | Détail |
| --- | --- |
| Clé | compte + racine + filtre + options (formats, archives, cible, nom) : deux recherches identiques d'un même compte se comparent |
| Stockage | `var/snapshots/<sha1(clé)>/<AAAAMMJJ-HHMMSS-microsecondes>.json` (`snapshots_dir`), 20 derniers gardés |
| Comparaison | `compare()` : plus récent contre précédent (ou deux instantanés choisis) → `appeared`, `disappeared`, `changed` (compteur avant/après, fichier modifié), `unchanged` |
| Isolation | Un compte ne voit jamais les instantanés d'un autre |

| Où | Comment |
| --- | --- |
| Interface | Bande « Depuis la recherche du … : +N nouveaux · −M disparus · K modifiés » sous la synthèse → « Voir les changements » |
| CLI | `poseidon search <racine> <filtre> --changes` |
| API | Événement `{"type":"changes","comparison":…}` en fin de flux `/api/search` ; `GET /api/changes?…[&from=&to=]` |

## Historique Git (`Git\GitHistory`, `Git\HistorySearch`)

Lecture seule, sans shell, délais bornés ; `-c safe.directory=*` pour lire un dépôt appartenant à un autre compte (Docker).

| Fonction | Commande Git | Remarque |
| --- | --- | --- |
| `repository($chemin)` | — | Remonte jusqu'au dossier qui contient `.git` |
| `log($dépôt, $fichier)` | `git log --follow` | 50 derniers commits, renommages suivis |
| `show($dépôt, $rév, $fichier)` | `git show rév:fichier` | Contenu d'une version (taille bornée) |
| `parent($dépôt, $rév)` | `git rev-list --parents` | Vide pour un premier commit |
| `touched($dépôt, $chemin)` | `git log --name-only --diff-filter=AMR` | Commits et fichiers modifiés |
| `search($dépôt, $chemin, $texte)` | `git log -S` (ou `-G` regex) | Fichiers texte |

Identifiants de commit validés (`[0-9a-f]{7,40}`) : aucune injection d'option possible.

### Diff d'un commit sur un fichier

Poseidon compare le **texte extrait** de la version parente et de la version du commit, comme pour la comparaison de deux fichiers : un commit sur un DOCX, un XLSX ou un PDF montre les lignes de texte ajoutées et retirées, là où `git diff` afficherait « Binary files differ ». Les versions sont des sources **en mémoire** (`Source::inMemory()`), jamais relues sur le disque.

### Recherche dans l'historique (`HistorySearch`)

« Dans quels commits ce filtre a-t-il été ajouté ou retiré ? »

1. Fichiers texte : `git log -S` (rapide), puis comptage avant/après.
2. Documents (DOCX, XLSX, PDF, ODT, courriels…) : leur contenu est compressé, Git ne peut pas y chercher. Pour chacun des 100 derniers commits qui les modifient, Poseidon extrait le texte des deux versions et compare le nombre d'occurrences.

Résultat : commits (du plus récent au plus ancien) avec, par fichier, `avant → après`.

| Où | Comment |
| --- | --- |
| Interface | Aperçu → onglet **Historique** (commits du fichier, clic = diff) ; bouton **Git** de la synthèse (recherche dans l'historique du dossier) |
| CLI | `poseidon git-log <fichier>`, `poseidon git-search <racine> <filtre> [--mode=regex]` |
| API | `GET /api/git/log?id=`, `GET /api/git/diff?id=&commit=`, `GET /api/git/search?root=&q=` |
