#!/usr/bin/env bash
# Langages CodeQL présents dans un plugin GLPI, d'après ses fichiers suivis par git (hors vendor et
# node_modules) : actions (workflows), javascript (JavaScript et TypeScript), python.
# PHP n'est pas analysé par CodeQL : Semgrep et les contrôles de ce dépôt le couvrent.
#
# Usage : codeql-languages.sh <dossier du plugin>   → tableau JSON, par exemple ["actions","javascript"]
set -u
cd "${1:-.}" || exit 2
files=$(git ls-files -- . | grep -Ev '(^|/)(vendor|node_modules)/' || true)
has() { grep -Eq "$1" <<< "$files"; }

langs=()
has '^\.github/workflows/[^/]+\.ya?ml$' && langs+=(actions)
has '\.(js|mjs|cjs|jsx|ts|tsx|vue)$' && langs+=(javascript)
has '\.py$' && langs+=(python)

out='['
for l in "${langs[@]}"; do out+="\"$l\","; done
printf '%s\n' "${out%,}]"
