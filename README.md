# Poseidon

**Recherche et extraction dans les fichiers.** Poseidon fait ce que font ensemble `ls -R`, `grep -R -i`, les pipes et `cat`, mais il lit aussi l'intérieur des PDF, Word, Excel, OpenDocument et des ZIP imbriqués, compte les occurrences de chaque mot, prouve chaque résultat par sa ligne, et transforme une recherche en un nouveau document de données.

![Interface de Poseidon](docs/assets/interface-bureau.png)

## En bref

| | |
| --- | --- |
| **Où** | Dossier local, `C:\…`, partage `\\serveur\partage` ; à l'intérieur des ZIP, 7z, RAR, tar, gz, et des pièces jointes de courriels, imbriqués à volonté |
| **Quoi** | PDF (OCR si scanné), Word, Excel, OpenDocument, PowerPoint, RTF, CSV, texte, code, courriels `.eml` / `.msg`, images (OCR) |
| **Comment** | Un mot, une phrase, plusieurs mots (tous / un des), à proximité les uns des autres, avec fautes de frappe, ou une regex |
| **Résultat** | Fichiers, chemins absolu et relatif, occurrences par terme, lignes-preuves surlignées ; aperçu, téléchargement |
| **Données** | Extraction d'occurrences, de lignes ou de colonnes vers CSV, XLSX, JSON, TXT, MD ; groupes nommés → colonnes ; modèles rejouables ; rapport HTML/PDF |
| **Comparer** | Deux fichiers (`git diff`), deux recherches (ce qui a changé), l'historique Git d'un document (même DOCX ou PDF) |
| **Vitesse** | Index plein texte : 10 000 fichiers relus en 5 s, puis 0,5 s indexés |
| **Équipe** | Comptes et rôles, racines par compte, journal d'audit, Docker + HTTPS |
| **Robuste** | Fichiers protégés, illisibles ou piégés signalés, jamais bloquants ; aucun CDN ; PHP seul |

## Démarrer

**Version 1.0.0.** Captures : [bureau](docs/assets/interface-bureau.png) · [mobile](docs/assets/interface-mobile.png) · [comparaison](docs/assets/interface-comparaison.png) · [tableur](docs/assets/interface-tableur.png) · [thème sombre](docs/assets/interface-sombre.png).

**Windows** : double-clic sur `poseidon.bat` (le navigateur s'ouvre sur <http://127.0.0.1:8080>).

**Linux / macOS / WSL** :

```sh
./poseidon.sh            # interface web sur le port 8080
```

**Ligne de commande** :

```sh
php bin/poseidon search ~/Documents "contrat préavis" --ext=pdf,docx
php bin/poseidon extract ./finance "résilié" --extract=columns --columns=Client,Montant --out=resilies.xlsx
php bin/poseidon diff contrat_v1.docx contrat_v2.pdf
php bin/poseidon search ./juridique "contrat résiliation" --mode=near --changes
php bin/poseidon git-search ./depot "préavis"
```

Sous Windows : `bin\poseidon.bat search C:\Docs "contrat préavis"`.

Guide complet : [docs/01-general/demarrage.md](docs/01-general/demarrage.md).

## Documentation

| | |
| --- | --- |
| [Contexte du projet](context.md) | Objectif, état, décisions |
| [Documentation](docs/README.md) | Architecture, API, interface, modules |
| [Cahier des charges](docs/web/cahier-des-charges.html) | Exigences et avancement (page web) |
| [Roadmap](docs/web/roadmap.html) | Phases jusqu'à la v1.0 (page web) |
| [Déploiement](docs/01-general/deploiement.md) | Comptes, Docker + HTTPS, serveurs |
| [Versions](docs/03-suivi/versions.md) | Journal des versions |

## Tests

```sh
php tests/run.php
```

## Licence des polices

Archivo et JetBrains Mono sont distribuées sous licence SIL Open Font License 1.1.
