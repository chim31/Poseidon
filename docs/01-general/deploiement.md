# Déploiement sur un serveur

Mettre Poseidon à disposition d'une équipe, dans un navigateur, en HTTPS. Pour un usage sur un seul poste, `poseidon.bat` ou `poseidon.sh` suffisent : voir [demarrage.md](demarrage.md).

## Sommaire

1. [Règles de sécurité](#1-règles-de-sécurité)
2. [Comptes et rôles](#2-comptes-et-rôles)
3. [Docker + Caddy (HTTPS automatique)](#3-docker--caddy-https-automatique)
4. [Nginx + PHP-FPM](#4-nginx--php-fpm)
5. [Apache](#5-apache)
6. [Windows Server](#6-windows-server)
7. [Sauvegarde et mises à jour](#7-sauvegarde-et-mises-à-jour)

## 1. Règles de sécurité

| Règle | Pourquoi | Comment |
| --- | --- | --- |
| Toujours HTTPS | Mots de passe et documents circulent sur le réseau | Proxy inverse (Caddy, Nginx, IIS) avec certificat |
| Toujours une authentification | Poseidon lit des fichiers du serveur | Comptes (`poseidon user add`) ou au minimum `POSEIDON_PASSWORD` |
| Toujours des racines autorisées | Aucune lecture hors périmètre | `POSEIDON_ROOTS`, et des racines par compte |
| Dossiers montés en lecture seule | Poseidon n'écrit jamais dans les racines | `:ro` (Docker), droits du compte de service |
| Journal d'audit conservé | Traçabilité (qui a consulté quoi) | `var/log/audit.log`, sauvegardé |

Sans mot de passe ni compte, Poseidon ne répond **qu'à la machine elle-même** (adresse source et nom d'hôte locaux : protège contre un poste du réseau qui enverrait `Host: localhost` et contre le DNS rebinding), avec une réponse 503 expliquant quoi faire. Dès qu'une authentification existe, le réseau est servi. Ouvrir au réseau sans authentification exige `POSEIDON_INSECURE_NO_AUTH=1` (déconseillé).

## 2. Comptes et rôles

| Rôle | Peut |
| --- | --- |
| `admin` | Tout : rechercher, consulter, télécharger, extraire, enregistrer **et supprimer** des modèles, construire et supprimer les index, lire le journal d'audit |
| `lecteur` | Rechercher, consulter, télécharger, comparer, extraire, rapports, utiliser et enregistrer des modèles |

```sh
php bin/poseidon user add admin --role=admin                         # demande le mot de passe (10 caractères min.)
php bin/poseidon user add lea --roots="/data/juridique;/data/rh"      # lectrice limitée à deux dossiers
php bin/poseidon user list
php bin/poseidon user passwd lea
php bin/poseidon user update lea --role=admin
php bin/poseidon user delete lea                                      # sa session devient invalide aussitôt
php bin/poseidon audit --tail=100 --user=lea                          # ce qu'elle a fait
```

| Mode d'accès | Activé quand | Connexion |
| --- | --- | --- |
| Local | Ni compte ni mot de passe | Aucune (hôtes locaux seulement) |
| Mot de passe partagé | `POSEIDON_PASSWORD` défini, aucun compte | Mot de passe seul, utilisateur « partagé » (admin) |
| Comptes | `var/users.json` contient au moins un compte | Identifiant + mot de passe |

Mots de passe hachés (`password_hash`, rehachés si l'algorithme évolue), 5 échecs en 10 minutes par adresse IP bloquent la connexion (réponse 429), chaque tentative est journalisée.

## 3. Docker + Caddy (HTTPS automatique)

Le dépôt fournit `Dockerfile` (PHP 8.4 sur Debian 13 : SQLite 3.46 pour l'index, poppler, libarchive pour 7z/RAR, Tesseract avec le français) et `docker-compose.yml` (Poseidon + Caddy, certificat Let's Encrypt automatique).

```sh
# 1. Adapter docker-compose.yml : nom de domaine (poseidon.exemple.fr) et dossier ./donnees à fouiller
# 2. Démarrer avec un mot de passe provisoire (obligatoire : sans lui, Compose refuse de démarrer)
POSEIDON_PASSWORD='un-mot-de-passe-long' docker compose up -d --build
# 3. Créer le premier compte administrateur
docker compose exec poseidon php bin/poseidon user add admin --role=admin
# 4. (Optionnel) indexer les données
docker compose exec poseidon php bin/poseidon index /data
```

| Élément | Valeur |
| --- | --- |
| Port interne | 8080 (servi par `poseidon serve`, 4 processus) |
| Dossiers fouillés | `/data` (montage en lecture seule), `POSEIDON_ROOTS=/data` |
| Données persistantes | volume `/app/var` : index, cache, comptes, modèles, journal d'audit, clé de signature |
| Compte système | `poseidon` (non root) |
| Santé | `HEALTHCHECK` sur `/api/info` |

Essai local sans domaine : remplacer `poseidon.exemple.fr` par `localhost` (Caddy émet un certificat local).

## 4. Nginx + PHP-FPM

```nginx
server {
    listen 443 ssl http2;
    server_name poseidon.exemple.fr;
    ssl_certificate     /etc/letsencrypt/live/poseidon.exemple.fr/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/poseidon.exemple.fr/privkey.pem;

    root /opt/poseidon/public;
    client_max_body_size 1m;

    location / { try_files $uri /index.php$is_args$args; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param HTTPS on;
        fastcgi_param POSEIDON_ROOTS "/srv/docs";
        fastcgi_buffering off;          # résultats en flux
        fastcgi_read_timeout 600s;      # longues recherches et indexations
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
}
server { listen 80; server_name poseidon.exemple.fr; return 301 https://$host$request_uri; }
```

Le compte de PHP-FPM doit **lire** les racines et **écrire** dans `/opt/poseidon/var`.

## 5. Apache

```apache
<VirtualHost *:443>
    ServerName poseidon.exemple.fr
    DocumentRoot /opt/poseidon/public
    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/poseidon.exemple.fr/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/poseidon.exemple.fr/privkey.pem
    SetEnv POSEIDON_ROOTS /srv/docs
    <Directory /opt/poseidon/public>
        AllowOverride None
        Require all granted
        FallbackResource /index.php
    </Directory>
</VirtualHost>
```

## 6. Windows Server

1. Installer PHP 8.4 (extensions `zip`, `mbstring`, `intl`, `pdo_sqlite`), Tesseract et poppler si besoin.
2. Lancer Poseidon comme service (par exemple avec NSSM) : `php.exe C:\poseidon\bin\poseidon serve --host=127.0.0.1 --port=8080`, avec `POSEIDON_ROOTS` défini dans l'environnement du service.
3. Publier en HTTPS avec IIS (module URL Rewrite + Application Request Routing) en proxy inverse vers `http://127.0.0.1:8080`, en désactivant la mise en mémoire tampon des réponses (flux de résultats).
4. Le compte du service doit avoir accès aux partages `\\serveur\partage` utilisés comme racines.

## 7. Sauvegarde et mises à jour

| Quoi | Où | Sauvegarder ? |
| --- | --- | --- |
| Comptes | `var/users.json` | Oui |
| Journal d'audit | `var/log/audit.log*` | Oui (conservation selon la politique interne) |
| Modèles d'extraction | `var/templates/` | Oui |
| Clé de signature | `var/secret.key` | Oui (sinon les liens en cours deviennent invalides) |
| Index et cache | `var/index/`, `var/cache/` | Non : reconstructibles |

Mise à jour : `git pull` (ou nouvelle image `docker compose up -d --build`), puis `php tests/run.php`. Les données de `var/` sont conservées.
