# Backend

Vue globale du code PHP (`src/`, namespace `Poseidon\`). Chaque module a sa fiche dans [backend/](backend/).

## Couches

```
Façades        Http\Kernel (API web)          Cli\Application (terminal)
                         │                              │
Services       Search\SearchEngine · Export\DataExtractor · Search\Diff
                         │
Domaine        Search\Query · Search\Matcher · Extractor\Document · Export\ExtractionSpec
                         │
Accès          Filesystem\Scanner · Archive\ZipWalker · Extractor\* · Filesystem\FileLocator
                         │
Socle          Config · Support\Path · Support\Text · Support\Token · Support\Process
```

Les façades ne contiennent aucune logique métier : elles traduisent HTTP ou `argv` en objets du domaine et sérialisent les résultats.

## Modules

| Dossier | Classes | Fiche |
| --- | --- | --- |
| `Filesystem/`, `Archive/` | `Scanner`, `Source`, `Notice`, `UnreadableFileException`, `RootGuard`, `FileLocator`, `ZipWalker`, `ZipHandle` | [parcours-et-archives.md](backend/parcours-et-archives.md) |
| `Extractor/` | `Extractor`, `Document`, `ExtractorRegistry`, `PlainTextExtractor`, `CsvExtractor`, `WordExtractor`, `SpreadsheetExtractor`, `OpenDocumentExtractor`, `PdfExtractor`, `LegacyOfficeExtractor`, `RtfExtractor`, `OfficeArchive` | [extracteurs.md](backend/extracteurs.md) |
| `Search/` | `Query`, `SearchOptions`, `Matcher`, `MatchResult`, `SearchEngine`, `Diff` | [moteur-de-recherche.md](backend/moteur-de-recherche.md) |
| `Export/` | `ExtractionSpec`, `DataExtractor`, `Writer`, `XlsxWriter` | [extraction-de-donnees.md](backend/extraction-de-donnees.md) |
| `Http/`, `Cli/` | `Kernel`, `HttpError`, `Application` | [http-et-cli.md](backend/http-et-cli.md) |
| `Support/` | `Path`, `Text`, `Token`, `Process` | [socle-transversal.md](socle-transversal.md) |
| `Demo/` | `Samples` | Jeu d'échantillons synthétiques (démo + tests) |

## Patterns

| Pattern | Où | Pourquoi |
| --- | --- | --- |
| Générateurs (flux) | `Scanner::scan`, `ZipWalker::walk`, `SearchEngine::search` | Mémoire constante, résultats immédiats, annulation |
| Stratégie | `Extractor` + `ExtractorRegistry` | Un extracteur par famille de formats, ajout sans toucher au moteur |
| Objets valeur immuables | `Query`, `SearchOptions`, `ExtractionSpec`, `Document`, `Notice` | Paramètres validés une fois, à la construction |
| Chargement paresseux | `Source` (closure `loader`) | Les octets d'une entrée d'archive ne sont lus que si nécessaire |
| Exceptions métier → avertissements | `UnreadableFileException` → `Notice` | Robustesse : un fichier ne bloque jamais le parcours |

## Ajouter un format

1. Créer `src/Extractor/MonFormatExtractor.php` qui implémente `Extractor` (`extensions()`, `extract(Source): Document`).
2. Lever `UnreadableFileException` (avec la bonne raison) pour un fichier protégé ou corrompu.
3. L'enregistrer dans `ExtractorRegistry::__construct` (et dans `CACHED` si l'extraction est coûteuse).
4. Ajouter un échantillon dans `Demo\Samples` et un test dans `tests/run.php`.
5. Ajouter l'extension au bon groupe de formats dans `public/assets/js/app.js` (`FORMAT_GROUPS`).
6. Documenter dans [extracteurs.md](backend/extracteurs.md) et mettre à jour `projet.json`.
