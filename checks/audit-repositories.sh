#!/usr/bin/env bash
# Liste les dépôts de plugins GLPI de <propriétaire> qui n'appellent pas le workflow commun, ou qui
# l'appellent dans une version antérieure à la dernière étiquette publiée de ce dépôt, et tient à
# jour une issue de <dépôt d'issues> (ouverte s'il en manque ou s'il y en a en retard, fermée sinon).
# Usage : audit-repositories.sh <propriétaire> <dépôt d'issues>   (GH_TOKEN dans l'environnement)
set -euo pipefail
OWNER=$1 ISSUES_REPO=$2
CALL='parime/glpi-plugin-security/.github/workflows/plugin-security.yml@'
TITLE="Dépôts de plugins GLPI sans le contrôle de sécurité commun"
# Dernière version publiée des contrôles : un plugin resté sur une ancienne étiquette ne reçoit pas
# les nouveaux contrôles (cas réel : une montée de version poussée après la fusion de sa PR).
LATEST=$(gh api "repos/parime/glpi-plugin-security/tags" --jq '[.[].name | select(test("^v[0-9]+\\.[0-9]+\\.[0-9]+$"))] | sort_by(ltrimstr("v") | split(".") | map(tonumber)) | last')

missing=()
outdated=()
plugins=0
while IFS=$'\t' read -r repo branch; do
  setup=$(gh api "repos/$OWNER/$repo/contents/setup.php?ref=$branch" --jq .content 2>/dev/null | base64 -d 2>/dev/null || true)
  grep -q 'function plugin_version_' <<< "$setup" || continue
  plugins=$((plugins + 1))
  version=""
  for wf in $(gh api "repos/$OWNER/$repo/contents/.github/workflows?ref=$branch" --jq '.[].path' 2>/dev/null || true); do
    content=$(gh api "repos/$OWNER/$repo/contents/$wf?ref=$branch" --jq .content | base64 -d)
    if grep -qF "$CALL" <<< "$content"; then
      version=$(grep -o "plugin-security.yml@[^[:space:]]*" <<< "$content" | head -1 | cut -d@ -f2)
      break
    fi
  done
  if [ -z "$version" ]; then
    echo "$repo ($branch) : absent"
    missing+=("$repo")
  elif [ "$version" != "$LATEST" ]; then
    echo "$repo ($branch) : $version, en retard sur $LATEST"
    outdated+=("$repo|$version")
  else
    echo "$repo ($branch) : $version"
  fi
done < <(gh api "users/$OWNER/repos?per_page=100" --paginate \
  --jq '.[] | select(.archived | not) | select(.fork | not) | [.name, .default_branch] | @tsv')

echo "$plugins dépôt(s) de plugin, ${#missing[@]} sans le contrôle commun, ${#outdated[@]} en retard sur $LATEST."
if [ "${DRY_RUN:-0}" = 1 ]; then exit 0; fi
issue=$(gh issue list -R "$ISSUES_REPO" --state open --search "\"$TITLE\" in:title" --json number --jq '.[0].number // empty')
if [ ${#missing[@]} -eq 0 ] && [ ${#outdated[@]} -eq 0 ]; then
  [ -z "$issue" ] || gh issue close "$issue" -R "$ISSUES_REPO" --comment "Tous les dépôts de plugins appellent le contrôle commun, en $LATEST."
  exit 0
fi
body=""
if [ ${#missing[@]} -gt 0 ]; then
  body+="Ces dépôts de plugins GLPI n'appellent pas \`plugin-security.yml\` (voir le README de ce dépôt pour l'ajouter en une ligne) :"$'\n\n'
  for repo in "${missing[@]}"; do body+="- [ ] [$OWNER/$repo](https://github.com/$OWNER/$repo)"$'\n'; done
  body+=$'\n'
fi
if [ ${#outdated[@]} -gt 0 ]; then
  body+="Ces dépôts appellent une version antérieure à **$LATEST** sur leur branche par défaut : ils ne reçoivent pas les derniers contrôles."$'\n\n'
  for entry in "${outdated[@]}"; do body+="- [ ] [$OWNER/${entry%%|*}](https://github.com/$OWNER/${entry%%|*}) : ${entry#*|}"$'\n'; done
fi
if [ -n "$issue" ]; then
  gh issue edit "$issue" -R "$ISSUES_REPO" --body "$body"
else
  gh issue create -R "$ISSUES_REPO" --title "$TITLE" --body "$body"
fi
exit 1
