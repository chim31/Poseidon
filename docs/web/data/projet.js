// Généré par docs/tools/sync.php depuis projet.json — ne pas modifier à la main.
window.POSEIDON_PROJET = {
    "meta": {
        "projet": "Poseidon",
        "version": "0.1.0",
        "miseAJour": "2026-10-17",
        "porteur": "chim31",
        "resume": "Recherche et extraction dans les fichiers : la combinaison ls -R, grep -R -i, pipes et cat, en PHP, avec PDF, Word, Excel, OpenDocument et ZIP imbriqués."
    },
    "lots": [
        {
            "id": "L1",
            "titre": "Socle et parcours",
            "objectif": "Poser une racine (locale, Windows, réseau) et parcourir toute l'arborescence comme ls -R.",
            "exigences": [
                {
                    "id": "L1-01",
                    "titre": "Racine locale, Windows et UNC",
                    "description": "Accepte /home/…, C:\\…, \\\\serveur\\partage, file://, smb://, chemins entre guillemets ; normalise slash et anti-slash.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L1-02",
                    "titre": "Parcours récursif en flux",
                    "description": "Parcours itératif (pas de récursion PHP), ordre alphabétique, protection contre les boucles de liens symboliques.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L1-03",
                    "titre": "Dossiers exclus",
                    "description": ".git, node_modules, vendor… configurables.",
                    "statut": "Terminé",
                    "priorite": "Moyenne",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L1-04",
                    "titre": "Racines autorisées",
                    "description": "Liste blanche (POSEIDON_ROOTS) contrôlée sur les chemins réels : aucun accès hors périmètre, ni par « .. », ni par lien symbolique.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L1-05",
                    "titre": "Partages réseau sous Linux",
                    "description": "Correspondance \\\\serveur\\partage → point de montage (network_mounts), message explicite si non monté.",
                    "statut": "Terminé",
                    "priorite": "Moyenne",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                }
            ]
        },
        {
            "id": "L2",
            "titre": "Formats de fichiers",
            "objectif": "Lire le texte de tous les formats courants, sans dépendance externe obligatoire.",
            "exigences": [
                {
                    "id": "L2-01",
                    "titre": "Texte, Markdown, code, logs",
                    "description": "Détection d'encodage (UTF-8, UTF-16, Windows-1252), fichiers binaires ignorés.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L2-02",
                    "titre": "PDF",
                    "description": "pdftotext (poppler) si présent, lecteur PHP de secours sinon ; numéro de page par ligne.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L2-03",
                    "titre": "Word DOCX",
                    "description": "Corps, en-têtes, pieds de page, notes ; sans ext-dom.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L2-04",
                    "titre": "Excel XLSX et ODS",
                    "description": "Valeurs de cellules feuille par feuille, chaînes partagées, tableau pour le mapping de colonnes.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L2-05",
                    "titre": "CSV / TSV",
                    "description": "Détection du séparateur, lignes et cellules.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L2-06",
                    "titre": "ODT, ODP, PPTX",
                    "description": "Paragraphes et diapositives (avec notes).",
                    "statut": "Terminé",
                    "priorite": "Moyenne",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L2-07",
                    "titre": "DOC, XLS, PPT anciens",
                    "description": "antiword si présent, sinon extraction approximative des chaînes (signalée).",
                    "statut": "Terminé",
                    "priorite": "Basse",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L2-08",
                    "titre": "RTF",
                    "description": "Mots de contrôle retirés, accents \\'xx et \\u décodés.",
                    "statut": "Terminé",
                    "priorite": "Basse",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L2-09",
                    "titre": "Cache du texte extrait",
                    "description": "PDF et Office mis en cache (clé : chemin, taille, date) : 2e recherche quasi instantanée.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L2-10",
                    "titre": "OCR des PDF scannés",
                    "description": "Tesseract optionnel pour les PDF images.",
                    "statut": "À faire",
                    "priorite": "Moyenne",
                    "responsable": "chim31",
                    "date": "",
                    "ref": "Phase 6"
                },
                {
                    "id": "L2-11",
                    "titre": "Courriels EML / MSG",
                    "description": "Corps et pièces jointes des e-mails.",
                    "statut": "À faire",
                    "priorite": "Moyenne",
                    "responsable": "chim31",
                    "date": "",
                    "ref": "Phase 6"
                }
            ]
        },
        {
            "id": "L3",
            "titre": "Archives ZIP",
            "objectif": "Un fichier dans un ZIP dans un ZIP est un fichier comme un autre.",
            "exigences": [
                {
                    "id": "L3-01",
                    "titre": "Parcours récursif des ZIP",
                    "description": "ZIP, JAR, WAR, EAR, avec imbrication (profondeur configurable).",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L3-02",
                    "titre": "Chemins chaînés",
                    "description": "Chemin absolu et relatif : archives/2025.zip › clients/nereide.zip › contrat.docx.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L3-03",
                    "titre": "Protection anti-bombe ZIP",
                    "description": "Limites de profondeur, taille par entrée, volume total, ratio, nombre d'entrées ; parties Office et flux PDF bornés.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L3-04",
                    "titre": "Téléchargement d'une entrée",
                    "description": "Une entrée d'archive imbriquée se télécharge comme un fichier normal.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/interface-web (fusionnée)"
                },
                {
                    "id": "L3-05",
                    "titre": "7z, RAR, tar.gz",
                    "description": "Autres formats d'archives (via binaires optionnels).",
                    "statut": "À faire",
                    "priorite": "Basse",
                    "responsable": "chim31",
                    "date": "",
                    "ref": "Phase 6"
                }
            ]
        },
        {
            "id": "L4",
            "titre": "Recherche",
            "objectif": "Trouver exactement, compter par terme, prouver par la ligne.",
            "exigences": [
                {
                    "id": "L4-01",
                    "titre": "Modes de filtre",
                    "description": "Tous les mots (ET), un des mots (OU), phrase exacte (bloc), expression régulière.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L4-02",
                    "titre": "Découpage de phrase en termes",
                    "description": "La phrase est découpée en mots ; « des guillemets » gardent une expression groupée.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L4-03",
                    "titre": "Occurrences par terme",
                    "description": "Compteur par fichier et par terme, totaux globaux.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L4-04",
                    "titre": "Casse, accents, mot entier",
                    "description": "Insensible à la casse et aux accents par défaut, options inverses.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L4-05",
                    "titre": "Recherche dans les noms",
                    "description": "Contenu, nom de fichier, ou les deux ; filtre de nom façon shell (*.pdf).",
                    "statut": "Terminé",
                    "priorite": "Moyenne",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L4-06",
                    "titre": "Lignes-preuves",
                    "description": "Emplacement (ligne, page, §, feuille · ligne) et position des correspondances.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L4-07",
                    "titre": "Index persistant",
                    "description": "Index SQLite FTS5 (trigrammes, sans accents) : seuls les candidats sont relus, mise à jour incrémentale ; 0,47 s sur 10 000 fichiers.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-17",
                    "ref": "feat/index-plein-texte"
                },
                {
                    "id": "L4-08",
                    "titre": "Proximité (NEAR) et recherche floue",
                    "description": "Mots à moins de N mots d'écart ; tolérance aux fautes.",
                    "statut": "À faire",
                    "priorite": "Moyenne",
                    "responsable": "chim31",
                    "date": "",
                    "ref": "Phase 7"
                },
                {
                    "id": "L4-09",
                    "titre": "Recherches enregistrées et historique",
                    "description": "Rejouer une recherche, la partager par lien.",
                    "statut": "À faire",
                    "priorite": "Moyenne",
                    "responsable": "chim31",
                    "date": "",
                    "ref": "Phase 7"
                }
            ]
        },
        {
            "id": "L5",
            "titre": "Extraction de données",
            "objectif": "De la recherche à la donnée : un SELECT avec mapping sur les fichiers.",
            "exigences": [
                {
                    "id": "L5-01",
                    "titre": "Extraire les occurrences",
                    "description": "Une ligne par valeur trouvée ; groupes capturés en colonnes (regex).",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L5-02",
                    "titre": "Extraire les lignes",
                    "description": "La ligne complète de chaque correspondance.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L5-03",
                    "titre": "Mapping de colonnes",
                    "description": "Tableurs et CSV : colonnes par lettre ou par en-tête, lignes correspondantes ou toutes.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L5-04",
                    "titre": "Formats de sortie",
                    "description": "CSV (Excel FR), XLSX natif, JSON, TXT, Markdown.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L5-05",
                    "titre": "Sécurité des exports",
                    "description": "Neutralisation des formules (=, +, -, @) dans les CSV.",
                    "statut": "Terminé",
                    "priorite": "Moyenne",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L5-06",
                    "titre": "Modèles d'extraction",
                    "description": "Enregistrer un mapping (colonnes, groupes nommés → colonnes) et le rejouer.",
                    "statut": "À faire",
                    "priorite": "Moyenne",
                    "responsable": "chim31",
                    "date": "",
                    "ref": "Phase 7"
                },
                {
                    "id": "L5-07",
                    "titre": "Rapport de recherche",
                    "description": "Export HTML/PDF de la recherche (synthèse + preuves).",
                    "statut": "À faire",
                    "priorite": "Basse",
                    "responsable": "chim31",
                    "date": "",
                    "ref": "Phase 7"
                }
            ]
        },
        {
            "id": "L6",
            "titre": "Interface web",
            "objectif": "Une interface Poseidon soignée, rapide à lire, utilisable au clavier et sur mobile.",
            "exigences": [
                {
                    "id": "L6-01",
                    "titre": "Univers « Pavillons de signaux »",
                    "description": "Chaque terme hisse son pavillon : chip, colonne, surlignage, mini-drisse.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/interface-web (fusionnée)"
                },
                {
                    "id": "L6-02",
                    "titre": "Résultats en flux",
                    "description": "Les fichiers apparaissent pendant le parcours ; annulation possible.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/interface-web (fusionnée)"
                },
                {
                    "id": "L6-03",
                    "titre": "Aperçu et navigation d'occurrence",
                    "description": "Lignes numérotées surlignées, occurrence suivante/précédente (touche N).",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/interface-web (fusionnée)"
                },
                {
                    "id": "L6-04",
                    "titre": "Onglet Tableau",
                    "description": "Grille des feuilles ; clic sur une colonne = ajout au mapping d'extraction.",
                    "statut": "Terminé",
                    "priorite": "Moyenne",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/interface-web (fusionnée)"
                },
                {
                    "id": "L6-05",
                    "titre": "Sélecteur de dossier",
                    "description": "Navigation dans les dossiers depuis le navigateur, racines récentes.",
                    "statut": "Terminé",
                    "priorite": "Moyenne",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/interface-web (fusionnée)"
                },
                {
                    "id": "L6-06",
                    "titre": "Responsive",
                    "description": "Rail et aperçu en panneaux coulissants sous 1080 px, mobile 390 px.",
                    "statut": "Terminé",
                    "priorite": "Moyenne",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/interface-web (fusionnée)"
                },
                {
                    "id": "L6-07",
                    "titre": "Aucun CDN",
                    "description": "Polices, icônes et scripts servis par l'application.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/interface-web (fusionnée)"
                },
                {
                    "id": "L6-08",
                    "titre": "Mode sombre",
                    "description": "Thème abysse pour les postes en environnement sombre.",
                    "statut": "À faire",
                    "priorite": "Basse",
                    "responsable": "chim31",
                    "date": "",
                    "ref": "Phase 8"
                }
            ]
        },
        {
            "id": "L7",
            "titre": "Robustesse et sécurité",
            "objectif": "Ne jamais tourner en rond, ne jamais sortir du périmètre, toujours prévenir.",
            "exigences": [
                {
                    "id": "L7-01",
                    "titre": "Fichiers protégés signalés",
                    "description": "ZIP chiffrés, Office chiffrés, PDF chiffrés : ignorés et signalés par notification + panneau de détail.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/interface-web (fusionnée)"
                },
                {
                    "id": "L7-02",
                    "titre": "Droits refusés et fichiers corrompus",
                    "description": "Dossiers/fichiers illisibles signalés, le parcours continue.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L7-03",
                    "titre": "Délais maximaux",
                    "description": "pdftotext interrompu au-delà de 30 s ; fichiers trop gros ignorés.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L7-04",
                    "titre": "Identifiants signés",
                    "description": "HMAC : impossible de forger un lien pour lire un fichier arbitraire.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/interface-web (fusionnée)"
                },
                {
                    "id": "L7-05",
                    "titre": "Mot de passe d'accès",
                    "description": "POSEIDON_PASSWORD, session HttpOnly SameSite=Strict, en-tête anti-CSRF.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/interface-web (fusionnée)"
                },
                {
                    "id": "L7-06",
                    "titre": "En-têtes de sécurité",
                    "description": "CSP stricte (self), nosniff, X-Frame-Options, téléchargements en sandbox.",
                    "statut": "Terminé",
                    "priorite": "Moyenne",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/interface-web (fusionnée)"
                },
                {
                    "id": "L7-08",
                    "titre": "Protection DNS rebinding",
                    "description": "Sans mot de passe, seuls localhost et les hôtes déclarés (POSEIDON_HOSTS) sont servis ; en-tête X-Poseidon exigé sur l'API.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-17",
                    "ref": "feat/interface-web (fusionnée)"
                },
                {
                    "id": "L7-07",
                    "titre": "Comptes et rôles",
                    "description": "Plusieurs utilisateurs, racines par rôle, journal d'audit.",
                    "statut": "À faire",
                    "priorite": "Moyenne",
                    "responsable": "chim31",
                    "date": "",
                    "ref": "Phase 8"
                }
            ]
        },
        {
            "id": "L8",
            "titre": "Comparaison (git diff)",
            "objectif": "Comparer deux fichiers trouvés, quel que soit leur format.",
            "exigences": [
                {
                    "id": "L8-01",
                    "titre": "Diff ligne à ligne",
                    "description": "Algorithme de Myers, blocs avec contexte, format unifié copiable.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L8-02",
                    "titre": "Comparer dans l'interface",
                    "description": "Cocher deux fichiers → vue diff colorée avec numéros de ligne.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/interface-web (fusionnée)"
                },
                {
                    "id": "L8-03",
                    "titre": "poseidon diff en CLI",
                    "description": "Sortie colorée façon git diff, code retour 1 si différent.",
                    "statut": "Terminé",
                    "priorite": "Moyenne",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L8-04",
                    "titre": "Diff entre deux recherches",
                    "description": "Instantanés : fichiers apparus, disparus, occurrences en hausse.",
                    "statut": "À faire",
                    "priorite": "Moyenne",
                    "responsable": "chim31",
                    "date": "",
                    "ref": "Phase 9"
                },
                {
                    "id": "L8-05",
                    "titre": "Historique Git",
                    "description": "Dans un dépôt Git : rechercher dans les versions passées, diff entre commits.",
                    "statut": "À faire",
                    "priorite": "Basse",
                    "responsable": "chim31",
                    "date": "",
                    "ref": "Phase 9"
                }
            ]
        },
        {
            "id": "L9",
            "titre": "Exploitation et documentation",
            "objectif": "Installer, lancer et faire évoluer Poseidon sans aide.",
            "exigences": [
                {
                    "id": "L9-01",
                    "titre": "Ligne de commande",
                    "description": "search, extract, diff, cat, serve, info, demo.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L9-02",
                    "titre": "Lanceurs Windows .bat",
                    "description": "poseidon.bat (serveur + navigateur), bin\\poseidon.bat (CLI), poseidon.sh.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/interface-web (fusionnée)"
                },
                {
                    "id": "L9-03",
                    "titre": "Tests automatisés",
                    "description": "Suite sans dépendance : 69 vérifications (dont équivalence avec/sans index), lancée en CI sous Linux et Windows.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "feat/moteur-recherche (fusionnée)"
                },
                {
                    "id": "L9-04",
                    "titre": "Documentation et suivi",
                    "description": "docs/ structuré, context.md, cahier des charges et roadmap web.",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-16",
                    "ref": "docs/fondations (fusionnée)"
                },
                {
                    "id": "L9-05",
                    "titre": "Validation et fusion",
                    "description": "Relecture des PR par le porteur, fusion dans main (PR #1 et #4).",
                    "statut": "Terminé",
                    "priorite": "Haute",
                    "responsable": "chim31",
                    "date": "2026-10-17",
                    "ref": "PR #1, #4"
                },
                {
                    "id": "L9-06",
                    "titre": "Image Docker et guide HTTPS",
                    "description": "Déploiement serveur derrière Nginx/Caddy.",
                    "statut": "À faire",
                    "priorite": "Moyenne",
                    "responsable": "chim31",
                    "date": "",
                    "ref": "Phase 8"
                },
                {
                    "id": "L9-07",
                    "titre": "Intégration continue",
                    "description": "Workflow GitHub Actions prêt (Linux + Windows, PHP 8.2/8.4) ; ne démarre pas tant que le compte GitHub est bloqué pour facturation. Vérifié à la main : Linux PHP 8.4 (69 tests) et Windows PHP 8.3 (66 tests).",
                    "statut": "Bloqué",
                    "priorite": "Moyenne",
                    "responsable": "chim31",
                    "date": "2026-10-17",
                    "ref": "feat/index-plein-texte"
                }
            ]
        }
    ],
    "phases": [
        {
            "id": "P0",
            "titre": "Cadrage",
            "debut": "2026-10-16",
            "fin": "2026-10-16",
            "statut": "Terminé",
            "livrables": [
                "Besoin reformulé (PRODUCT.md)",
                "Choix de stack : PHP natif",
                "Direction visuelle choisie : Pavillons de signaux"
            ]
        },
        {
            "id": "P1",
            "titre": "Moteur de recherche",
            "debut": "2026-10-16",
            "fin": "2026-10-16",
            "statut": "Terminé",
            "livrables": [
                "Parcours + ZIP récursifs",
                "11 familles de formats",
                "Modes ET / OU / phrase / regex",
                "Extraction CSV/XLSX/JSON/TXT/MD",
                "Diff Myers",
                "CLI + 62 tests"
            ]
        },
        {
            "id": "P2",
            "titre": "Interface web",
            "debut": "2026-10-16",
            "fin": "2026-10-16",
            "statut": "Terminé",
            "livrables": [
                "API JSON + flux NDJSON",
                "Interface Pavillons de signaux",
                "Notifications de fichiers protégés",
                "Lanceurs .bat / .sh"
            ]
        },
        {
            "id": "P3",
            "titre": "Documentation et suivi",
            "debut": "2026-10-16",
            "fin": "2026-10-16",
            "statut": "Terminé",
            "livrables": [
                "context.md",
                "docs/ en 3 volets",
                "Cahier des charges et roadmap web"
            ]
        },
        {
            "id": "P4",
            "titre": "Validation et fusion",
            "debut": "2026-10-16",
            "fin": "2026-10-17",
            "statut": "Terminé",
            "livrables": [
                "Durcissement sécurité (7 corrections)",
                "Relecture et fusion dans main (PR #1, #4)",
                "Essai Windows validé par le porteur (correctif du port, PR #6)",
                "Étiquette v0.1.0"
            ]
        },
        {
            "id": "P5",
            "titre": "Performance et fiabilité",
            "debut": "2026-10-17",
            "fin": "2026-10-17",
            "statut": "Terminé",
            "livrables": [
                "Index plein texte SQLite FTS5",
                "Intégration continue Linux + Windows prête (bloquée par la facturation GitHub ; tests Windows passés à la main)",
                "Banc d'essai : 10 000 fichiers en 5,2 s à froid, 0,47 s indexé",
                "Parallélisation écartée (remplacée par l'index)"
            ]
        },
        {
            "id": "P6",
            "titre": "Formats étendus",
            "debut": "2026-10-17",
            "fin": "2026-12-18",
            "statut": "En cours",
            "livrables": [
                "OCR des PDF scannés (Tesseract)",
                "Courriels EML / MSG",
                "Archives 7z, RAR, tar.gz"
            ]
        },
        {
            "id": "P7",
            "titre": "Extraction avancée",
            "debut": "2027-01-04",
            "fin": "2027-01-29",
            "statut": "À faire",
            "livrables": [
                "Modèles d'extraction enregistrés",
                "Groupes nommés → colonnes",
                "Recherche de proximité et floue",
                "Recherches enregistrées, rapport HTML/PDF"
            ]
        },
        {
            "id": "P8",
            "titre": "Équipe et déploiement",
            "debut": "2027-02-01",
            "fin": "2027-02-26",
            "statut": "À faire",
            "livrables": [
                "Comptes, rôles, journal d'audit",
                "Image Docker, guide HTTPS",
                "Mode sombre"
            ]
        },
        {
            "id": "P9",
            "titre": "Comparaison avancée → v1.0",
            "debut": "2027-03-01",
            "fin": "2027-03-26",
            "statut": "À faire",
            "livrables": [
                "Diff entre deux recherches",
                "Historique Git",
                "Version 1.0"
            ]
        }
    ],
    "propositions": [
        {
            "type": "Correction",
            "titre": "Préciser « rapide »",
            "texte": "En PHP, la vitesse vient du flux, du pré-filtre et du cache, pas du langage. Au-delà de ~50 000 fichiers, il faut un index persistant (Phase 5). À fixer dans le cahier des charges : un objectif chiffré (ex. 10 000 fichiers en moins de 10 s à froid, moins de 2 s à chaud)."
        },
        {
            "type": "Correction",
            "titre": "Partages réseau sous Linux",
            "texte": "PHP ne lit pas \\\\serveur\\partage directement sous Linux/WSL : le partage doit être monté (CIFS) puis déclaré dans network_mounts. Sous Windows, l'accès UNC est natif."
        },
        {
            "type": "Correction",
            "titre": "Phrase sur plusieurs lignes",
            "texte": "Une phrase coupée par un retour à la ligne (fréquent dans les PDF mis en page) n'est pas comptée en mode « Phrase exacte ». Contournement : mode « Tous les mots ». Correctif prévu avec la recherche de proximité."
        },
        {
            "type": "Correction",
            "titre": "PDF scannés et anciens formats",
            "texte": "Un PDF image n'a pas de texte : sans OCR, il est invisible. Les .doc/.xls binaires sont lus de façon approximative. À annoncer clairement aux utilisateurs."
        },
        {
            "type": "Correction",
            "titre": "Exposition sur Internet",
            "texte": "Un outil qui lit des fichiers serveur ne doit jamais être exposé sans mot de passe, HTTPS (proxy inverse) et liste de racines autorisées."
        },
        {
            "type": "Fonctionnalité",
            "titre": "Comparaison façon git diff",
            "texte": "Livrée : diff entre deux fichiers trouvés, y compris PDF contre DOCX. Prochaine étape : diff entre deux recherches et dans l'historique Git."
        },
        {
            "type": "Fonctionnalité",
            "titre": "Modèles d'extraction",
            "texte": "Enregistrer un « SELECT » (filtre + colonnes + format) pour le rejouer chaque mois sur un nouveau dossier."
        },
        {
            "type": "Fonctionnalité",
            "titre": "Index plein texte",
            "texte": "SQLite FTS5 : recherche instantanée sur des centaines de milliers de documents, mise à jour incrémentale par date de modification."
        },
        {
            "type": "Fonctionnalité",
            "titre": "OCR",
            "texte": "Tesseract optionnel pour les PDF et images scannés."
        },
        {
            "type": "Fonctionnalité",
            "titre": "Surveillance",
            "texte": "Relancer une recherche planifiée et notifier les nouveaux fichiers correspondants."
        },
        {
            "type": "Fonctionnalité",
            "titre": "Rapports",
            "texte": "Exporter la recherche (synthèse, fichiers, preuves) en HTML ou PDF pour un audit."
        },
        {
            "type": "Fonctionnalité",
            "titre": "Recherche de proximité et floue",
            "texte": "« contrat NEAR/5 résiliation », tolérance aux fautes de frappe."
        }
    ]
};
