# API HTTP

L'interface web consomme cette API ; elle est aussi utilisable par un script (`curl`, PowerShell…).

## Conventions

| Sujet | Règle |
| --- | --- |
| Base URL | `http://127.0.0.1:8080` (lanceurs) ou l'URL du serveur |
| Versionnement | aucun préfixe pour la v0.x ; `/api/v1` sera introduit avant la v1.0 |
| Format | JSON UTF-8, sauf `/api/search` (NDJSON) et les téléchargements |
| Authentification | comptes (`var/users.json`) ou mot de passe partagé (`POSEIDON_PASSWORD`) : `POST /api/login`, puis cookie de session `poseidon` ; sans les deux : accès local |
| Rôles | `admin` (index, suppression de modèles, audit) et `lecteur` ; un compte peut être limité à ses propres racines |
| En-tête obligatoire | toute requête `/api/` doit porter `X-Poseidon: 1` (anti-CSRF et anti-rebinding), sauf `GET /api/file` et `GET /api/text` (liens de téléchargement à identifiant signé) |
| Accès réseau | sans compte ni mot de passe, seules les requêtes **émises par la machine locale** vers un hôte local sont servies (sinon 503) ; ouverture explicite et déconseillée : `POSEIDON_INSECURE_NO_AUTH=1` + `POSEIDON_HOSTS` |
| Erreurs | `{"error": "message"}` avec le code HTTP ci-dessous ; les fichiers protégés ajoutent `"reason"` |
| Identifiants de fichiers | jetons signés `id` reçus dans les résultats ; ne pas les fabriquer |

| Code | Sens |
| --- | --- |
| 400 | Paramètre manquant ou invalide (filtre vide, regex invalide, racine introuvable) |
| 401 | Authentification requise ou mot de passe incorrect |
| 403 | En-tête `X-Poseidon` manquant, ou chemin hors des racines autorisées |
| 404 | Route inconnue |
| 503 | Instance sans authentification interrogée depuis une autre machine ou un autre nom d'hôte (seule la machine locale est servie) |
| 429 | Trop de tentatives de connexion (5 en 10 minutes par adresse IP) |
| 415 | Fichier sans texte exploitable |
| 422 | Fichier illisible ou protégé (`reason` : `protected`, `denied`, `corrupt`, `timeout`…) |
| 500 | Erreur interne (journalisée par PHP) |

## Paramètres de recherche communs

Utilisés par `/api/search`, `/api/preview` et `/api/extract`.

| Paramètre | Type | Défaut | Description |
| --- | --- | --- | --- |
| `root` | chaîne | — | Racine : `/home/…`, `C:\…`, `\\serveur\partage`, `file://…` |
| `q` | chaîne | — | Filtre |
| `mode` | `all` \| `any` \| `phrase` \| `regex` \| `near` | `all` | Tous les mots, un des mots, phrase exacte, expression régulière, proximité |
| `distance` | entier 0–100 | 5 | Proximité : nombre maximal de mots entre les termes |
| `fuzzy` | `0`/`1` | `0` | Tolérer les fautes de frappe |
| `caseSensitive` | `0`/`1` | `0` | Respecter la casse |
| `wholeWord` | `0`/`1` | `0` | Mot entier |
| `ignoreAccents` | `0`/`1` | `1` | `e` trouve aussi `é`, `è`, `ê`… |
| `extensions` | liste `pdf,docx` | toutes | Extensions retenues |
| `archives` | `0`/`1` | `1` | Ouvrir les ZIP |
| `target` | `content` \| `name` \| `both` | `content` | Où chercher |
| `name` | motif shell | — | Filtre sur le nom de fichier (`contrat_*.pdf`) |
| `limit` | entier | 5000 | Nombre maximal de fichiers retournés |
| `index` | `0`/`1` | `1` | Utiliser l'index de la racine s'il existe |

## Routes

### `GET /api/info`

Informations publiques (en-tête `X-Poseidon: 1` requis). Réponse :

```json
{ "name": "Poseidon", "version": "0.1.0", "php": "8.4.24", "os": "Linux", "pdfEngine": "pdftotext",
  "formats": ["txt", "md", "pdf", "docx", "xlsx", "zip"], "allowedRoots": [], "authRequired": false,
  "authenticated": true, "home": "/home/moi", "demo": "/opt/poseidon/samples/demo (null si POSEIDON_DEMO=0)",
  "indexAvailable": true, "ocr": false, "archives7z": true, "authMode": "none", "user": { "login": "local", "role": "admin" } }
```

### `GET /api/browse?path=`

Sous-dossiers d'un dossier (sélecteur de racine). Sans `path` : racines autorisées, ou lecteurs Windows, ou `/`.

```json
{ "path": "/home/moi", "parent": "/home", "dirs": [ { "name": "Documents", "path": "/home/moi/Documents" } ] }
```

### `GET /api/search`

Flux NDJSON (une ligne JSON par événement), `Content-Type: application/x-ndjson`.

```text
{"type":"start","root":"/srv/docs","query":{"q":"contrat préavis","mode":"all","terms":["contrat","préavis"],…}}
{"type":"match","file":{"id":"…","name":"CGV_v4.pdf","extension":"pdf","absolutePath":"/srv/docs/juridique/CGV_v4.pdf","relativePath":"juridique/CGV_v4.pdf","directory":"juridique","archiveDepth":0,"size":812,"modified":"2026-10-16T17:11:05+00:00"},"counts":[2,1],"total":3,"hitLines":2,"nameMatch":false,"hits":[{"location":"p. 1","text":"Le client peut resilier le contrat…","spans":[[25,7,0]]}]}
{"type":"notice","reason":"protected","path":"/srv/docs/archives/protege.zip › secret.txt","message":"Entrée chiffrée (archive protégée par mot de passe) : ignorée."}
{"type":"progress","scanned":1200,"matched":14,"current":"finance/2026/…"}
{"type":"done","summary":{"root":"/srv/docs","scanned":18,"matched":7,"inArchives":1,"occurrences":19,"termTotals":[11,8],"byExtension":{"docx":2},"notices":3,"truncated":false,"index":{"root":"/srv/docs","usable":true,"skippedFiles":10,"skippedArchives":1},"durationMs":84}}
```

`spans` : `[position, longueur, index du terme]` en caractères. `index` vaut `null` sans index. Fermer la connexion arrête le parcours.

### `GET /api/preview?id=&q=&mode=…`

Texte extrait complet (5 000 lignes maximum) avec surlignage, et tableaux (200 lignes par feuille).

```json
{ "file": { … }, "counts": [4, 2], "totalLines": 7, "truncated": false, "warnings": [],
  "lines": [ { "location": "§ 3", "text": "Le présent contrat…", "spans": [[11, 7, 0]] } ],
  "tables": [ { "name": "T3", "columns": 5, "totalRows": 4, "rows": [ { "n": 1, "cells": { "0": "Référence" } } ] } ] }
```

### `GET /api/file?id=&inline=1`

Fichier d'origine (y compris une entrée de ZIP imbriqué). `inline=1` ouvre dans le navigateur pour PDF, texte et images ; sinon téléchargement. Réponse servie avec `Content-Security-Policy: sandbox`.

### `GET /api/text?id=`

Texte extrait au format `.txt` (téléchargement).

### `GET /api/diff?a=&b=&context=3`

```json
{ "a": { … }, "b": { … }, "added": 3, "removed": 7, "identical": false,
  "hunks": [ { "header": "@@ -1,7 +1,3 @@", "lines": [["-", "Contrat-cadre…"], ["+", "Conditions générales…"], [" ", "…"]] } ],
  "unified": "--- a/A.docx\n+++ b/B.pdf\n@@ -1,7 +1,3 @@\n…" }
```

### `POST /api/extract`

Corps JSON : paramètres de recherche + spécification.

| Champ | Valeurs | Défaut |
| --- | --- | --- |
| `extract` | `matches`, `lines`, `columns`, `files` | `matches` |
| `format` | `csv`, `xlsx`, `json`, `txt`, `md` | `csv` |
| `columns` | `"A, C, Client"` | toutes |
| `headerRow` | entier (0 = aucune) | 1 |
| `allRows` | booléen | `false` |
| `withSource` | booléen | `true` |
| `unique` | booléen | `false` |

```sh
curl -H "X-Poseidon: 1" -H "Content-Type: application/json" \
  -d '{"root":"/srv/docs","q":"[A-Z]{2}\\d{6}","mode":"regex","extract":"matches","format":"xlsx","unique":true}' \
  -o references.xlsx http://127.0.0.1:8080/api/extract
```

En-têtes de réponse : `X-Poseidon-Rows` (lignes extraites), `X-Poseidon-Notices` (fichiers ignorés).

### `GET /api/index?root=`

État de l'index de la racine (ou d'un dossier parent) :

```json
{ "available": true, "exists": true,
  "stats": { "root": "/srv/docs", "files": 10200, "withText": 9300, "notices": 3, "builtAt": "2026-10-17T00:30:28+00:00", "sizeBytes": 65241088 } }
```

### `POST /api/index`

Corps `{"root": "…", "rebuild": false}`. Construit ou met à jour l'index ; réponse en flux NDJSON (`start`, `progress` avec `scanned`/`indexed`, `done` avec `files`, `indexed`, `unchanged`, `removed`, `notices`, `durationMs`, `stats`).

### `POST /api/index/delete`

Corps `{"root": "…"}` → `{"deleted": true}`.

### `GET /api/report?…&inline=1`

Rapport HTML autonome de la recherche (mêmes paramètres que `/api/search`). `inline=1` l'affiche, sinon téléchargement. Servi avec une CSP qui interdit tout script. Comme `/api/file`, accessible par simple lien (sans en-tête `X-Poseidon`).

### `GET /api/templates` · `POST /api/templates` · `POST /api/templates/delete`

Modèles d'extraction. Enregistrement : paramètres de recherche + d'extraction + `templateName` (+ `templateDescription`). Suppression : `{"name": "…"}`.

```json
{ "templates": [ { "name": "Références dossiers", "description": "", "query": { "q": "[A-Z]{2}\\d{6}", "mode": "regex" },
  "options": { "extensions": "md,log" }, "extraction": { "extract": "matches", "format": "xlsx", "unique": true }, "savedAt": "2026-10-17T03:01:00+02:00" } ] }
```

### `POST /api/login` / `POST /api/logout`

Comptes : `{"login": "lea", "password": "…"}` ; mot de passe partagé : `{"password": "…"}`. Réponse `{"authenticated": true, "user": {"login": "lea", "role": "lecteur"}}`, ou 401 / 429.

### `GET /api/audit?limit=300` (admin)

Dernières entrées du journal d'audit, la plus récente d'abord : `{"entries": [{"time": "…", "user": "lea", "ip": "10.0.0.4", "action": "téléchargement", "fichier": "/data/…"}]}`. Actions : connexion, connexion refusée, déconnexion, recherche, aperçu, téléchargement, texte, comparaison, extraction, rapport, indexation, index supprimé, modèle enregistré, modèle supprimé.

### `GET /projet/…`

Pages de suivi (`cahier-des-charges.html`, `roadmap.html`) servies depuis `docs/web`.
