# Poseidon

**Recherche et extraction dans les fichiers.** Poseidon fait ce que font ensemble `ls -R`, `grep -R -i`, les pipes et `cat`, mais il lit aussi l'intérieur des PDF, Word, Excel, OpenDocument et des ZIP imbriqués, compte les occurrences de chaque mot, prouve chaque résultat par sa ligne, et transforme une recherche en un nouveau document de données.

![Interface de Poseidon](docs/assets/interface-bureau.png)

## En bref

- **Où** : dossier local, `C:\…`, partage réseau `\\serveur\partage`, ZIP dans des ZIP.
- **Quoi** : un mot, une phrase en bloc, plusieurs mots (tous / au moins un), ou une expression régulière ; casse, accents, mot entier.
- **Résultat** : nombre de fichiers, chemins absolu et relatif, occurrences par terme, lignes-preuves ; aperçu, téléchargement, comparaison de deux fichiers façon `git diff`.
- **Extraction** : occurrences, lignes, colonnes de tableurs (par lettre ou en-tête) ou liste de fichiers, vers CSV, XLSX, JSON, TXT ou Markdown.
- **Robuste** : les fichiers protégés, illisibles ou piégés sont ignorés et signalés, jamais bloquants.
- **Autonome** : PHP 8.2+ uniquement, aucun CDN, aucune dépendance Composer.

## Démarrer

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

## Tests

```sh
php tests/run.php
```

## Licence des polices

Archivo et JetBrains Mono sont distribuées sous licence SIL Open Font License 1.1.
