# API HTTP

L'interface web consomme cette API ; elle est aussi utilisable par un script (`curl`, PowerShell…).

## Conventions

| Sujet | Règle |
| --- | --- |
| Base URL | `http://127.0.0.1:8080` (lanceurs) ou l'URL du serveur |
| Versionnement | aucun préfixe pour la v0.x ; `/api/v1` sera introduit avant la v1.0 |
| Format | JSON UTF-8, sauf `/api/search` (NDJSON) et les téléchargements |
| Authentification | si `POSEIDON_PASSWORD` est défini : `POST /api/login`, puis cookie de session `poseidon` |
| Anti-CSRF | toute requête `POST` doit porter l'en-tête `X-Poseidon: 1` |
| Erreurs | `{"error": "message"}` avec le code HTTP ci-dessous ; les fichiers protégés ajoutent `"reason"` |
| Identifiants de fichiers | jetons signés `id` reçus dans les résultats ; ne pas les fabriquer |

| Code | Sens |
| --- | --- |
| 400 | Paramètre manquant ou invalide (filtre vide, regex invalide, racine introuvable) |
| 401 | Authentification requise ou mot de passe incorrect |
| 403 | En-tête `X-Poseidon` manquant, ou chemin hors des racines autorisées |
| 404 | Route inconnue |
| 415 | Fichier sans texte exploitable |
| 422 | Fichier illisible ou protégé (`reason` : `protected`, `denied`, `corrupt`, `timeout`…) |
| 500 | Erreur interne (journalisée par PHP) |

## Paramètres de recherche communs

Utilisés par `/api/search`, `/api/preview` et `/api/extract`.

| Paramètre | Type | Défaut | Description |
| --- | --- | --- | --- |
| `root` | chaîne | — | Racine : `/home/…`, `C:\…`, `\\serveur\partage`, `file://…` |
| `q` | chaîne | — | Filtre |
| `mode` | `all` \| `any` \| `phrase` \| `regex` | `all` | Tous les mots, un des mots, phrase exacte, expression régulière |
| `caseSensitive` | `0`/`1` | `0` | Respecter la casse |
| `wholeWord` | `0`/`1` | `0` | Mot entier |
| `ignoreAccents` | `0`/`1` | `1` | `e` trouve aussi `é`, `è`, `ê`… |
| `extensions` | liste `pdf,docx` | toutes | Extensions retenues |
| `archives` | `0`/`1` | `1` | Ouvrir les ZIP |
| `target` | `content` \| `name` \| `both` | `content` | Où chercher |
| `name` | motif shell | — | Filtre sur le nom de fichier (`contrat_*.pdf`) |
| `limit` | entier | 5000 | Nombre maximal de fichiers retournés |

## Routes

### `GET /api/info`

Informations publiques. Réponse :

```json
{ "name": "Poseidon", "version": "0.1.0", "php": "8.4.24", "os": "Linux", "pdfEngine": "pdftotext",
  "formats": ["txt", "md", "pdf", "docx", "xlsx", "zip"], "allowedRoots": [], "authRequired": false,
  "authenticated": true, "home": "/home/moi", "demo": "/opt/poseidon/samples/demo" }
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
{"type":"done","summary":{"root":"/srv/docs","scanned":18,"matched":7,"inArchives":1,"occurrences":19,"termTotals":[11,8],"byExtension":{"docx":2},"notices":3,"truncated":false,"durationMs":84}}
```

`spans` : `[position, longueur, index du terme]` en caractères. Fermer la connexion arrête le parcours.

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

### `POST /api/login` / `POST /api/logout`

`{"password": "…"}` → `{"authenticated": true}` ou 401.

### `GET /projet/…`

Pages de suivi (`cahier-des-charges.html`, `roadmap.html`) servies depuis `docs/web`.
