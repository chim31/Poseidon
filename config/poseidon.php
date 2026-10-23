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

    // Sans compte ni mot de passe, Poseidon ne répond qu'à la machine locale (adresse et hôte locaux).
    // Ouvrir au réseau SANS authentification est déconseillé : il faut POSEIDON_INSECURE_NO_AUTH=1,
    // et les noms d'hôte acceptés se déclarent dans POSEIDON_HOSTS="poste-42;192.168.1.20".
    'insecure_no_auth' => getenv('POSEIDON_INSECURE_NO_AUTH') === '1',
    'allowed_hosts' => array_values(array_filter(explode(';', (string) getenv('POSEIDON_HOSTS')))),

    // Mot de passe d'accès à l'interface web. Vide = pas d'authentification.
    'password' => (string) getenv('POSEIDON_PASSWORD'),

    // Dossiers ignorés pendant le parcours.
    'excluded_dirs' => ['.git', '.svn', '.hg', 'node_modules', 'vendor', '__pycache__', '.idea', '.vscode'],

    // Taille maximale d'un fichier analysé (octets).
    'max_file_size' => (int) (getenv('POSEIDON_MAX_FILE_SIZE') ?: 200 * 1024 * 1024),

    // Comparaison de dossiers et Git diff : nombre maximal de fichiers différents affichés.
    'diff_max_files' => (int) (getenv('POSEIDON_DIFF_MAX_FILES') ?: 2000),

    // Archives ZIP : profondeur d'imbrication, taille d'une entrée, volume total décompressé, ratio max.
    'zip' => [
        'max_depth' => 5,
        'max_entry_size' => 100 * 1024 * 1024,
        'max_total_size' => 1024 * 1024 * 1024,
        'max_ratio' => 200,
        'max_entries' => 20000,
    ],

    // Jeu d'échantillons de démonstration (samples/demo), généré à la demande. POSEIDON_DEMO=0 pour le désactiver (serveurs).
    'demo' => getenv('POSEIDON_DEMO') !== '0',

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

    // Historique Git : dépôts appartenant à un autre compte système mais jugés sûrs (ex. dossier monté dans Docker).
    // Les autres sont refusés par Git (protection « safe.directory »). Séparateur « ; ».
    'git_trusted_repos' => array_values(array_filter(explode(';', (string) getenv('POSEIDON_GIT_TRUSTED')))),

    // Binaires externes optionnels (détectés automatiquement si null).
    'pdftotext' => getenv('POSEIDON_PDFTOTEXT') ?: null,
    'antiword' => getenv('POSEIDON_ANTIWORD') ?: null,
];
