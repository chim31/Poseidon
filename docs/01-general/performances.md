# Performances

Mesures reproductibles avec le banc d'essai fourni, et conseils pour les gros volumes.

## Banc d'essai

```sh
php -d memory_limit=1G tests/bench.php 10000
```

Le script génère une arborescence synthétique (70 % TXT, 10 % MD, 8 % CSV, 6 % DOCX, 3 % XLSX, 2 % PDF, 1 % ZIP contenant TXT + DOCX ; 40 lignes par fichier ; un mot rare tous les 997 fichiers), puis mesure six scénarios. Tout est supprimé à la fin.

## Résultats de référence

Poste de développement : WSL2, PHP 8.4, SSD, `pdftotext` présent ; 10 000 fichiers (10 200 avec les entrées de ZIP).

| Scénario | Durée | Remarque |
| --- | ---: | --- |
| 1. Parcours à froid (sans cache, sans index) | 5,2 s | Objectif du cahier des charges : < 10 s ✔ |
| 2. Parcours à chaud (cache de texte) | 0,9 s | PDF et Office déjà extraits |
| 3. Construction de l'index | 5,1 s | Une fois ; ensuite incrémental |
| 4. Recherche indexée d'un terme rare | 0,47 s | 9 990 fichiers et 100 archives non relus |
| 5. Recherche indexée d'un terme fréquent | 3,2 s | 10 100 fichiers trouvés : le coût vient des résultats eux-mêmes |
| 6. Expression régulière | 1,2 s | Index inutilisable, parcours complet à chaud |

Taille de l'index : 62 Mo pour 10 000 fichiers (≈ 400 Ko de texte par fichier × trigrammes).

## Conseils

| Volume | Recommandation |
| --- | --- |
| < 5 000 fichiers | Rien à faire : le cache suffit |
| 5 000 – 100 000 | Indexer la racine (`poseidon index` ou bouton de l'interface) ; les recherches suivantes mettent l'index à jour seules |
| > 100 000 | Indexer des sous-dossiers ciblés ; exclure les dossiers inutiles (`excluded_dirs`) ; filtrer par formats |
| Partages réseau lents | L'index évite de relire les fichiers inchangés : gain maximal |
| Windows (serveur intégré mono-processus) | Préférer un serveur Apache/Nginx + PHP-FPM pour les usages partagés |

## Ce qui coûte

1. **Lire** les fichiers (disque, réseau) : évité par l'index.
2. **Extraire** le texte des PDF et Office : évité par le cache.
3. **Produire** les résultats (lignes-preuves, surlignage) : proportionnel au nombre de correspondances ; limiter avec `--limit` ou un filtre plus précis.

La parallélisation du parcours, envisagée au départ, a été écartée : PHP n'offre pas de fils d'exécution portables (Windows compris), et l'index supprime l'essentiel des lectures, qui sont le vrai goulot.
