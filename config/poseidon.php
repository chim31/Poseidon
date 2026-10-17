<?php

/*
 * Configuration de Poseidon.
 *
 * Toutes les valeurs peuvent être surchargées par des variables d'environnement
 * (voir docs/01-general/demarrage.md). Ne jamais écrire de secret ici :
 * utiliser POSEIDON_PASSWORD dans l'environnement.
 */

return [
    // Racines autorisées. Vide = toute racine lisible est acceptée (usage local uniquement).
    // Exemple : ['/home/moi/Documents', 'D:\\Archives', '\\\\srv-archives\\juridique']
    'allowed_roots' => array_values(array_filter(explode(';', (string) getenv('POSEIDON_ROOTS')))),

    // Correspondance partage réseau -> point de montage (utile sous Linux/WSL).
    // Exemple : ['\\\\srv-archives\\juridique' => '/mnt/juridique']
    'network_mounts' => [],

    // Noms d'hôte acceptés en plus de localhost / 127.0.0.1 quand aucun mot de passe n'est défini
    // (protection contre le « DNS rebinding »). Exemple : POSEIDON_HOSTS="poste-42;192.168.1.20"
    'allowed_hosts' => array_values(array_filter(explode(';', (string) getenv('POSEIDON_HOSTS')))),

    // Mot de passe d'accès à l'interface web. Vide = pas d'authentification.
    'password' => (string) getenv('POSEIDON_PASSWORD'),

    // Dossiers ignorés pendant le parcours.
    'excluded_dirs' => ['.git', '.svn', '.hg', 'node_modules', 'vendor', '__pycache__', '.idea', '.vscode'],

    // Taille maximale d'un fichier analysé (octets).
    'max_file_size' => (int) (getenv('POSEIDON_MAX_FILE_SIZE') ?: 200 * 1024 * 1024),

    // Archives ZIP : profondeur d'imbrication, taille d'une entrée, volume total décompressé, ratio max.
    'zip' => [
        'max_depth' => 5,
        'max_entry_size' => 100 * 1024 * 1024,
        'max_total_size' => 1024 * 1024 * 1024,
        'max_ratio' => 200,
        'max_entries' => 20000,
    ],

    // Nombre maximal de lignes-preuves conservées par fichier.
    'max_hits_per_file' => 50,

    // Cache du texte extrait (PDF, DOCX, XLSX...). Dossier vide = cache désactivé.
    'cache_dir' => POSEIDON_ROOT . '/var/cache',

    // Délai maximal d'analyse d'un PDF (secondes) : un fichier piégé ne bloque jamais le scan.
    'pdf_timeout' => 30,

    // OCR (Tesseract, optionnel) : PDF scannés et images. Désactivable : 'enabled' => false.
    'ocr' => [
        'enabled' => getenv('POSEIDON_OCR') !== '0',
        'tesseract' => getenv('POSEIDON_TESSERACT') ?: null,
        'languages' => getenv('POSEIDON_OCR_LANG') ?: 'fra+eng',
        'max_pages' => 30,
        'timeout' => 60,
    ],

    // Binaires externes optionnels (détectés automatiquement si null).
    'pdftotext' => getenv('POSEIDON_PDFTOTEXT') ?: null,
    'antiword' => getenv('POSEIDON_ANTIWORD') ?: null,
];
