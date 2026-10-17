# Contexte du projet Poseidon

> Source de vérité partagée par tous les intervenants (humains et agents). À lire au début de chaque session, à mettre à jour avant de clôturer toute action.

**Dernière mise à jour :** 2026-10-17 · **Version :** 0.1.0

## Sommaire

1. [Objectif](#1-objectif)
2. [Périmètre](#2-périmètre)
3. [État actuel](#3-état-actuel)
4. [Décisions techniques et fonctionnelles](#4-décisions-techniques-et-fonctionnelles)
5. [Contraintes](#5-contraintes)
6. [Intervenants et responsabilités](#6-intervenants-et-responsabilités)
7. [Documents du projet](#7-documents-du-projet)
8. [Historique](#8-historique)

## 1. Objectif

Poseidon est un outil PHP qui se comporte comme la combinaison des commandes Unix `ls -R`, `grep -R -i`, des pipes et de `cat` :

- on choisit un **répertoire racine** (local, lecteur Windows, partage réseau `\\serveur\partage`) ;
- on donne un **filtre** : un mot, une phrase en bloc, une phrase découpée en plusieurs mots (ET / OU) ou une expression régulière ;
- Poseidon indique **combien de fichiers** contiennent l'information, **où** (chemins absolu et relatif, y compris dans des ZIP imbriqués), **combien d'occurrences** par terme, et **sur quelles lignes** ;
- on peut **consulter** le contenu, **télécharger** le fichier, **comparer** deux fichiers (façon `git diff`) ;
- on peut **extraire** uniquement les mots, motifs ou colonnes désignés vers un nouveau document, comme un `SELECT` avec mapping sur une base de données.

Formats : TXT, MD, CSV, logs, code, PDF (OCR si scanné), DOCX, DOC, ODT, RTF, XLSX, XLS, ODS, PPTX, ODP, courriels EML/MSG et leurs pièces jointes, images (OCR) ; conteneurs ZIP, 7z, RAR, tar, gz imbriqués.

## 2. Périmètre

| Inclus | Exclu (pour l'instant) |
| --- | --- |
| Recherche récursive locale et réseau | Écriture ou suppression dans la racine |
| 11 familles de formats + ZIP imbriqués | Ouverture des fichiers protégés par mot de passe |
| Modes ET / OU / phrase / regex, casse, accents, mot entier | OCR sans Tesseract installé |
| Extraction CSV / XLSX / JSON / TXT / MD, mapping de colonnes | Accès SMB direct sous Linux sans montage |
| Comparaison de deux fichiers (diff unifié) | Comptes multiples et rôles (phase 8) |
| Interface web, CLI, lanceurs `.bat` / `.sh` | Base de données serveur (SQLite embarqué pour l'index uniquement) |

**Documents non créés car non applicables** (règle d'adaptation) :

- `docs/01-general/base-de-donnees.md` : créé en phase 5 pour l'index SQLite (seul stockage structuré).
- `docs/02-technique/interface/` : l'interface tient en un seul écran ; ses zones sont décrites dans [interface.md](docs/02-technique/interface.md).

## 3. État actuel

| Élément | État |
| --- | --- |
| Moteur (parcours, ZIP, extracteurs, recherche, extraction, diff) | Terminé, testé (62 tests), fusionné dans `main` (PR #1) |
| Interface web + API + lanceurs | Terminé, revue de design faite, fusionné dans `main` (PR #4) |
| Documentation, cahier des charges, roadmap | Terminé, fusionné dans `main` (PR #4) |
| Fusion dans `main` | Faite le 2026-10-17 ; branches de fonctionnalité supprimées |
| Essais sur de vraies arborescences Windows | Validés par le porteur le 2026-10-17 → v0.1.0 |
| Index plein texte, banc d'essai | Terminés — phase P5 (PR #8) |
| Intégration continue GitHub Actions | Prête mais **bloquée** : compte GitHub verrouillé pour facturation (à régler par le porteur). Tests passés à la main sous Linux (PHP 8.4, 82 tests) et Windows (PHP 8.3, 77 tests) |
| Formats étendus (courriels .eml/.msg, 7z/RAR/tar/gz, OCR) | Terminés — phase P6 |
| Phase en cours | P7 — Extraction avancée |

Avancement détaillé : [cahier des charges web](docs/web/cahier-des-charges.html) · [suivi Markdown](docs/03-suivi/suivi-cahier-des-charges.md) · [roadmap](docs/web/roadmap.html).

## 4. Décisions techniques et fonctionnelles

| Date | Décision | Justification |
| --- | --- | --- |
| 2026-10-16 | PHP 8.4 natif, sans framework ni Composer | Validé par le porteur. Laravel exige ext-dom/xml, absents de la machine ; zéro dépendance = installation immédiate sous Windows. Laravel reste une option. |
| 2026-10-16 | Lecture DOCX/XLSX/ODT/PPTX par expressions régulières | Pas d'ext-dom ; les formats Office sont des ZIP de XML simples à lire. |
| 2026-10-16 | PDF : `pdftotext` si présent, lecteur PHP de secours sinon | Meilleure qualité quand poppler existe, résultat minimal garanti sinon. |
| 2026-10-16 | Résultats diffusés en flux (NDJSON) | Vitesse ressentie, annulation possible. |
| 2026-10-16 | Cache disque du texte extrait (PDF, Office) | Deuxième recherche quasi instantanée. |
| 2026-10-16 | Identifiants de fichiers signés (HMAC) | Empêche de forger un lien vers un fichier arbitraire du serveur. |
| 2026-10-16 | Fichiers protégés / illisibles : ignorés et signalés | Demande du porteur : le programme ne doit jamais tourner en rond. Alerte en ligne + panneau de détail dans l'interface, avertissements sur stderr en CLI. |
| 2026-10-16 | Comparaison façon `git diff` (Myers) | Demande du porteur. Fonctionne sur le texte extrait : PDF contre DOCX possible. |
| 2026-10-16 | Lanceurs `poseidon.bat` et `bin\poseidon.bat` | Demande du porteur : alternative Windows en double-clic. |
| 2026-10-16 | Univers visuel « Pavillons de signaux » | Choisi par le porteur parmi les directions proposées ; chaque terme reçoit un pavillon du code international des signaux. Sol froid « embrun » plutôt que crème. Voir [DESIGN.md](DESIGN.md). |
| 2026-10-17 | Courriels et archives exotiques comme conteneurs | Les pièces jointes d'un mail et les entrées d'un 7z sont des fichiers comme les autres (chemins chaînés, imbrication libre). `.msg` lu par un lecteur OLE maison (aucune dépendance) ; 7z/RAR via bsdtar, présent nativement sous Windows. |
| 2026-10-17 | OCR optionnel (Tesseract) | Activé automatiquement si présent ; borné en pages et en temps ; résultat mis en cache. |
| 2026-10-17 | Index SQLite FTS5 trigramme sans contenu | Recherches répétées quasi instantanées sans serveur à installer ; l'index ne fait que désigner des candidats, le Matcher vérifie toujours (résultats identiques). |
| 2026-10-17 | Parallélisation écartée | Pas de fils d'exécution portables en PHP (Windows) ; l'index supprime l'essentiel des lectures. |
| 2026-10-17 | Durcissement après revue de sécurité automatique | Protection DNS rebinding (liste d'hôtes, `POSEIDON_HOSTS`), en-tête `X-Poseidon` sur toute l'API, bornes anti-bombe (Office, PDF, tableurs), diff à mémoire bornée, formules CSV neutralisées partout. Racines contrôlées sur chemin réel (liens symboliques). Voir BUG-012 à BUG-018. |
| 2026-10-17 | PR toujours dirigées vers `main` | Les PR empilées ont été fusionnées dans leur branche de base au lieu de `main` ; désormais chaque PR cible `main` directement. |
| 2026-10-16 | Suivi piloté par `docs/web/data/projet.json` | Une seule source ; `php docs/tools/sync.php` régénère les vues web et Markdown. |

## 5. Contraintes

- **Aucun CDN** : polices (Archivo, JetBrains Mono, licence OFL), icônes et scripts servis par l'application.
- **Chemins** : slash et anti-slash, lecteurs Windows, UNC `\\serveur\partage`, `file://`, `smb://`, chemins entre guillemets.
- **Réseau** : l'interface doit fonctionner dans un navigateur, en local, sur intranet ou sur un serveur distant → mot de passe (`POSEIDON_PASSWORD`), racines autorisées (`POSEIDON_ROOTS`) et HTTPS (proxy inverse) obligatoires hors poste local. Sans mot de passe, seuls `localhost` et les hôtes de `POSEIDON_HOSTS` sont servis.
- **Prérequis** : PHP ≥ 8.2 avec `zip` et `mbstring`. Optionnels : `pdftotext` (poppler), `antiword`.
- **Git** : une branche par fonctionnalité, Conventional Commits, **aucune mention de co-auteur** (ni `Co-authored-by`, ni signature d'outil), PR via `gh`, fusion après validation du porteur pour tout changement structurant.
- **Sécurité** : aucun secret dans le dépôt ; `var/secret.key` est généré localement et ignoré par Git.

## 6. Intervenants et responsabilités

| Intervenant | Rôle |
| --- | --- |
| chim31 | Porteur du projet : besoin, validation des PR, fusion des changements structurants |
| Agent IA de développement | Conception, code, tests, documentation sur branches dédiées ; fusion autonome limitée à la documentation et aux corrections mineures |

Projet individuel : pas de binôme.

## 7. Documents du projet

| Document | Rôle |
| --- | --- |
| [README.md](README.md) | Présentation et démarrage rapide |
| [PRODUCT.md](PRODUCT.md) | Vérité produit (utilisateurs, principes) |
| [DESIGN.md](DESIGN.md) | Système visuel « Pavillons de signaux » |
| [docs/README.md](docs/README.md) | Index de la documentation |
| [docs/01-general/demarrage.md](docs/01-general/demarrage.md) | Installer et lancer, pas à pas |
| [docs/01-general/architecture.md](docs/01-general/architecture.md) | Architecture et flux |
| [docs/01-general/api.md](docs/01-general/api.md) | API HTTP |
| [docs/web/cahier-des-charges.html](docs/web/cahier-des-charges.html) | Cahier des charges et avancement |
| [docs/web/roadmap.html](docs/web/roadmap.html) | Roadmap |
| [docs/03-suivi/anomalies.md](docs/03-suivi/anomalies.md) | Registre des anomalies |

## 8. Historique

| Date | Évolution |
| --- | --- |
| 2026-10-16 | Cadrage : besoin reformulé, stack PHP natif validée, direction visuelle choisie. |
| 2026-10-16 | Ajouts demandés en cours de route : signalement des fichiers protégés, lanceurs `.bat`, comparaison façon `git diff`. |
| 2026-10-16 | Moteur, interface, documentation et suivi livrés sur trois branches ; PR #1, #2, #3 ouvertes pour validation. |
| 2026-10-17 | Phase P6 : courriels .eml/.msg avec pièces jointes, 7z/RAR/tar/gz, OCR ; tests Linux 82/82, Windows 77/77. |
| 2026-10-17 | Phase P5 : index plein texte, intégration continue Linux/Windows, banc d'essai (10 000 fichiers : 5,2 s à froid, 0,47 s indexé). |
| 2026-10-17 | Essai Windows validé après libération du port 8080 ; étiquette v0.1.0 ; démarrage de la phase P5. |
| 2026-10-17 | Premier essai Windows : le lanceur ouvrait une autre application occupant le port 8080 → port libre automatique et ouverture du navigateur une fois Poseidon prêt (BUG-019). |
| 2026-10-17 | PR #2 et #3 fusionnées par erreur dans les branches empilées : contenu regroupé et fusionné dans `main` via la PR #4 ; branches supprimées. |
| 2026-10-17 | Revue de sécurité : 7 corrections (DNS rebinding, liens symboliques…) intégrées aux PR, 62 tests. |
