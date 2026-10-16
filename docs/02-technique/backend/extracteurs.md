# Extracteurs

Chaque extracteur transforme une `Source` en `Document` : une liste de lignes `[emplacement, texte]` et, pour les tableurs, des tableaux de cellules.

## `Extractor\Document`

| Propriété | Type | Exemple |
| --- | --- | --- |
| `lines` | `list<[string, string]>` | `['p. 3', 'Article 12 - Résiliation']`, `['T3 · L4', 'PF552190 \| Protée & Fils \| …']` |
| `tables` | `list<{name, rows}>` | `rows[numéro de ligne][index de colonne] = valeur` |
| `warnings` | `list<string>` | `Extraction approximative (format binaire ancien).` |

## Formats

| Classe | Extensions | Méthode | Emplacement | Protégé détecté |
| --- | --- | --- | --- | --- |
| `PlainTextExtractor` | txt, md, log, json, xml, html, code, config… | Décodage UTF-8/16/1252 ; binaire ignoré | `ligne N` | — |
| `CsvExtractor` | csv, tsv | Séparateur détecté (`,` `;` tab `\|`), `str_getcsv` | `ligne N` | — |
| `WordExtractor` | docx, docm, dotx | `word/document.xml` + en-têtes, pieds, notes ; `<w:t>`, tabulations | `§ N`, `en-tête`, `pied de page`, `note` | Oui (conteneur OLE chiffré) |
| `SpreadsheetExtractor` | xlsx, xlsm, xltx, ods | Feuilles, chaînes partagées, types `s`/`b`/`inlineStr` ; ODS avec répétitions | `Feuille · LN` | Oui |
| `OpenDocumentExtractor` | odt, odp, pptx, pptm | `content.xml` (`text:p`/`text:h`) ; diapositives et notes PPTX | `§ N`, `diapo N` | Oui |
| `PdfExtractor` | pdf | `pdftotext -layout` (délai 30 s), sinon lecteur PHP (flux FlateDecode, `Tj`/`TJ`) | `p. N` (ou `flux N` en PHP) | Oui (mot de passe, `/Encrypt`) |
| `LegacyOfficeExtractor` | doc, xls, ppt, dot, xlt, pps | `antiword` pour .doc ; sinon chaînes UTF-16LE et 8 bits | `fragment N` | Oui (`EncryptedPackage`) |
| `RtfExtractor` | rtf | Groupes de métadonnées retirés, `\'xx` et `\uN` décodés | `ligne N` | — |

Les extensions inconnues sont lues comme du texte si le contenu n'a pas l'air binaire ; les images, vidéos, exécutables et archives non ZIP sont ignorés (recherche par nom uniquement).

## `Extractor\OfficeArchive`

Ouvre les formats « ZIP + XML ». Si les 8 premiers octets sont la signature OLE `D0 CF 11 E0 A1 B1 1A E1` alors que l'extension est `docx`/`xlsx`/`pptx`, le document est **chiffré** (Office enveloppe les fichiers protégés dans un conteneur OLE) → `UnreadableFileException::protected()`.

## `Extractor\ExtractorRegistry`

- `extract(Source): ?Document` : choisit l'extracteur, consulte le cache, extrait, met en cache. Retourne `null` pour un fichier non textuel.
- `supportedExtensions()`, `supports()`, `pdfEngine()` (`pdftotext` ou `php`).

## Limites connues

| Limite | Contournement / évolution |
| --- | --- |
| PDF scannés sans couche texte | OCR prévu (phase 6) |
| Formats binaires anciens : texte approximatif | Installer `antiword` pour .doc ; convertir en format récent |
| Lecteur PDF PHP : polices à encodage personnalisé illisibles | Installer `pdftotext` |
| Valeurs Excel : nombres bruts (dates en numéro de série, pas de formatage) | Formatage des dates prévu avec les modèles d'extraction (phase 7) |
