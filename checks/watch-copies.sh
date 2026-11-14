#!/usr/bin/env bash
# Surveillance des reprises du code des plugins de <propriétaire> : cherche sur GitHub (recherche de
# code, hors forks et hors dépôts du propriétaire) des identifiants propres à chaque plugin, et
# indique si le dépôt trouvé cite le projet d'origine (nom de l'auteur ou du compte, ou lien).
#
# Rien n'est publié : le résultat va dans le résumé de l'exécution (privé, onglet Actions). Un dépôt
# trouvé qui n'est pas encore dans copy-watch-reviewed.txt fait échouer l'exécution : GitHub prévient
# alors le propriétaire par e-mail. Après examen, ajouter le dépôt à copy-watch-reviewed.txt.
#
# Usage : watch-copies.sh <propriétaire>   (GH_TOKEN dans l'environnement : recherche de code)
set -uo pipefail
OWNER=$1
REVIEWED_FILE=${REVIEWED_FILE:-copy-watch-reviewed.txt}
SUMMARY=${GITHUB_STEP_SUMMARY:-/dev/stdout}

# Identifiants propres à chaque plugin : espaces de noms PHP, préfixes de tables et de constantes.
MARKERS=(
  'GlpiPlugin\Grcmanager' 'glpi_plugin_grcmanager_' 'PLUGIN_GRCMANAGER_VERSION'
  'GlpiPlugin\Assetsign' 'glpi_plugin_assetsign_' 'PLUGIN_ASSETSIGN_VERSION'
  'GlpiPlugin\Configurationglpiauto' 'glpi_plugin_configurationglpiauto_' 'PLUGIN_CONFIGURATIONGLPIAUTO_VERSION'
  'GlpiPlugin\Dockerinventory' 'glpi_plugin_dockerinventory_' 'PLUGIN_DOCKERINVENTORY_VERSION'
)

declare -A FOUND=()
errors=0
for marker in "${MARKERS[@]}"; do
  # La recherche de code est limitée en débit : petite pause entre deux requêtes.
  sleep 7
  if ! out=$(gh search code "\"$marker\"" --limit 100 --json repository,path \
      --jq '.[] | [.repository.nameWithOwner, .path] | @tsv' 2>&1); then
    echo "::warning::recherche impossible pour « $marker » : $out"
    errors=$((errors + 1))
    continue
  fi
  while IFS=$'\t' read -r repo path; do
    [ -n "$repo" ] || continue
    [ "${repo%%/*}" = "$OWNER" ] && continue
    FOUND[$repo]+="$marker ($path)"$'\n'
  done <<< "$out"
done

if [ "$errors" -eq "${#MARKERS[@]}" ]; then
  echo "::error::la recherche de code a échoué pour tous les identifiants (jeton sans accès à la recherche ?)"
  exit 2
fi

declare -A REVIEWED=()
if [ -f "$REVIEWED_FILE" ]; then
  while read -r line; do
    line=${line%%#*}; line=${line// /}
    [ -n "$line" ] && REVIEWED[$line]=1
  done < "$REVIEWED_FILE"
fi

new=0
{
  echo "## Reprises possibles du code des plugins de $OWNER"
  echo
  if [ ${#FOUND[@]} -eq 0 ]; then
    echo "Aucun dépôt hors $OWNER ne contient les identifiants des plugins."
  else
    echo "| Dépôt | Cite l'origine ? | Déjà examiné | Identifiants trouvés |"
    echo "|---|---|---|---|"
  fi
} >> "$SUMMARY"

for repo in "${!FOUND[@]}"; do
  sleep 7
  cites="non trouvé"
  if gh search code "GUILLOTTE" --repo "$repo" --limit 1 --json path --jq 'length' 2>/dev/null | grep -qv '^0$' \
     || gh search code "github.com/$OWNER" --repo "$repo" --limit 1 --json path --jq 'length' 2>/dev/null | grep -qv '^0$'; then
    cites="oui"
  fi
  reviewed="non"
  [ -n "${REVIEWED[$repo]:-}" ] && reviewed="oui"
  [ "$reviewed" = "non" ] && new=$((new + 1))
  markers=$(printf '%s' "${FOUND[$repo]}" | head -3 | tr '\n' ';' | sed 's/;$//; s/|/\\|/g')
  echo "| [$repo](https://github.com/$repo) | $cites | $reviewed | $markers |" >> "$SUMMARY"
  echo "$repo : cite l'origine $cites, examiné $reviewed"
done

echo "${#FOUND[@]} dépôt(s) trouvé(s), $new nouveau(x) à examiner."
if [ "$new" -gt 0 ]; then
  echo "::error::$new dépôt(s) à examiner : voir le résumé de l'exécution, puis l'ajouter à $REVIEWED_FILE"
  exit 1
fi
