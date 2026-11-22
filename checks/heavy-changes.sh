#!/usr/bin/env bash
# Contrôles lourds nécessaires ? Les contrôles qui démarrent un vrai GLPI (onglets et
# notifications, tests d'intégration, mise à jour, parcours des pages) ne servent à rien quand une
# pull request ne touche que de la documentation : ils ne tournent alors pas (« skipped », accepté
# comme réussi pour un contrôle exigé). Dans le doute, ils tournent toujours.
#
# Usage : heavy-changes.sh <dossier du dépôt> <événement GitHub> [motifs]
#   Motifs (ou variable HEAVY_PATHS) : séparés par des espaces ou des sauts de ligne, chemins depuis
#   la racine du dépôt, où « * » traverse les dossiers (« *.php » vise tout fichier PHP, « src/* »
#   tout ce qui est sous src/, « docs/ » vaut « docs/* »). « !motif » exclut ; pour chaque fichier,
#   le dernier motif qui lui correspond l'emporte (comme .gitignore). Exemple, tout sauf la
#   documentation : « * !docs/* !*.md ».
#
# Lourds (heavy=true) : tout événement autre que pull_request (push sur main ou dev, étiquette,
# lancement manuel, planification) ; aucun motif ; liste des fichiers modifiés illisible ou vide ;
# au moins un fichier modifié, ajouté, supprimé ou renommé (ancien et nouveau nom) qui correspond.
# Les fichiers de la PR sont ceux du commit de fusion que GitHub prépare (refs/pull/<n>/merge,
# extrait par actions/checkout avec fetch-depth: 2) : différence entre son premier parent (la base
# à jour) et lui.
#
# Sortie : la décision et les fichiers qui la motivent, puis « heavy=true » ou « heavy=false » en
# dernière ligne, aussi écrit dans $GITHUB_OUTPUT s'il est défini. Code 0 dans tous les cas.
set -u
DIR=${1:?dossier du dépôt attendu} EVENT=${2:?événement attendu}
PATTERNS=${3-${HEAVY_PATHS:-}}

decide() {
  echo "$2"
  echo "heavy=$1"
  if [ -n "${GITHUB_OUTPUT:-}" ]; then echo "heavy=$1" >> "$GITHUB_OUTPUT"; fi
  exit 0
}

case "$EVENT" in
  pull_request|pull_request_target) ;;
  *) decide true "Événement « $EVENT » : tous les contrôles tournent." ;;
esac
set -f
# shellcheck disable=SC2206 # découpage voulu : un motif par mot, sans développement (set -f)
pats=($PATTERNS)
set +f
[ ${#pats[@]} -gt 0 ] || decide true "Aucun motif heavy-paths : tous les contrôles tournent."

git -C "$DIR" rev-parse -q --verify 'HEAD^2' >/dev/null 2>&1 \
  || decide true "Pas de commit de fusion de pull request (HEAD^2 absent) : tous les contrôles tournent."
# --no-renames : un fichier déplacé compte sous son ancien et son nouveau nom (src/a.php -> docs/a.md
# reste lourd).
if ! files=$(git -C "$DIR" diff --name-only --no-renames 'HEAD^1' HEAD 2>&1); then
  decide true "Liste des fichiers modifiés illisible ($files) : tous les contrôles tournent."
fi
[ -n "$files" ] || decide true "Aucun fichier modifié : tous les contrôles tournent."

heavy=()
total=0
while IFS= read -r f; do
  [ -n "$f" ] || continue
  total=$((total + 1))
  match=0
  for p in "${pats[@]}"; do
    neg=0
    case "$p" in '!'*) neg=1; p=${p#!} ;; esac
    case "$p" in */) p="$p*" ;; esac
    # shellcheck disable=SC2053 # comparaison à un motif voulue
    if [[ $f == $p ]]; then
      match=$((1 - neg))
    fi
  done
  [ "$match" = 1 ] && heavy+=("$f")
done <<< "$files"

if [ ${#heavy[@]} -gt 0 ]; then
  decide true "${#heavy[@]} fichier(s) sur $total concerné(s) par les contrôles lourds, par exemple :
$(printf '  %s\n' "${heavy[@]:0:20}")"
fi
decide false "Aucun des $total fichier(s) modifié(s) ne concerne les contrôles lourds (motifs : ${pats[*]}) : ils ne tournent pas."
