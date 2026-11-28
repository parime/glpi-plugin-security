#!/usr/bin/env bash
# Protège les branches main et dev d'un dépôt de plugin contre la suppression et le force push, et
# impose une PR relue pour y écrire. À lancer une fois à la création du dépôt (droits
# d'administration du dépôt nécessaires), puis à chaque nouvelle branche dev.
#
# Pourquoi : un dépôt réglé pour supprimer la branche source après fusion (delete_branch_on_merge)
# supprime main à la fusion d'une PR main vers dev, si main n'est pas protégée (cas réel :
# glpi-finops, novembre 2026, plus aucune publication possible). Une branche protégée n'est jamais
# supprimée par GitHub. L'audit hebdomadaire (checks/audit-repositories.sh) signale tout dépôt de
# plugin dont main, ou dev quand elle existe, n'est pas protégée.
#
# Une protection déjà en place (contrôles obligatoires, nombre de relectures) est conservée : seules
# la suppression et le force push sont interdits. Sans protection, une protection de base est créée
# (une relecture, sans contrôle obligatoire : les ajouter ensuite, dont le job « security »).
#
# Usage : protect-branches.sh <propriétaire/dépôt>   (gh authentifié et jq ; idempotent)
set -euo pipefail
REPO=${1:?usage : protect-branches.sh <propriétaire/dépôt>}
status=0
for branch in main dev; do
  if ! gh api "repos/$REPO/branches/$branch" --silent 2>/dev/null; then
    [ "$branch" = main ] && { echo "$REPO : branche main absente, à recréer avant tout"; status=1; }
    continue
  fi
  current=$(gh api "repos/$REPO/branches/$branch/protection" 2>/dev/null || true)
  if [ -n "$current" ] && [ "$(jq -r '.allow_deletions.enabled == false and .allow_force_pushes.enabled == false' <<< "$current")" = true ]; then
    echo "$REPO $branch : déjà protégée (suppression et force push interdits), inchangée"
    continue
  fi
  signatures=false
  if [ -n "$current" ]; then
    # Protection existante à compléter : réécrite à l'identique (PUT, l'API n'a pas d'appel dédié
    # à la suppression), avec la suppression et le force push interdits.
    signatures=$(jq -r '.required_signatures.enabled // false' <<< "$current")
    body=$(jq '{
      required_status_checks: (if .required_status_checks then {strict: .required_status_checks.strict, checks: .required_status_checks.checks} else null end),
      enforce_admins: (.enforce_admins.enabled // false),
      required_pull_request_reviews: (if .required_pull_request_reviews then {
        dismiss_stale_reviews: .required_pull_request_reviews.dismiss_stale_reviews,
        require_code_owner_reviews: .required_pull_request_reviews.require_code_owner_reviews,
        required_approving_review_count: .required_pull_request_reviews.required_approving_review_count} else null end),
      restrictions: null,
      required_linear_history: (.required_linear_history.enabled // false),
      allow_force_pushes: false,
      required_conversation_resolution: (.required_conversation_resolution.enabled // false),
      allow_deletions: false}' <<< "$current")
  else
    body='{"required_status_checks":null,"enforce_admins":false,"required_pull_request_reviews":{"required_approving_review_count":1},"restrictions":null,"allow_force_pushes":false,"allow_deletions":false}'
  fi
  gh api -X PUT "repos/$REPO/branches/$branch/protection" --input - --jq \
    '"'"$REPO $branch"' : suppression \(if .allow_deletions.enabled then "permise" else "interdite" end), force push \(if .allow_force_pushes.enabled then "permis" else "interdit" end)"' <<< "$body"
  # Les signatures obligatoires ont leur propre API : remises si elles étaient en place.
  [ "$signatures" = true ] && gh api -X POST "repos/$REPO/branches/$branch/protection/required_signatures" --silent
done
exit $status
