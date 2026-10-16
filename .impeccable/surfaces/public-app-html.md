---
version: 1
slug: "public-app-html"
primary_target: "public/app.html"
related_targets: []
---

# Surface : application Poseidon (public/app.html, servie par public/index.php)

Mode : Operate. Build : code-led (maquette choisie : .impeccable/mocks/decision/assigned.html).

Audience : développeurs, admins et équipes métier qui cherchent une information dans une arborescence (locale, UNC, ZIP imbriqués). Tâche : poser une racine, un filtre multi-termes, lire combien de fichiers / d'occurrences, prouver par la ligne, ouvrir, télécharger, extraire.

## Direction contract

THESIS: Chaque terme de la recherche hisse son pavillon (code international des signaux) et ce pavillon le suit partout : chip de requête, colonne de compteur, mini-drisse par résultat, soulignement dans l'aperçu. Refuse le panneau de recherche gris d'éditeur avec un seul bleu.

OWN-WORLD: Rail bleu abyssal #0B2340, sol embrun froid #F6F8F9 / bande #E9EFF3 / volets #FFFFFF (passé du crème au froid le 2026-10-16 : le crème tirait vers le défaut « papier chaud », l'embrun est plus juste pour Poséidon), encre #14202E ; pavillons jaune #F2C230, rouge #C8302B, bleu #1D5FA8, blanc, noir en motifs (moitiés, bandes, croix). Archivo (largeurs variables, titres étirés) + JetBrains Mono pour chemins et chiffres. Surlignage jaune réservé aux correspondances (aucun autre élément ne porte le jaune hors pavillons). Lettre du code (A, B, I…) à côté de chaque pavillon.

STORY: Je pose la racine, je tape mes mots, je vois tout de suite combien de fichiers et d'occurrences par terme, je prouve par la ligne, je télécharge ou j'extrais en CSV/XLSX.

FIRST VIEWPORT: Rail 272px à gauche (marque trident, racine, formats, options, statistiques de scan). Centre : barre de requête avec pavillons, bouton « Sonder », ligne de synthèse (fichiers, occurrences, dans les ZIP, Extraire), tableau des fichiers avec drisse et compteurs par pavillon. Droite 420px : aperçu avec chemin complet, actions, lignes numérotées surlignées.

FORM: Pavillons de signaux, candidat 7 de la liste ordonnée. seed:0a473747c5f4de12. Raises : surlignage réservé aux correspondances (orientation) ; chaque compteur suivi de sa ligne-preuve (monochrome) ; le résultat sélectionné prend le devant, le reste s'efface d'un ton (streaming).

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
