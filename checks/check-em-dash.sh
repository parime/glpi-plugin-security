#!/usr/bin/env bash
# Typographie des textes produits par un plugin GLPI : aucun tiret cadratin (U+2014), ni dans les
# chaînes affichées, ni dans les traductions, ni dans la documentation. Remplacer par « : », une
# virgule, des parenthèses, ou « - » pour un séparateur (issues grcmanager #363, cga #390).
#
# Usage : check-em-dash.sh <dossier du plugin>   (code de sortie 1 si un tiret est trouvé)
# Parcourt les fichiers suivis par git, hors dépendances (vendor, node_modules) et fichiers binaires.
# Un nom historique à retrouver (migration) s'écrit avec la séquence d'échappement "\u{2014}".
set -u
dir=${1:-.}
cd "$dir" || exit 2

dash=$(printf '\xe2\x80\x94')
found=$(git ls-files -z -- . ':!vendor' ':!node_modules' ':!*.mo' \
  | xargs -0 -r grep -n -I -F -- "$dash" 2>/dev/null || true)

if [ -n "$found" ]; then
  count=$(printf '%s\n' "$found" | wc -l)
  printf '%s\n' "$found" | head -50 | while IFS= read -r line; do
    file=${line%%:*}
    rest=${line#*:}
    echo "::error file=$file,line=${rest%%:*}::tiret cadratin (—) : remplacer par « : », une virgule, des parenthèses ou « - »"
  done
  echo "$count ligne(s) avec un tiret cadratin."
  exit 1
fi
echo "Aucun tiret cadratin."
