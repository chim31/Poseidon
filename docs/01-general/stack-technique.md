# Stack technique

Langages, outils, versions et raisons de chaque choix.

| Élément | Version | Rôle | Pourquoi | Alternative écartée |
| --- | --- | --- | --- | --- |
| PHP | ≥ 8.2 (développé en 8.4.24) | Moteur, API, CLI | Imposé par le projet ; disponible partout, y compris Windows sans installation lourde | — |
| Framework | aucun | | Laravel n'apporte ni routage complexe, ni base, ni file d'attente utiles ici, et exige ext-dom/xml absents ; décision validée le 2026-10-16 | Laravel 12 (possible plus tard) |
| Autoloader | maison (PSR-4, `bootstrap.php`) | | Zéro Composer | Composer |
| Extension `zip` | incluse | Office, ODF, ZIP | Lecture d'archives native | `unzip` en sous-processus |
| Extension `mbstring` | incluse | Unicode, encodages | Accents, UTF-16, Windows-1252 | `iconv` seul |
| poppler `pdftotext` | optionnel | PDF | Meilleure qualité de texte, mise en page conservée | Bibliothèque PHP (Composer) |
| `antiword` | optionnel | `.doc` binaire | Texte propre des anciens Word | — |
| HTML / CSS / JavaScript | natifs, modules ES | Interface | Aucun CDN, aucune compilation | React, Vue, Tailwind (CDN interdit, chaîne de build inutile) |
| Archivo | variable (wdth 62–125, wght 100–900) | Police d'interface | Largeurs variables pour les titres étirés de l'univers « signaux » ; licence OFL | Inter, système |
| JetBrains Mono | variable (400–700) | Chemins, chiffres, code | Lisible, chiffres tabulaires ; licence OFL | Consolas |
| Tests | `tests/run.php` maison | | PHPUnit exige ext-dom | PHPUnit |
| Git + GitHub CLI (`gh`) | | Versionnement, PR | Workflow imposé (branches, PR) | |

## Compatibilité

| Système | Interface web | CLI | Remarques |
| --- | --- | --- | --- |
| Windows 10/11 | `poseidon.bat` | `bin\poseidon.bat` | UNC natif ; serveur intégré mono-processus |
| Linux | `poseidon.sh` | `bin/poseidon` | Partages réseau à monter |
| WSL | `poseidon.sh` | `bin/poseidon` | Lecteurs Windows dans `/mnt/c`, `/mnt/d`… |
| macOS | `poseidon.sh` | `bin/poseidon` | |
