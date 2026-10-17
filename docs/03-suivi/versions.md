# Journal des versions

## 1.0.0 — 2026-10-17

Toutes les phases de la roadmap (P0 à P9) sont livrées.

**Recherche**
- Racines locales, Windows, UNC (`\\serveur\partage`), `file://`, `smb://` ; parcours récursif en flux.
- Modes : tous les mots, un des mots, phrase exacte, regex, **proximité** (sur plusieurs lignes) ; casse, accents, mot entier, **fautes de frappe**.
- Occurrences par terme, lignes-preuves, recherche dans les noms.
- **Index plein texte** SQLite FTS5 : seuls les fichiers candidats sont relus.

**Formats**
- TXT, MD, CSV, logs, code, PDF (OCR si scanné), DOCX, DOC, ODT, RTF, XLSX, XLS, ODS, PPTX, ODP, images (OCR).
- Conteneurs imbriqués : ZIP, 7z, RAR, tar, gz, courriels `.eml` / `.msg` et leurs pièces jointes.
- Fichiers protégés, illisibles ou piégés : signalés, jamais bloquants.

**Extraction et comparaison**
- Occurrences, lignes, colonnes (lettres ou en-têtes), liste de fichiers → CSV, XLSX, JSON, TXT, MD ; groupes nommés → colonnes ; **modèles** rejouables.
- Rapport HTML imprimable en PDF.
- Diff façon `git diff` entre deux fichiers ; **changements entre deux recherches** ; **historique Git** sur le texte extrait.

**Interface et exploitation**
- Interface « Pavillons de signaux », résultats en flux, aperçu, tableur, historique et recherches enregistrées, lien de partage, thème sombre.
- Comptes et rôles, racines par compte, anti-forçage, journal d'audit.
- CLI complète, lanceurs `.bat` / `.sh`, image Docker + Caddy (HTTPS).
- 127 tests (Linux), 122 (Windows), exécutés aussi dans l'image Docker.

**Connu**
- Intégration continue GitHub prête mais non exécutée : compte GitHub bloqué pour facturation.

## 0.1.0 — 2026-10-16

Première version : moteur multi-formats, ZIP imbriqués, extraction, diff, interface web, lanceurs, documentation.
