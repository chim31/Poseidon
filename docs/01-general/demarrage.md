# Guide de démarrage

Tout ce qu'il faut pour installer, configurer, lancer, tester et déployer Poseidon, même sans connaître PHP. Suis les étapes dans l'ordre.

## Sommaire

1. [Prérequis](#1-prérequis)
2. [Installation](#2-installation)
3. [Premier lancement](#3-premier-lancement)
4. [Configuration](#4-configuration)
5. [Utilisation en ligne de commande](#5-utilisation-en-ligne-de-commande)
6. [Partages réseau](#6-partages-réseau)
7. [Tests](#7-tests)
8. [Déploiement sur un serveur](#8-déploiement-sur-un-serveur)
9. [Commandes utiles](#9-commandes-utiles)
10. [Problèmes fréquents](#10-problèmes-fréquents)

## 1. Prérequis

| Élément | Version | Obligatoire | Rôle |
| --- | --- | --- | --- |
| PHP | 8.2 ou plus récent (testé en 8.4) | Oui | Exécute Poseidon |
| Extension `zip` | incluse dans PHP | Oui | DOCX, XLSX, ODT, ZIP |
| Extension `mbstring` | incluse dans PHP | Oui | Texte Unicode, accents |
| `pdftotext` (poppler-utils) | toute version récente | Non, recommandé | Meilleure lecture des PDF |
| `antiword` | toute version | Non | Meilleure lecture des `.doc` anciens |
| Navigateur | Chrome, Edge, Firefox, Safari récents | Pour l'interface | |

Aucun Composer, aucun Node, aucune base de données.

Vérifier PHP :

```sh
php -v
php -m | grep -Ei "zip|mbstring"     # Windows : php -m | findstr /i "zip mbstring"
```

## 2. Installation

### Windows

1. Installer PHP : télécharger l'archive « VS16 x64 Thread Safe » sur <https://windows.php.net/download>, la décompresser (par exemple dans `C:\php`).
2. Dans `C:\php`, copier `php.ini-production` en `php.ini`, puis décommenter (retirer le `;`) les lignes `extension=zip` et `extension=mbstring`.
3. Ajouter `C:\php` au `PATH` (Paramètres → Système → Informations système → Paramètres avancés → Variables d'environnement), **ou** copier le dossier PHP dans un sous-dossier `php\` à côté de `poseidon.bat` : le lanceur le trouvera tout seul.
4. Récupérer Poseidon : `git clone git@github.com:chim31/Poseidon.git` ou télécharger le ZIP du dépôt.
5. Optionnel : installer poppler pour Windows et ajouter son dossier `bin` au `PATH` pour `pdftotext`.

### Linux / WSL (Debian, Ubuntu, Kali)

```sh
sudo apt install php-cli php-zip php-mbstring poppler-utils   # poppler-utils : optionnel
git clone git@github.com:chim31/Poseidon.git
cd Poseidon
```

### macOS

```sh
brew install php poppler
git clone git@github.com:chim31/Poseidon.git && cd Poseidon
```

## 3. Premier lancement

| Système | Commande | Résultat |
| --- | --- | --- |
| Windows | double-clic sur `poseidon.bat` | Serveur sur le port 8080, navigateur ouvert |
| Windows, autre port | `poseidon.bat 9000` | Port 9000 |
| Linux / macOS / WSL | `./poseidon.sh` | Serveur sur le port 8080, navigateur ouvert |
| Partout | `php bin/poseidon serve --port=8080` | Serveur seul |

Puis dans l'interface :

1. Clique sur **Essayer sur les échantillons** : Poseidon génère un jeu de fichiers synthétiques (`samples/demo`) et lance une recherche.
2. Pour tes propres fichiers : saisis la **racine** dans le rail de gauche (ou clique sur l'icône dossier pour naviguer), tape tes mots, puis **Sonder**.
3. Clique sur un résultat pour l'aperçu ; coche deux résultats pour **Comparer** ; **Extraire** pour produire un document.

Raccourcis : `Ctrl+K` place le curseur dans le filtre, `N` / `Maj+N` passent d'une occurrence à l'autre dans l'aperçu, `Échap` annule une recherche en cours.

Le cahier des charges et la roadmap sont servis à l'adresse `/projet/`.

## 4. Configuration

La configuration vit dans `config/poseidon.php`. Pour la surcharger sans toucher au fichier suivi par Git, crée `config/poseidon.local.php` (ignoré par Git) qui retourne uniquement les clés à changer :

```php
<?php
return [
    'allowed_roots' => ['D:\\Archives', '\\\\srv-archives\\juridique'],
    'network_mounts' => ['\\\\srv-archives\\juridique' => '/mnt/juridique'],
    'max_file_size' => 500 * 1024 * 1024,
];
```

### Variables d'environnement

| Variable | Défaut | Rôle |
| --- | --- | --- |
| `POSEIDON_ROOTS` | vide (aucune restriction) | Racines autorisées, séparées par `;` |
| `POSEIDON_PASSWORD` | vide (pas d'authentification) | Mot de passe d'accès à l'interface |
| `POSEIDON_HOSTS` | vide | Noms d'hôte acceptés en plus de `localhost` quand il n'y a pas de mot de passe (ex. `poste-42;192.168.1.20`) |
| `POSEIDON_MAX_FILE_SIZE` | 200 Mo | Taille maximale d'un fichier analysé (octets) |
| `POSEIDON_PDFTOTEXT` | détecté dans le `PATH` | Chemin explicite de `pdftotext` |
| `POSEIDON_ANTIWORD` | détecté dans le `PATH` | Chemin explicite d'`antiword` |
| `POSEIDON_PHP` | `php` | PHP utilisé par les lanceurs `.bat` / `.sh` |

Exemple Linux : `POSEIDON_ROOTS="/srv/docs;/mnt/partage" POSEIDON_PASSWORD="…" ./poseidon.sh`
Exemple Windows : `set POSEIDON_PASSWORD=…` puis `poseidon.bat`.

### Autres clés de `config/poseidon.php`

| Clé | Défaut | Rôle |
| --- | --- | --- |
| `excluded_dirs` | `.git`, `node_modules`, `vendor`… | Dossiers jamais parcourus |
| `zip.max_depth` | 5 | Profondeur maximale de ZIP imbriqués |
| `zip.max_entry_size` | 100 Mo | Taille maximale d'une entrée d'archive |
| `zip.max_total_size` | 1 Go | Volume décompressé maximal par archive racine |
| `zip.max_ratio` | 200 | Ratio de compression au-delà duquel une entrée est suspecte |
| `zip.max_entries` | 20 000 | Nombre maximal d'entrées par archive |
| `max_hits_per_file` | 50 | Lignes-preuves conservées par fichier dans les résultats |
| `pdf_timeout` | 30 s | Délai maximal d'analyse d'un PDF |
| `cache_dir` | `var/cache` | Cache du texte extrait ; vide = désactivé |

Ne jamais écrire de mot de passe dans un fichier suivi par Git.

## 5. Utilisation en ligne de commande

```sh
php bin/poseidon help
```

| Besoin | Commande |
| --- | --- |
| Fichiers contenant deux mots | `php bin/poseidon search ./docs "contrat préavis"` |
| Au moins un des mots | `php bin/poseidon search ./docs "contrat avenant" --mode=any` |
| Phrase exacte | `php bin/poseidon search ./docs "préavis de 30 jours" --mode=phrase` |
| Phrase groupée parmi d'autres mots | `php bin/poseidon search ./docs '"préavis de 30 jours" contrat'` |
| Motif | `php bin/poseidon search ./docs "[A-Z]{2}\d{6}" --mode=regex` |
| Seulement les PDF et DOCX | `... --ext=pdf,docx` |
| Juste la liste des fichiers (`grep -l`) | `... -l` |
| Dans les noms de fichiers (`ls -R \| grep`) | `... --target=name` |
| Sortie JSON | `... --json` |
| Extraire des références vers Excel | `php bin/poseidon extract ./docs "[A-Z]{2}\d{6}" --mode=regex --unique --out=refs.xlsx` |
| Extraire des colonnes | `php bin/poseidon extract ./finance "résilié" --extract=columns --columns=Client,Montant --out=out.csv` |
| Afficher le texte d'un PDF (`cat`) | `php bin/poseidon cat rapport.pdf` |
| Comparer deux fichiers | `php bin/poseidon diff v1.docx v2.docx` |

Codes de retour : `0` résultats trouvés (ou fichiers identiques pour `diff`), `1` aucun résultat (ou différences), `2` erreur. Les avertissements (fichiers protégés…) sortent sur stderr ; `--no-warnings` les masque.

## 6. Partages réseau

| Système | Comment faire |
| --- | --- |
| Windows | Saisir directement `\\serveur\partage\dossier`. Le compte qui lance Poseidon doit avoir accès au partage. |
| Linux / WSL | Monter le partage puis déclarer la correspondance pour pouvoir continuer à saisir le chemin UNC. |

Montage Linux (exemple) :

```sh
sudo mkdir -p /mnt/juridique
sudo mount -t cifs //srv-archives/juridique /mnt/juridique -o username=moi,uid=$(id -u),gid=$(id -g),ro
```

Puis dans `config/poseidon.local.php` : `'network_mounts' => ['\\\\srv-archives\\juridique' => '/mnt/juridique']`.

Sous WSL, un lecteur Windows réseau `Z:` est aussi accessible via `/mnt/z` après `sudo mount -t drvfs Z: /mnt/z`.

## 7. Tests

```sh
php tests/run.php
```

La suite génère des fichiers synthétiques (DOCX, XLSX, PDF, ZIP dans ZIP, ZIP chiffré, document protégé, fichier sans droit), vérifie chemins, filtres, formats, avertissements, extraction, identifiants signés et diff, puis nettoie tout. Résultat attendu : `62 tests réussis`.

Régénérer les échantillons de démonstration : `php bin/poseidon demo`.

## 8. Déploiement sur un serveur

Le serveur intégré de PHP (`poseidon serve`) suffit pour un usage local. Pour un accès réseau :

1. **Toujours** définir `POSEIDON_PASSWORD` et `POSEIDON_ROOTS`. Sans mot de passe, Poseidon ne répond qu'aux hôtes locaux et à ceux de `POSEIDON_HOSTS` (protection contre le « DNS rebinding »).
2. Servir `public/` avec Apache ou Nginx + PHP-FPM, en réécrivant toutes les requêtes non statiques vers `public/index.php` :

   ```nginx
   server {
       listen 443 ssl;
       server_name poseidon.exemple.local;
       root /opt/poseidon/public;
       location / { try_files $uri /index.php$is_args$args; }
       location ~ \.php$ {
           include fastcgi_params;
           fastcgi_param SCRIPT_FILENAME $document_root/index.php;
           fastcgi_param POSEIDON_PASSWORD "…";
           fastcgi_param POSEIDON_ROOTS "/srv/docs";
           fastcgi_buffering off;          # résultats en flux
           fastcgi_read_timeout 600s;      # longues recherches
           fastcgi_pass unix:/run/php/php8.4-fpm.sock;
       }
   }
   ```

3. HTTPS obligatoire (certificat interne ou Let's Encrypt).
4. Le compte du serveur web doit pouvoir **lire** les racines et **écrire** dans `var/`.

## 9. Commandes utiles

| Commande | Effet |
| --- | --- |
| `php bin/poseidon info` | Version, moteur PDF, racines autorisées, formats |
| `php bin/poseidon demo` | (Ré)génère `samples/demo` |
| `php docs/tools/sync.php` | Régénère le suivi web et Markdown depuis `projet.json` |
| `rm -rf var/cache/??` | Vide le cache de texte extrait |

## 10. Problèmes fréquents

| Symptôme | Cause | Solution |
| --- | --- | --- |
| `PHP introuvable` au lancement de `poseidon.bat` | PHP absent du `PATH` | Ajouter PHP au `PATH` ou le placer dans `php\` à côté du lanceur |
| `Les extensions PHP "zip" et "mbstring" sont requises` | Extensions désactivées | Décommenter `extension=zip` et `extension=mbstring` dans `php.ini` |
| « Le partage réseau … n'est pas accessible directement » | Chemin UNC sous Linux/WSL | Monter le partage et le déclarer dans `network_mounts` (§ 6) |
| « Chemin hors des racines autorisées » | `POSEIDON_ROOTS` restreint l'accès | Ajouter la racine à la liste |
| PDF trouvés mais texte vide | PDF scanné (image) | OCR prévu en phase 6 ; en attendant, passer le PDF dans un outil d'OCR |
| Texte de PDF mal découpé | Lecteur PHP de secours utilisé | Installer `pdftotext` (poppler) |
| Une phrase exacte n'est pas trouvée dans un PDF | La phrase est coupée par un retour à la ligne | Utiliser le mode « Tous les mots » (voir [anomalies](../03-suivi/anomalies.md)) |
| L'aperçu reste bloqué pendant une longue recherche (Windows) | Serveur intégré PHP mono-processus sous Windows | Attendre la fin de la recherche, ou servir via Apache/Nginx (§ 8) |
| « Hôte non autorisé » (code 421) | Accès par un nom ou une IP autre que `localhost` sans mot de passe | Définir `POSEIDON_PASSWORD`, ou ajouter l'hôte à `POSEIDON_HOSTS` |
| « Identifiant de fichier invalide ou expiré » | `var/secret.key` régénéré (nouvelle installation) | Relancer la recherche |
| Fichier signalé « Protégé » | Mot de passe sur le document ou l'archive | Normal : Poseidon n'ouvre pas les fichiers protégés |
