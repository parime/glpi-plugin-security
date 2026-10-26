#!/usr/bin/env bash
# Liste les dépôts de plugins GLPI de <propriétaire> qui n'appellent pas le workflow commun, et tient
# à jour une issue de <dépôt d'issues> (ouverte s'il en manque, fermée sinon).
# Usage : audit-repositories.sh <propriétaire> <dépôt d'issues>   (GH_TOKEN dans l'environnement)
set -euo pipefail
OWNER=$1 ISSUES_REPO=$2
CALL='parime/glpi-plugin-security/.github/workflows/plugin-security.yml@'
TITLE="Dépôts de plugins GLPI sans le contrôle de sécurité commun"

missing=()
plugins=0
while IFS=$'\t' read -r repo branch; do
  setup=$(gh api "repos/$OWNER/$repo/contents/setup.php?ref=$branch" --jq .content 2>/dev/null | base64 -d 2>/dev/null || true)
  grep -q 'function plugin_version_' <<< "$setup" || continue
  plugins=$((plugins + 1))
  found=no
  for wf in $(gh api "repos/$OWNER/$repo/contents/.github/workflows?ref=$branch" --jq '.[].path' 2>/dev/null || true); do
    if gh api "repos/$OWNER/$repo/contents/$wf?ref=$branch" --jq .content | base64 -d | grep -qF "$CALL"; then
      found=yes
      break
    fi
  done
  echo "$repo ($branch) : $found"
  [ "$found" = yes ] || missing+=("$repo")
done < <(gh api "users/$OWNER/repos?per_page=100" --paginate \
  --jq '.[] | select(.archived | not) | select(.fork | not) | [.name, .default_branch] | @tsv')

echo "$plugins dépôt(s) de plugin, ${#missing[@]} sans le contrôle commun."
if [ "${DRY_RUN:-0}" = 1 ]; then exit 0; fi
issue=$(gh issue list -R "$ISSUES_REPO" --state open --search "\"$TITLE\" in:title" --json number --jq '.[0].number // empty')
if [ ${#missing[@]} -eq 0 ]; then
  [ -z "$issue" ] || gh issue close "$issue" -R "$ISSUES_REPO" --comment "Tous les dépôts de plugins appellent le contrôle commun."
  exit 0
fi
body="Ces dépôts de plugins GLPI n'appellent pas \`plugin-security.yml\` (voir le README de ce dépôt pour l'ajouter en une ligne) :"$'\n\n'
for repo in "${missing[@]}"; do body+="- [ ] [$OWNER/$repo](https://github.com/$OWNER/$repo)"$'\n'; done
if [ -n "$issue" ]; then
  gh issue edit "$issue" -R "$ISSUES_REPO" --body "$body"
else
  gh issue create -R "$ISSUES_REPO" --title "$TITLE" --body "$body"
fi
exit 1
