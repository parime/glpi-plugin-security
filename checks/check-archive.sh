#!/usr/bin/env bash
# Archive de release propre : aucun fichier de développement dans ce que `git archive` produit,
# c'est-à-dire dans l'archive publiée (les workflows de release des plugins la construisent avec
# git archive, en appliquant les export-ignore de .gitattributes).
#
# Usage : check-archive.sh [dossier du dépôt du plugin]   (par défaut : dossier courant)
#
# Sortie : la liste des fichiers en trop et un code 1, ou un code 0.
set -euo pipefail
DIR=${1:-.}

# Racine du dépôt : outillage de développement, de test, d'analyse ou de CI.
# Dossiers (tout leur contenu) puis fichiers.
FORBIDDEN='^(tests|\.github|\.semgrep|\.phpstan[^/]*|node_modules|\.vscode|\.idea|\.devcontainer)/'
FORBIDDEN+='|^(phpstan[^/]*|\.phpstan-bootstrap\.php|phpunit[^/]*|\.phpunit[^/]*|\.php-cs-fixer[^/]*|\.php_cs[^/]*'
FORBIDDEN+='|phpcs\.xml[^/]*|psalm\.xml[^/]*|rector\.php|infection\.json[^/]*|\.editorconfig|\.yamllint[^/]*'
FORBIDDEN+='|\.gitignore|\.gitattributes|\.glpi-security-exceptions|docker-compose[^/]*|Dockerfile|\.dockerignore'
FORBIDDEN+='|package\.json|package-lock\.json|\.eslintrc[^/]*|\.stylelintrc[^/]*)$'

files=$(git -C "$DIR" archive HEAD | tar -t)
extra=$(printf '%s\n' "$files" | grep -E "$FORBIDDEN" | awk -F/ '{print ($2 == "" ? $0 : $1 "/")}' | sort -u || true)

if [ -n "$extra" ]; then
  echo "::error::fichiers de développement dans l'archive publiée (à ajouter en export-ignore dans .gitattributes) :"
  printf '%s\n' "$extra" | sed 's/^/  /'
  exit 1
fi
echo "Archive propre : $(printf '%s\n' "$files" | grep -vc '/$') fichiers, aucun fichier de développement."
