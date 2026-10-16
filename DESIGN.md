---
name: Poseidon
description: Recherche et extraction dans les fichiers, sous les pavillons du code international des signaux.
colors:
  abyss: "#0b2340"
  abyss-2: "#10305a"
  abyss-3: "#183e6c"
  abyss-line: "#2a5182"
  on-abyss: "#f3f1e9"
  on-abyss-2: "#b3c3d8"
  paper: "#f6f8f9"
  foam: "#e9eff3"
  surface: "#ffffff"
  ink: "#14202e"
  ink-2: "#3a4859"
  mute: "#566273"
  line: "#d3dce3"
  line-soft: "#e6ecf0"
  flag-yellow: "#f2c230"
  flag-red: "#c8302b"
  flag-blue: "#1d5fa8"
  mark: "#ffe38a"
  mark-strong: "#ffd34d"
  ok: "#1d7347"
  ok-bg: "#e4f3ea"
  bad: "#ad2620"
  bad-bg: "#fbe7e5"
  warn: "#8a5a00"
  warn-bg: "#fff3d3"
typography:
  brand:
    fontFamily: "Archivo, Segoe UI, system-ui, sans-serif"
    fontSize: "21px"
    fontWeight: 800
    lineHeight: 1.45
    letterSpacing: "0.03em"
    fontVariation: "'wdth' 125"
  display:
    fontFamily: "Archivo, Segoe UI, system-ui, sans-serif"
    fontSize: "clamp(36px, 5vw, 60px)"
    fontWeight: 800
    lineHeight: 1.02
    letterSpacing: "-0.01em"
    fontVariation: "'wdth' 118"
  headline:
    fontFamily: "Archivo, Segoe UI, system-ui, sans-serif"
    fontSize: "26px"
    fontWeight: 800
    lineHeight: 1.15
    letterSpacing: "-0.01em"
    fontVariation: "'wdth' 112"
  title:
    fontFamily: "Archivo, Segoe UI, system-ui, sans-serif"
    fontSize: "17px"
    fontWeight: 750
    lineHeight: 1.25
    fontVariation: "'wdth' 108"
  figure:
    fontFamily: "Archivo, Segoe UI, system-ui, sans-serif"
    fontSize: "24px"
    fontWeight: 700
    lineHeight: 1.05
    fontFeature: "'tnum'"
    fontVariation: "'wdth' 88"
  body:
    fontFamily: "Archivo, Segoe UI, system-ui, sans-serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: 1.45
    fontFeature: "'tnum'"
  body-read:
    fontFamily: "Archivo, Segoe UI, system-ui, sans-serif"
    fontSize: "16px"
    fontWeight: 400
    lineHeight: 1.6
  label:
    fontFamily: "Archivo, Segoe UI, system-ui, sans-serif"
    fontSize: "11.5px"
    fontWeight: 600
    lineHeight: 1.45
    letterSpacing: "0.08em"
  mono:
    fontFamily: "JetBrains Mono, ui-monospace, Cascadia Mono, Consolas, monospace"
    fontSize: "12.5px"
    fontWeight: 400
    lineHeight: 1.7
rounded:
  flag: "1.5px"
  xs: "3px"
  sm: "4px"
  md: "6px"
  lg: "8px"
  xl: "10px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "12px"
  lg: "16px"
  pane: "20px"
  gutter: "24px"
  section: "48px"
components:
  button-primary:
    backgroundColor: "{colors.abyss}"
    textColor: "{colors.on-abyss}"
    rounded: "{rounded.md}"
    padding: "0 14px"
    height: "36px"
  button-primary-hover:
    backgroundColor: "{colors.abyss-3}"
    textColor: "{colors.on-abyss}"
  button-outline:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.abyss}"
    rounded: "{rounded.md}"
    padding: "0 14px"
    height: "36px"
  button-quiet:
    backgroundColor: "transparent"
    textColor: "{colors.ink-2}"
    rounded: "{rounded.md}"
    padding: "0 14px"
    height: "36px"
  button-quiet-hover:
    backgroundColor: "{colors.line-soft}"
    textColor: "{colors.ink}"
  query-box:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.lg}"
    padding: "5px 5px 5px 12px"
  input:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.md}"
    padding: "8px 10px"
  rail-input:
    backgroundColor: "{colors.abyss-2}"
    textColor: "{colors.on-abyss}"
    rounded: "{rounded.md}"
    padding: "8px 10px"
  chip-rail:
    backgroundColor: "transparent"
    textColor: "{colors.on-abyss}"
    rounded: "{rounded.sm}"
    padding: "4px 9px"
  chip-rail-pressed:
    backgroundColor: "{colors.on-abyss}"
    textColor: "{colors.abyss}"
  pennant:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.sm}"
    padding: "3px 9px 3px 4px"
  segmented-active:
    backgroundColor: "{colors.abyss}"
    textColor: "{colors.on-abyss}"
    rounded: "{rounded.sm}"
    padding: "5px 11px"
  badge-zip:
    backgroundColor: "#e2ebf6"
    textColor: "{colors.flag-blue}"
    rounded: "{rounded.xs}"
    padding: "1px 6px"
  alert-band:
    backgroundColor: "{colors.warn-bg}"
    textColor: "#5c3d00"
    padding: "9px 24px"
  toast:
    backgroundColor: "{colors.abyss}"
    textColor: "{colors.on-abyss}"
    rounded: "{rounded.lg}"
    padding: "12px 12px 12px 14px"
  match:
    backgroundColor: "{colors.mark}"
    textColor: "{colors.ink}"
    rounded: "2px"
    padding: "0 1px"
---

# Design System: Poseidon

## Overview

**Creative North Star: "La drisse des signaux"**

Poseidon est une table à cartes de bord : une coque bleu abyssal tient les réglages à gauche, une mer d'embrun froid porte les résultats au centre, un volet blanc expose la preuve à droite. Chaque terme de la recherche hisse un pavillon du code international des signaux, et ce pavillon le suit partout : dans la barre de requête, en tête de colonne de compteur, en mini-drisse sur chaque ligne de résultat, et en soulignement sous chaque correspondance de l'aperçu. Le système refuse le panneau de recherche gris d'éditeur à bleu unique.

La densité est celle d'un outil de travail : corps à 14px, rangées serrées, chiffres tabulaires partout. La hiérarchie passe par l'axe de largeur d'Archivo plutôt que par la taille seule (marque étirée à 125 %, compteurs condensés à 88 %), et tout ce qui se vérifie (chemin, ligne, nombre, lettre du pavillon) est composé en JetBrains Mono. La couleur vive n'appartient qu'aux pavillons et au surlignage des correspondances ; le reste vit en bleu abyssal, encre et embrun.

Les pages de documentation (cahier des charges, feuille de route) partagent le même monde en mode lecture : bandeau abyssal, sol d'embrun, corps à 16px, titres étirés, pavillons carrés en guise d'état d'avancement.

**Key Characteristics:**
- Coque abyssale (rail, bandeau, barre mobile) contre sol d'embrun froid, jamais crème chaud.
- Un pavillon et sa lettre de code par terme, motifs lisibles en niveaux de gris.
- Jaune réservé aux correspondances et aux pavillons.
- Archivo à largeur variable pour la voix, JetBrains Mono pour la preuve.
- Plat au repos ; l'ombre n'existe que pour ce qui flotte.

## Colors

Une coque bleu nuit, un sol froid presque blanc, et cinq couleurs de pavillon qui portent seules la signalisation.

### Primary
- **Bleu abyssal** (abyss): la masse sombre unique du système. Rail de réglages, bandeau de documentation, barre supérieure mobile, bouton primaire « Sonder », segment actif, onglet actif (soulignement), cases cochées, écran de connexion. Ses paliers **abysse 2** et **abysse 3** servent de champ et de survol sur la coque ; **filin abyssal** (abyss-line) trace les bordures sur fond sombre.
- **Blanc de coque** (on-abyss): texte et état pressé sur l'abysse (chips actives, bouton déplié). **Brume de coque** (on-abyss-2) pour les libellés et valeurs secondaires sur le rail.

### Secondary
- **Pavillons** (flag-yellow, flag-red, flag-blue, plus ink comme noir et surface comme blanc de pavillon): couleurs de signalisation, utilisées uniquement à l'intérieur des pavillons SVG. Chaque pavillon a une couleur-clé qui souligne les correspondances de son terme.
- **Bleu signal** (flag-blue) a un second rôle fonctionnel hors pavillons : anneau de focus, liens, sélection de texte, curseur, badge ZIP.

### Tertiary
- **Surlignage** (mark): fond des correspondances dans l'aperçu et les lignes-preuves, avec un trait intérieur de 2px à la couleur-clé du pavillon du terme. **Surlignage vif** (mark-strong) marque l'occurrence courante, cerclée d'abysse.
- **États** : succès (ok / ok-bg) et suppression (bad / bad-bg) pour le diff et les erreurs ; avertissement (warn / warn-bg) pour la bande d'alerte des fichiers ignorés et le compteur « ignorés ».

### Neutral
- **Embrun** (paper): sol de l'application et des pages de documentation, en-têtes collants du tableau.
- **Écume** (foam): bande de la requête, en-têtes de tableur, fonds de `code`, encadrés de la documentation.
- **Volet** (surface): volet d'aperçu, rangée sélectionnée, champs, cartes et panneaux.
- **Encre** (ink) pour le texte, **encre 2** (ink-2) pour le texte secondaire, **brume** (mute) pour les libellés et métadonnées (5,8:1 sur embrun).
- **Filet** (line) et **filet doux** (line-soft) pour toutes les séparations : le système découpe par filets de 1px, pas par blocs colorés.

### Named Rules
**The Reserved Yellow Rule.** Le jaune (flag-yellow, mark, mark-strong) n'apparaît que dans les pavillons et sur les correspondances. Aucun bouton, état, icône ou accent décoratif ne le porte ; s'il apparaît ailleurs, l'œil ne sait plus où est la preuve.

**The Cold Spray Rule.** Le sol est froid (embrun, écume, volet). Aucun fond crème ou papier chaud ; la seule chaleur de l'interface est celle des pavillons et du surlignage.

**The One Hull Rule.** L'abysse est la seule masse sombre. Une surface nouvelle se pose sur embrun ou volet ; elle ne crée pas un second bleu foncé.

## Typography

**Display Font:** Archivo, variable en graisse (100 à 900) et en largeur (62 % à 125 %), embarquée (avec Segoe UI, system-ui)
**Body Font:** Archivo
**Label/Mono Font:** JetBrains Mono, embarquée (avec ui-monospace, Cascadia Mono, Consolas)

**Character:** Une grotesque industrielle qui s'étire pour parler et se condense pour compter, épaulée par une mono de terminal qui signe tout ce qui est vérifiable. Le couple rappelle la plaque d'instrument plus que le magazine.

### Hierarchy
- **Brand** (800, 21px, largeur 125 %, capitales, 0,03em): le mot-symbole POSEIDON du rail, de la barre mobile, du bandeau de documentation (18px) et de l'écran de connexion (24px).
- **Display** (800, clamp 36 à 60px, largeur 118 %, 1,02): titre de page de la documentation, seul usage.
- **Headline** (800, 26px, largeur 112 %, 1,15): titres d'état vide (« Pose une racine, hisse tes mots. ») et titres de section de la documentation (30px).
- **Title** (750, 17px, largeur 108 à 112 %, 1,25): nom du fichier dans l'aperçu, titres de volet (extraction, fichiers ignorés), titre du diff (16px).
- **Figure** (700, 24px, largeur 88 %, 1,05, chiffres tabulaires): compteurs de synthèse (fichiers, occurrences, ZIP, ignorés). La jauge de la documentation pousse jusqu'à 112px en largeur 80 %.
- **Body** (400, 14px, 1,45): texte d'interface. Prose d'état vide à 15px limitée à 62ch ; documentation à 16px / 1,6, prose limitée à 72ch.
- **Label** (600, 11,5px, 0,08em, capitales): libellés de champ du rail, légendes de groupe du volet d'extraction, en-têtes de colonnes du tableau (0,06em). Toujours attachés à un contrôle ou à une colonne.
- **Mono** (400, 11 à 13px): chemins, numéros de ligne, compteurs de colonne, lettres de pavillon (10,5px, 600), statistiques de scan, aperçu (12,5px / 1,7), diff.

### Named Rules
**The Mono-for-Proof Rule.** Tout ce qu'on peut vérifier ou copier (chemin, numéro de ligne, nombre, lettre de code, motif) est en JetBrains Mono ; tout ce qu'on lit est en Archivo.

**The Width-Is-Voice Rule.** La hiérarchie se règle d'abord sur l'axe de largeur : étiré (108 à 125 %) pour nommer, condensé (80 à 88 %) pour compter. Les chiffres sont toujours tabulaires.

## Layout

L'application est une grille à trois colonnes sur toute la hauteur de la fenêtre : rail de 280px, centre fluide, volet d'aperçu de 448px (256px et 400px sous 1360px). Le contrat de direction prévoyait 272px et 420px ; le build a retenu 280 et 448, qui font foi. Le centre empile la bande de requête (écume), la ligne de progression, la synthèse chiffrée, la bande d'alerte éventuelle puis le tableau défilant, chaque strate séparée par un filet.

Sous 1080px, le rail et le volet deviennent des tiroirs fixes qui glissent depuis leur bord (0,28s), une barre supérieure abyssale apparaît avec le menu et la marque. Sous 720px, les colonnes de compteur par pavillon disparaissent au profit des compteurs inline de la mini-drisse, la synthèse et la barre de requête passent en colonne, le contrôle segmenté se répartit en deux colonnes.

Le rythme suit un pas de 4px : 4, 6, 8, 10, 12, 14, 16, 20, 24. La gouttière horizontale du centre est de 24px (16px en mobile), celle du volet de 20px, celle du rail de 20px avec 22px entre groupes. La documentation centre son contenu sur 1180px, avec un sommaire collant de 220px et 48px de gouttière.

**The Proof-Beside Rule.** Le résultat et sa preuve sont visibles en même temps : la liste au centre, les lignes numérotées dans le volet à droite. On ne quitte jamais la liste pour lire un fichier.

## Elevation & Depth

Le système est plat. La profondeur se lit par le ton (abysse, écume, embrun, volet blanc) et par des filets de 1px. Une rangée sélectionnée passe au blanc de volet pendant que les autres reculent à 74 % d'opacité. L'ombre n'apparaît que sur ce qui flotte au-dessus du plan : toasts, tiroirs en mobile, carte de connexion.

### Shadow Vocabulary
- **Ombre de détachement** (`box-shadow: 0 10px 30px -12px rgb(0 0 0 / 0.38), 0 2px 6px -2px rgb(0 0 0 / 0.2)`): toasts, rail et volet en tiroir, carte de connexion.
- **Anneau de saisie** (`box-shadow: 0 0 0 3px rgb(29 95 168 / 0.18)`): halo bleu signal autour de la barre de requête et des champs au focus (0,15 sur les champs).
- **Liseré de pavillon** (`box-shadow: 0 0 0 1px rgb(20 32 46 / 0.22)`): contour fin qui détache un pavillon blanc du fond.
- **Carte en cours** (`box-shadow: 0 8px 24px -14px rgb(0 0 0 / 0.35)`): documentation uniquement, l'étape en cours de la feuille de route.

### Named Rules
**The Flat-Until-Floating Rule.** Une surface posée dans la grille n'a pas d'ombre. Seul un élément qui recouvre le contenu (toast, tiroir, modale) en reçoit une.

## Shapes

Des angles juste adoucis, jamais de pilule. Les contrôles prennent 6px (boutons, champs, groupes segmentés), les éléments compacts 4px (chips, pennants, segments, lignes du navigateur de dossiers), les badges et cases à cocher 3px, la barre de requête, les toasts et les cartes de documentation 8px, la carte de connexion 10px. Les pavillons sont des rectangles 3:2 à 1,5px de rayon, dessinés en aplats francs (moitiés, bandes, croix, losange, damier), jamais en dégradé. Les bordures font 1px ; les contrôles d'action et la barre de requête montent à 1,5px en abysse. Le mât et la drisse de l'état vide sont des traits pleins de 2px.

## Components

### Buttons
Fermes, compacts, en abysse.
- **Shape:** angles adoucis (6px), hauteur 36px, 32px en petite taille, 40px pour « Sonder », 42px pour « Créer le document ».
- **Primary:** fond abysse, texte blanc de coque, 600 à 13,5px, icône 16px à gauche. « Sonder » s'étire en largeur 112 %.
- **Hover / Focus:** abysse 3 au survol, enfoncement de 1px à l'appui, contour de focus 2px bleu signal décalé de 2px. Désactivé à 45 % d'opacité.
- **Outline:** bordure 1,5px abysse sur volet blanc, texte abysse ; survol en bleu très pâle.
- **Quiet:** sans fond, encre 2 ; survol en filet doux. Pour les actions secondaires (Comparer, Afficher plus, retour).
- **Lien-bouton:** bleu signal, 600, souligné à 3px.

### Chips
- **Style:** sur le rail, bordure filin abyssal sans fond, texte blanc de coque, 12,5px, 4px de rayon. Dans la documentation, sur volet blanc avec filet clair.
- **State:** pressée = fond blanc de coque, texte abysse, 600 (inversion de la coque), ou fond abysse sur fond clair.

### Pennant (signature)
Le pavillon du terme, hissé sous la barre de requête : capsule blanche à filet clair, 4px de rayon, pavillon 22×15, lettre de code en mono 10,5px brume, puis le terme en 600. Il entre par un léger hissage (6px vers le haut, 0,28s). Le même pavillon se réduit à 20×13 en tête de colonne, 14×10 dans la mini-drisse de chaque rangée, et s'éteint (18 % d'opacité, niveaux de gris) quand un fichier ne porte pas le terme. L'ordre d'attribution est fixe : A, B, I, L, C, D, E, F, G, H, J, K, puis on recommence.

### Match
Correspondance dans l'aperçu et les lignes-preuves : fond surlignage, encre, 2px de rayon, trait intérieur bas de 2px à la couleur-clé du pavillon du terme. L'occurrence courante passe en surlignage vif avec un contour abysse de 2px.

### Cards / Containers
- **Corner Style:** 6px pour les options d'extraction, 8px pour les panneaux de documentation.
- **Background:** volet blanc sur embrun ; écume pour les encadrés pédagogiques.
- **Shadow Strategy:** aucune (voir Elevation & Depth).
- **Border:** filet 1px ; une option choisie prend une bordure abysse et un fond bleu très pâle.
- **Internal Padding:** 8 à 10px pour les options, 18 à 22px pour les panneaux.

### Inputs / Fields
- **Style:** sur fond clair, volet blanc, bordure 1px gris-bleu, 6px. Sur le rail, abysse 2 avec bordure filin abyssal et texte blanc de coque ; chemins et motifs en mono.
- **Focus:** bordure bleu signal et anneau de saisie de 3px. La barre de requête porte en permanence une bordure 1,5px abysse et prend l'anneau au focus.
- **Checkbox:** carré 16px à 3px de rayon, coché = plein (blanc de coque sur le rail, abysse sur fond clair) avec une coche tracée.

### Navigation
- **Contrôle segmenté** (mode de filtre, format d'export): rail gris-bleu à 6px, segments à 4px, segment actif plein abysse en 600.
- **Onglets du volet:** texte 600 brume, l'onglet actif passe à l'encre avec un soulignement abysse de 2px.
- **Documentation:** bandeau abyssal collant de 60px, liens en brume de coque, page courante inversée (fond blanc de coque, texte abysse) ; sommaire latéral collant dont l'entrée active est pleine abysse.

### Results Table
En-têtes collants sur embrun, capitales 11,5px brume, triables. Rangées à 10×12px de padding, filet doux entre elles, nom de fichier en 600, chemin en mono 11,5px brume, badges d'extension en contour, badge ZIP en bleu signal sur bleu pâle. Une ligne-preuve d'une ligne suit chaque fichier, précédée de sa localisation en mono. Les compteurs sont alignés à droite en mono ; un zéro passe en gris.

### Alert Band
Notification persistante des fichiers ignorés : bande pleine largeur posée sous la synthèse, fond warn-bg, texte brun profond, icône cadenas en warn, chemin du dernier fichier en mono tronqué par la gauche, lien « Voir le détail » et fermeture. Elle ne recouvre jamais le contenu.

### Toast
Accusés et erreurs transitoires (copie, extraction, recherche annulée) : carte abysse flottante en bas à droite, 8px de rayon, ombre de détachement, icône teintée selon l'état (vert pâle, rouge pâle, blanc), entrée en 0,32s.

## Do's and Don'ts

### Do:
- **Do** attribuer à chaque terme son pavillon et sa lettre de code, dans l'ordre A, B, I, L, C, D, E, F, G, H, J, K, et répéter ce même pavillon partout où le terme apparaît.
- **Do** souligner chaque correspondance à la couleur-clé du pavillon de son terme, sur fond surlignage.
- **Do** composer chemins, numéros de ligne, compteurs et lettres de code en JetBrains Mono à chiffres tabulaires.
- **Do** séparer les strates par des filets de 1px (line, line-soft) et le ton, pas par des ombres.
- **Do** signaler un état persistant (fichiers ignorés) par une bande en ligne aux couleurs warn, et réserver le toast aux accusés transitoires.
- **Do** garder un contour de focus visible de 2px en bleu signal sur tout élément interactif.
- **Do** respecter `prefers-reduced-motion` : hissage, tiroirs et toasts se réduisent à un changement d'état.

### Don't:
- **Don't** utiliser le jaune (flag-yellow, mark) hors des pavillons et des correspondances.
- **Don't** revenir à un sol crème ou papier chaud : l'embrun froid (paper, foam, surface) est le sol.
- **Don't** introduire une seconde masse sombre ou un second bleu d'accent à côté de l'abysse et du bleu signal.
- **Don't** distinguer les pavillons par la seule couleur : chaque pavillon garde son motif (moitiés, bandes, croix, losange, damier) et sa lettre.
- **Don't** poser d'ombre sur une surface ancrée dans la grille.
- **Don't** arrondir les contrôles en pilule ni dépasser 10px de rayon.
- **Don't** charger de police ou d'icône depuis un CDN : Archivo, JetBrains Mono et le sprite SVG sont embarqués.
