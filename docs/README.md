# Documentation de Poseidon

Index de toute la documentation. Commence par le [guide de démarrage](01-general/demarrage.md) si tu découvres le projet, et par [context.md](../context.md) pour l'état et les décisions.

## 01 — Général

| Document | Contenu |
| --- | --- |
| [architecture.md](01-general/architecture.md) | Vue d'ensemble, composants, flux de données, choix structurants |
| [stack-technique.md](01-general/stack-technique.md) | Langages, versions, outils, alternatives écartées |
| [api.md](01-general/api.md) | API HTTP : conventions et chaque route |
| [demarrage.md](01-general/demarrage.md) | Installer, configurer, lancer, tester, déployer, dépanner |
| [performances.md](01-general/performances.md) | Banc d'essai, mesures, conseils pour les gros volumes |

[base-de-donnees.md](01-general/base-de-donnees.md) décrit l'index SQLite, seul stockage structuré de Poseidon.

## 02 — Technique

| Document | Contenu |
| --- | --- |
| [interface.md](02-technique/interface.md) | Interface web : zones, états, univers visuel, conventions |
| [socle-transversal.md](02-technique/socle-transversal.md) | Configuration, chemins, sécurité, cache, erreurs, journalisation |
| [backend.md](02-technique/backend.md) | Organisation du code PHP, couches, patterns |
| [backend/parcours-et-archives.md](02-technique/backend/parcours-et-archives.md) | `Scanner`, `ZipWalker`, `ZipHandle`, `RootGuard`, `FileLocator` |
| [backend/extracteurs.md](02-technique/backend/extracteurs.md) | Lecture de chaque format |
| [backend/moteur-de-recherche.md](02-technique/backend/moteur-de-recherche.md) | `Query`, `Matcher`, `SearchEngine`, `Diff` |
| [backend/extraction-de-donnees.md](02-technique/backend/extraction-de-donnees.md) | `DataExtractor`, `Writer`, `XlsxWriter` |
| [backend/index-plein-texte.md](02-technique/backend/index-plein-texte.md) | `Index`, `Indexer` (SQLite FTS5) |
| [backend/http-et-cli.md](02-technique/backend/http-et-cli.md) | `Kernel` HTTP et `Application` CLI |

## 03 — Suivi

| Document | Contenu |
| --- | --- |
| [suivi-cahier-des-charges.md](03-suivi/suivi-cahier-des-charges.md) | Avancement par exigence (généré) |
| [anomalies.md](03-suivi/anomalies.md) | Registre des anomalies et limitations |
| [Cahier des charges web](web/cahier-des-charges.html) | Version web, avec tableau de bord et filtres |
| [Roadmap web](web/roadmap.html) | Frise et phases jusqu'à la v1.0 |

Les pages web s'ouvrent par double-clic, ou depuis l'application lancée à l'adresse `/projet/`.

## Ressources

- [assets/](assets/) : captures de l'interface.
- [../DESIGN.md](../DESIGN.md) : système visuel.
- [../PRODUCT.md](../PRODUCT.md) : utilisateurs, principes produit.
