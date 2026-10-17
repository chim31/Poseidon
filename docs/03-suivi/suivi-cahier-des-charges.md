# Suivi du cahier des charges

> Généré par `php docs/tools/sync.php` depuis [`docs/web/data/projet.json`](../web/data/projet.json) — modifier le JSON, pas ce fichier.
> Version web : [cahier-des-charges.html](../web/cahier-des-charges.html) · [roadmap.html](../web/roadmap.html)

**Mise à jour :** 2026-10-17 · **Version :** 0.1.0

## Tableau de bord

Avancement global : **95 %** `███████████████████░` — 65 exigences : 62 terminées, 0 en cours, 2 à faire, 1 bloquées.

| Lot | Intitulé | Terminé | En cours | À faire | Avancement |
| --- | --- | ---: | ---: | ---: | --- |
| L1 | Socle et parcours | 5 | 0 | 0 | 100 % |
| L2 | Formats de fichiers | 11 | 0 | 0 | 100 % |
| L3 | Archives ZIP | 5 | 0 | 0 | 100 % |
| L4 | Recherche | 9 | 0 | 0 | 100 % |
| L5 | Extraction de données | 7 | 0 | 0 | 100 % |
| L6 | Interface web | 8 | 0 | 0 | 100 % |
| L7 | Robustesse et sécurité | 8 | 0 | 0 | 100 % |
| L8 | Comparaison (git diff) | 3 | 0 | 2 | 60 % |
| L9 | Exploitation et documentation | 6 | 0 | 0 | 86 % |

## L1 — Socle et parcours

_Poser une racine (locale, Windows, réseau) et parcourir toute l'arborescence comme ls -R._

| ID | Exigence | Statut | Priorité | Responsable | Date | Référence |
| --- | --- | --- | --- | --- | --- | --- |
| L1-01 | **Racine locale, Windows et UNC** — Accepte /home/…, C:\…, \\serveur\partage, file://, smb://, chemins entre guillemets ; normalise slash et anti-slash. | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L1-02 | **Parcours récursif en flux** — Parcours itératif (pas de récursion PHP), ordre alphabétique, protection contre les boucles de liens symboliques. | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L1-03 | **Dossiers exclus** — .git, node_modules, vendor… configurables. | Terminé | Moyenne | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L1-04 | **Racines autorisées** — Liste blanche (POSEIDON_ROOTS) contrôlée sur les chemins réels : aucun accès hors périmètre, ni par « .. », ni par lien symbolique. | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L1-05 | **Partages réseau sous Linux** — Correspondance \\serveur\partage → point de montage (network_mounts), message explicite si non monté. | Terminé | Moyenne | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |

## L2 — Formats de fichiers

_Lire le texte de tous les formats courants, sans dépendance externe obligatoire._

| ID | Exigence | Statut | Priorité | Responsable | Date | Référence |
| --- | --- | --- | --- | --- | --- | --- |
| L2-01 | **Texte, Markdown, code, logs** — Détection d'encodage (UTF-8, UTF-16, Windows-1252), fichiers binaires ignorés. | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L2-02 | **PDF** — pdftotext (poppler) si présent, lecteur PHP de secours sinon ; numéro de page par ligne. | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L2-03 | **Word DOCX** — Corps, en-têtes, pieds de page, notes ; sans ext-dom. | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L2-04 | **Excel XLSX et ODS** — Valeurs de cellules feuille par feuille, chaînes partagées, tableau pour le mapping de colonnes. | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L2-05 | **CSV / TSV** — Détection du séparateur, lignes et cellules. | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L2-06 | **ODT, ODP, PPTX** — Paragraphes et diapositives (avec notes). | Terminé | Moyenne | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L2-07 | **DOC, XLS, PPT anciens** — antiword si présent, sinon extraction approximative des chaînes (signalée). | Terminé | Basse | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L2-08 | **RTF** — Mots de contrôle retirés, accents \'xx et \u décodés. | Terminé | Basse | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L2-09 | **Cache du texte extrait** — PDF et Office mis en cache (clé : chemin, taille, date) : 2e recherche quasi instantanée. | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L2-10 | **OCR des PDF scannés** — Tesseract (optionnel) pour les PDF scannés (rendus par pdftoppm) et les images ; borné en pages et en temps, mis en cache. | Terminé | Moyenne | chim31 | 2026-10-17 | feat/formats-etendus |
| L2-11 | **Courriels EML / MSG** — .eml (MIME) et .msg (Outlook, lecteur OLE maison) : en-têtes, corps, pièces jointes parcourues comme des entrées d'archive. | Terminé | Moyenne | chim31 | 2026-10-17 | feat/formats-etendus |

## L3 — Archives ZIP

_Un fichier dans un ZIP dans un ZIP est un fichier comme un autre._

| ID | Exigence | Statut | Priorité | Responsable | Date | Référence |
| --- | --- | --- | --- | --- | --- | --- |
| L3-01 | **Parcours récursif des ZIP** — ZIP, JAR, WAR, EAR, avec imbrication (profondeur configurable). | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L3-02 | **Chemins chaînés** — Chemin absolu et relatif : archives/2025.zip › clients/nereide.zip › contrat.docx. | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L3-03 | **Protection anti-bombe ZIP** — Limites de profondeur, taille par entrée, volume total, ratio, nombre d'entrées ; parties Office et flux PDF bornés. | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L3-04 | **Téléchargement d'une entrée** — Une entrée d'archive imbriquée se télécharge comme un fichier normal. | Terminé | Haute | chim31 | 2026-10-16 | feat/interface-web (fusionnée) |
| L3-05 | **7z, RAR, tar.gz** — 7z, RAR, ISO, CAB, tar.* via bsdtar (natif sous Windows 10/11) ; tar et tar.gz en PHP pur à défaut ; .gz simple. | Terminé | Basse | chim31 | 2026-10-17 | feat/formats-etendus |

## L4 — Recherche

_Trouver exactement, compter par terme, prouver par la ligne._

| ID | Exigence | Statut | Priorité | Responsable | Date | Référence |
| --- | --- | --- | --- | --- | --- | --- |
| L4-01 | **Modes de filtre** — Tous les mots (ET), un des mots (OU), phrase exacte (bloc), expression régulière. | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L4-02 | **Découpage de phrase en termes** — La phrase est découpée en mots ; « des guillemets » gardent une expression groupée. | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L4-03 | **Occurrences par terme** — Compteur par fichier et par terme, totaux globaux. | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L4-04 | **Casse, accents, mot entier** — Insensible à la casse et aux accents par défaut, options inverses. | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L4-05 | **Recherche dans les noms** — Contenu, nom de fichier, ou les deux ; filtre de nom façon shell (*.pdf). | Terminé | Moyenne | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L4-06 | **Lignes-preuves** — Emplacement (ligne, page, §, feuille · ligne) et position des correspondances. | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L4-07 | **Index persistant** — Index SQLite FTS5 (trigrammes, sans accents) : seuls les candidats sont relus, mise à jour incrémentale ; 0,47 s sur 10 000 fichiers. | Terminé | Haute | chim31 | 2026-10-17 | feat/index-plein-texte |
| L4-08 | **Proximité (NEAR) et recherche floue** — Mode proximité (N mots, sur plusieurs lignes) et recherche floue (Damerau-Levenshtein, sans accents). | Terminé | Moyenne | chim31 | 2026-10-17 | feat/extraction-avancee |
| L4-09 | **Recherches enregistrées et historique** — Historique et recherches enregistrées (navigateur), lien de partage qui rejoue la recherche. | Terminé | Moyenne | chim31 | 2026-10-17 | feat/extraction-avancee |

## L5 — Extraction de données

_De la recherche à la donnée : un SELECT avec mapping sur les fichiers._

| ID | Exigence | Statut | Priorité | Responsable | Date | Référence |
| --- | --- | --- | --- | --- | --- | --- |
| L5-01 | **Extraire les occurrences** — Une ligne par valeur trouvée ; groupes capturés en colonnes (regex). | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L5-02 | **Extraire les lignes** — La ligne complète de chaque correspondance. | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L5-03 | **Mapping de colonnes** — Tableurs et CSV : colonnes par lettre ou par en-tête, lignes correspondantes ou toutes. | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L5-04 | **Formats de sortie** — CSV (Excel FR), XLSX natif, JSON, TXT, Markdown. | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L5-05 | **Sécurité des exports** — Neutralisation des formules (=, +, -, @) dans les CSV. | Terminé | Moyenne | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L5-06 | **Modèles d'extraction** — Modèles enregistrés (filtre + options + extraction, sans racine) ; groupes nommés de regex → colonnes. | Terminé | Moyenne | chim31 | 2026-10-17 | feat/extraction-avancee |
| L5-07 | **Rapport de recherche** — Rapport HTML autonome (synthèse, fichiers, preuves, ignorés), imprimable en PDF. | Terminé | Basse | chim31 | 2026-10-17 | feat/extraction-avancee |

## L6 — Interface web

_Une interface Poseidon soignée, rapide à lire, utilisable au clavier et sur mobile._

| ID | Exigence | Statut | Priorité | Responsable | Date | Référence |
| --- | --- | --- | --- | --- | --- | --- |
| L6-01 | **Univers « Pavillons de signaux »** — Chaque terme hisse son pavillon : chip, colonne, surlignage, mini-drisse. | Terminé | Haute | chim31 | 2026-10-16 | feat/interface-web (fusionnée) |
| L6-02 | **Résultats en flux** — Les fichiers apparaissent pendant le parcours ; annulation possible. | Terminé | Haute | chim31 | 2026-10-16 | feat/interface-web (fusionnée) |
| L6-03 | **Aperçu et navigation d'occurrence** — Lignes numérotées surlignées, occurrence suivante/précédente (touche N). | Terminé | Haute | chim31 | 2026-10-16 | feat/interface-web (fusionnée) |
| L6-04 | **Onglet Tableau** — Grille des feuilles ; clic sur une colonne = ajout au mapping d'extraction. | Terminé | Moyenne | chim31 | 2026-10-16 | feat/interface-web (fusionnée) |
| L6-05 | **Sélecteur de dossier** — Navigation dans les dossiers depuis le navigateur, racines récentes. | Terminé | Moyenne | chim31 | 2026-10-16 | feat/interface-web (fusionnée) |
| L6-06 | **Responsive** — Rail et aperçu en panneaux coulissants sous 1080 px, mobile 390 px. | Terminé | Moyenne | chim31 | 2026-10-16 | feat/interface-web (fusionnée) |
| L6-07 | **Aucun CDN** — Polices, icônes et scripts servis par l'application. | Terminé | Haute | chim31 | 2026-10-16 | feat/interface-web (fusionnée) |
| L6-08 | **Mode sombre** — Thème « abysses » : automatique (système) ou choisi, mêmes pavillons et surlignage. | Terminé | Basse | chim31 | 2026-10-17 | feat/equipe-deploiement |

## L7 — Robustesse et sécurité

_Ne jamais tourner en rond, ne jamais sortir du périmètre, toujours prévenir._

| ID | Exigence | Statut | Priorité | Responsable | Date | Référence |
| --- | --- | --- | --- | --- | --- | --- |
| L7-01 | **Fichiers protégés signalés** — ZIP chiffrés, Office chiffrés, PDF chiffrés : ignorés et signalés par notification + panneau de détail. | Terminé | Haute | chim31 | 2026-10-16 | feat/interface-web (fusionnée) |
| L7-02 | **Droits refusés et fichiers corrompus** — Dossiers/fichiers illisibles signalés, le parcours continue. | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L7-03 | **Délais maximaux** — pdftotext interrompu au-delà de 30 s ; fichiers trop gros ignorés. | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L7-04 | **Identifiants signés** — HMAC : impossible de forger un lien pour lire un fichier arbitraire. | Terminé | Haute | chim31 | 2026-10-16 | feat/interface-web (fusionnée) |
| L7-05 | **Mot de passe d'accès** — POSEIDON_PASSWORD, session HttpOnly SameSite=Strict, en-tête anti-CSRF. | Terminé | Haute | chim31 | 2026-10-16 | feat/interface-web (fusionnée) |
| L7-06 | **En-têtes de sécurité** — CSP stricte (self), nosniff, X-Frame-Options, téléchargements en sandbox. | Terminé | Moyenne | chim31 | 2026-10-16 | feat/interface-web (fusionnée) |
| L7-08 | **Protection DNS rebinding** — Sans mot de passe, seuls localhost et les hôtes déclarés (POSEIDON_HOSTS) sont servis ; en-tête X-Poseidon exigé sur l'API. | Terminé | Haute | chim31 | 2026-10-17 | feat/interface-web (fusionnée) |
| L7-07 | **Comptes et rôles** — Comptes (mots de passe hachés), rôles admin/lecteur, racines par compte, anti-forçage, journal d'audit consultable. | Terminé | Moyenne | chim31 | 2026-10-17 | feat/equipe-deploiement |

## L8 — Comparaison (git diff)

_Comparer deux fichiers trouvés, quel que soit leur format._

| ID | Exigence | Statut | Priorité | Responsable | Date | Référence |
| --- | --- | --- | --- | --- | --- | --- |
| L8-01 | **Diff ligne à ligne** — Algorithme de Myers, blocs avec contexte, format unifié copiable. | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L8-02 | **Comparer dans l'interface** — Cocher deux fichiers → vue diff colorée avec numéros de ligne. | Terminé | Haute | chim31 | 2026-10-16 | feat/interface-web (fusionnée) |
| L8-03 | **poseidon diff en CLI** — Sortie colorée façon git diff, code retour 1 si différent. | Terminé | Moyenne | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L8-04 | **Diff entre deux recherches** — Instantanés : fichiers apparus, disparus, occurrences en hausse. | À faire | Moyenne | chim31 | — | Phase 9 |
| L8-05 | **Historique Git** — Dans un dépôt Git : rechercher dans les versions passées, diff entre commits. | À faire | Basse | chim31 | — | Phase 9 |

## L9 — Exploitation et documentation

_Installer, lancer et faire évoluer Poseidon sans aide._

| ID | Exigence | Statut | Priorité | Responsable | Date | Référence |
| --- | --- | --- | --- | --- | --- | --- |
| L9-01 | **Ligne de commande** — search, extract, diff, cat, serve, info, demo. | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L9-02 | **Lanceurs Windows .bat** — poseidon.bat (serveur + navigateur), bin\poseidon.bat (CLI), poseidon.sh. | Terminé | Haute | chim31 | 2026-10-16 | feat/interface-web (fusionnée) |
| L9-03 | **Tests automatisés** — Suite sans dépendance : 111 vérifications (Linux), aussi exécutée sous Windows et dans l'image Docker. | Terminé | Haute | chim31 | 2026-10-16 | feat/moteur-recherche (fusionnée) |
| L9-04 | **Documentation et suivi** — docs/ structuré, context.md, cahier des charges et roadmap web. | Terminé | Haute | chim31 | 2026-10-16 | docs/fondations (fusionnée) |
| L9-05 | **Validation et fusion** — Relecture des PR par le porteur, fusion dans main (PR #1 et #4). | Terminé | Haute | chim31 | 2026-10-17 | PR #1, #4 |
| L9-06 | **Image Docker et guide HTTPS** — Image Docker (Debian 13, OCR, 7z, index) + Caddy HTTPS ; guides Nginx, Apache, Windows Server. | Terminé | Moyenne | chim31 | 2026-10-17 | feat/equipe-deploiement |
| L9-07 | **Intégration continue** — Workflow GitHub Actions prêt (Linux + Windows, PHP 8.2/8.4) ; ne démarre pas tant que le compte GitHub est bloqué pour facturation. Vérifié à la main : Linux PHP 8.4 (69 tests) et Windows PHP 8.3 (66 tests). | Bloqué | Moyenne | chim31 | 2026-10-17 | feat/index-plein-texte |
