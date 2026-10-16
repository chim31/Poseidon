# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Stack

PHP 8.4 natif, sans framework (choix confirmé le 2026-10-16). Architecture PSR-4 avec autoloader maison, zéro dépendance Composer, servi par `php -S` en local ou par n'importe quel serveur PHP (Apache/Nginx + PHP-FPM). Interface en HTML/CSS/JS vanilla servis par l'application : aucun CDN, polices et icônes embarquées. Laravel reste une évolution possible si le besoin apparaît.

## Users

Développeurs, administrateurs et équipes métier qui doivent retrouver une information enfouie dans une arborescence de documents (dossiers locaux, partages réseau Windows `\\serveur\partage`, montages Linux/WSL), sous Windows comme sous Linux. Usage individuel ou en équipe, depuis un navigateur — en local, sur intranet ou sur un serveur accessible à distance.

## Product Purpose

Poseidon remplace la combinaison de commandes Unix `ls -R`, `grep -R -i`, pipes et `cat` par un seul outil : on choisit un répertoire racine, on donne un filtre (mot, phrase, liste de mots ou motif), et Poseidon dit combien de fichiers contiennent l'information, où (chemins absolu et relatif, y compris à l'intérieur des archives ZIP imbriquées), combien de fois, et permet d'afficher ou de télécharger le fichier. Au-delà de la recherche, Poseidon extrait : il ne garde que les mots, motifs ou colonnes ciblés et produit un nouveau document (CSV, JSON, TXT, XLSX), à la manière d'un SELECT avec mapping sur une base de données.

Succès : trouver vite et de façon exacte, dans tous les formats courants (TXT, MD, CSV, LOG, code, PDF, DOCX, DOC, ODT, XLSX, XLS, ZIP), et repartir avec la donnée extraite.

## Positioning

Un grep -R qui lit aussi l'intérieur des PDF, Word, Excel et ZIP imbriqués, compte les occurrences par terme, et transforme une recherche en extraction de données structurée, sans rien installer d'autre que PHP.

## Operating Context

- Racines de recherche : chemins Linux (`/home/...`, `/mnt/...`), chemins Windows (`C:\Docs`), partages UNC (`\\serveur\partage\dossier`), mélanges de slash et d'anti-slash à normaliser.
- Volumes : de quelques fichiers à des dizaines de milliers ; la vitesse compte.
- Résultats consultés à l'écran puis exportés ou téléchargés.

## Capabilities and Constraints

- Modes de filtre : phrase exacte (bloc), tous les mots (ET), au moins un mot (OU), expression régulière ; sensibilité à la casse optionnelle ; mot entier optionnel.
- Comptage d'occurrences par fichier et par terme.
- Parcours récursif des ZIP, y compris les ZIP dans les ZIP, avec limites de profondeur et de taille (anti zip-bomb).
- Affichage du contenu texte et téléchargement du fichier d'origine, y compris une entrée d'archive.
- Extraction vers un nouveau document ; pour les tableurs, sélection de colonnes (mapping).
- Aucun CDN. Accès restreint aux racines autorisées ; protection contre la traversée de répertoires.
- Contraintes d'environnement : pas d'extensions dom/xml/sqlite sur la machine de développement ; l'analyse DOCX/XLSX/ODT doit s'en passer. `pdftotext` (poppler) est utilisé s'il est présent, avec un repli en PHP pur.

## Brand Commitments

- Nom : **Poseidon**. Univers imposé par le porteur du projet : Poséidon, la mer, les profondeurs (« sois dans le thème »).
- Exigence esthétique explicite : interface soignée, structurée, professionnelle.

## Evidence on Hand

Aucune donnée réelle fournie. Les jeux de démonstration (dossier `samples/`) sont synthétiques et doivent être signalés comme tels.

## Product Principles

1. Exactitude d'abord : un résultat affiché est un résultat vérifiable (chemin, ligne, nombre d'occurrences).
2. Vitesse : lecture en flux, filtrage précoce, aucune extraction inutile sur disque.
3. Tout format, même enfoui : un fichier dans un ZIP dans un ZIP est un fichier comme un autre.
4. De la recherche à la donnée : chaque recherche peut devenir un export.
5. Autonome et sûr : aucun service externe, aucun CDN, aucun accès hors des racines autorisées.

## Accessibility & Inclusion

Interface utilisable au clavier, contrastes WCAG AA, langue française.
