# Poseidon

**Recherche et extraction dans les fichiers.** Poseidon fait ce que font ensemble `ls -R`, `grep -R -i`, les pipes et `cat`, mais il lit aussi l'intérieur des PDF, Word, Excel, OpenDocument et des ZIP imbriqués, compte les occurrences de chaque mot, prouve chaque résultat par sa ligne, et transforme une recherche en un nouveau document de données.

Cette branche (`main`) ne contient que le nécessaire pour la production. Documentation, tests, lanceurs et outils de développement : branche [`dev`](../../tree/dev).

**Version 1.1.0.**

## Installer (sans terminal)

1. Copier ce dossier dans le dossier web du serveur : `C:\inetpub\wwwroot\poseidon` (IIS), `C:\wamp64\www\poseidon` (WAMP) ou `/var/www/poseidon` (Apache). PHP 8.2 ou plus récent avec les extensions `zip` et `mbstring` (`pdo_sqlite` recommandée).
2. Ouvrir `http://serveur/poseidon/` dans le navigateur.

Aucun module de réécriture d'URL n'est nécessaire. `web.config` (IIS) et les `.htaccess` (Apache) fournis interdisent l'accès direct aux dossiers internes (`var/`, `config/`, `src/`).

## Avant d'ouvrir au réseau

Sans mot de passe, Poseidon ne répond qu'au serveur lui-même (`http://127.0.0.1/poseidon/`). Pour l'ouvrir aux autres postes, définir dans les variables d'environnement du serveur web (IIS : pool d'applications ; Apache : `SetEnv`) :

| Variable | Rôle |
| --- | --- |
| `POSEIDON_PASSWORD` | Mot de passe partagé, obligatoire pour l'accès réseau |
| `POSEIDON_ROOTS` | Dossiers autorisés, séparés par `;` (ex. `\\serveur\juridique;D:\Archives`) |
| `POSEIDON_GIT_TRUSTED` | Dépôts Git partagés appartenant à un autre compte, à lire quand même |

Le compte du service web doit pouvoir **lire** les dossiers et partages à fouiller, et **écrire** dans `var/`.

## Ce que fait l'interface

| | |
| --- | --- |
| **Où** | Dossier local, `C:\…`, partage `\\serveur\partage` ; à l'intérieur des ZIP, 7z, RAR, tar, gz et des pièces jointes de courriels |
| **Quoi** | PDF (OCR si scanné), Word, Excel, OpenDocument, PowerPoint, RTF, CSV, texte, code, courriels `.eml` / `.msg`, images (OCR) |
| **Comment** | Un mot, une phrase, plusieurs mots (tous / un des), à proximité, avec fautes de frappe, ou une regex |
| **Comparer** | Deux fichiers, deux dossiers ou un dépôt Git entier (façon GitHub : ajoutés, modifiés, supprimés, renommés) |
| **Données** | Extraction vers CSV, XLSX, JSON, TXT, MD ; rapport HTML/PDF |

## Licence des polices

Archivo et JetBrains Mono sont distribuées sous licence SIL Open Font License 1.1.
