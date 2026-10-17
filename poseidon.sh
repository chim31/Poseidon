#!/usr/bin/env sh
# Poseidon — lanceur Linux / macOS / WSL de l'interface web.
# Usage : ./poseidon.sh [port]
cd "$(dirname "$0")" || exit 1
PORT="${1:-8080}"
PHP="${POSEIDON_PHP:-php}"
command -v "$PHP" >/dev/null 2>&1 || { echo "[Poseidon] PHP introuvable (8.2+ requis)."; exit 1; }
"$PHP" -r "exit(extension_loaded('zip') && extension_loaded('mbstring') ? 0 : 1);" || { echo "[Poseidon] Extensions zip et mbstring requises."; exit 1; }
# Le serveur choisit un port libre et ouvre le navigateur une fois prêt.
exec "$PHP" bin/poseidon serve --port="$PORT" --open
