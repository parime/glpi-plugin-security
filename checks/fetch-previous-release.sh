#!/usr/bin/env bash
# Récupère la dernière version publiée d'un plugin pour le test de mise à jour (check-upgrade.sh).
#
# Usage : fetch-previous-release.sh <dépôt owner/nom> <clé> <dossier de travail> <checkout du plugin>
#   Dernière release GitHub publiée (ni brouillon ni préversion), en écartant celle de l'étiquette
#   en cours de construction (GITHUB_REF_NAME sur un push d'étiquette). API REST seulement (gh api, GH_TOKEN).
#   - archive de la release (.zip, .tar.bz2, .tar.gz), vérifiée par son .sha256 s'il est publié,
#     extraite dans <dossier>/archive ; à défaut d'archive, les sources de l'étiquette ;
#   - sources de l'étiquette dans <dossier>/src (tests/ et le jeu de données, exclus de l'archive).
#
# Écrit « old=<dossier du plugin> », « old-src=<sources> » et « tag=<étiquette> » sur la sortie
# standard (et dans GITHUB_OUTPUT s'il existe). Sans release : avertissement, valeurs vides, code 0.
set -euo pipefail
REPO=$1 KEY=$2 WORK=$3 CHECKOUT=$4
mkdir -p "$WORK"
out() {
  printf 'old=%s\nold-src=%s\ntag=%s\n' "$1" "$2" "$3"
  if [ -n "${GITHUB_OUTPUT:-}" ]; then printf 'old=%s\nold-src=%s\ntag=%s\n' "$1" "$2" "$3" >> "$GITHUB_OUTPUT"; fi
}

current=""
[ "${GITHUB_REF_TYPE:-}" = tag ] && current=${GITHUB_REF_NAME:-}
# Une erreur de l'API arrête le job : seule une absence réelle de release est un avertissement.
tag=$(gh api "repos/$REPO/releases?per_page=20" --jq "[.[] | select(.draft | not) | select(.prerelease | not)
  | select(.tag_name != \"$current\")] | first | .tag_name // empty")
if [ -z "$tag" ]; then
  echo "::warning::aucune version publiée de $REPO : test de mise à jour sauté (premier plugin ?)" >&2
  out "" "" ""
  exit 0
fi
echo "Dernière version publiée : $tag" >&2

# Sources de l'étiquette, sans toucher au checkout (index temporaire).
src="$WORK/src"
mkdir -p "$src"
git -C "$CHECKOUT" fetch -q --depth 1 origin "refs/tags/$tag:refs/tags/$tag"
GIT_INDEX_FILE="$WORK/index" git -C "$CHECKOUT" read-tree "$tag"
GIT_INDEX_FILE="$WORK/index" git -C "$CHECKOUT" checkout-index -a --prefix="$(cd "$src" && pwd)/"
rm -f "$WORK/index"

# Fichiers publiés : « <id> <nom> », une ligne par fichier.
assets=$(gh api "repos/$REPO/releases/tags/$tag" --jq '.assets[] | "\(.id) \(.name)"')
asset=$(grep -E '\.(zip|tar\.bz2|tar\.gz|tgz)$' <<< "$assets" | head -1 || true)
if [ -z "$asset" ]; then
  echo "::warning::la release $tag ne publie pas d'archive : mise à jour testée depuis les sources de l'étiquette" >&2
  out "$src" "$src" "$tag"
  exit 0
fi
name=${asset#* }
gh api -H 'Accept: application/octet-stream' "repos/$REPO/releases/assets/${asset%% *}" > "$WORK/$name"
sum_id=$(awk -v n="$name.sha256" '$2 == n {print $1}' <<< "$assets")
if [ -n "$sum_id" ]; then
  expected=$(gh api -H 'Accept: application/octet-stream' "repos/$REPO/releases/assets/$sum_id" | awk '{print $1}')
  actual=$(sha256sum "$WORK/$name" | awk '{print $1}')
  [ "$expected" = "$actual" ] || { echo "::error::empreinte SHA-256 de $name incorrecte ($actual au lieu de $expected)" >&2; exit 1; }
  echo "Empreinte SHA-256 de $name vérifiée." >&2
fi
mkdir -p "$WORK/archive"
case "$name" in
  *.zip) unzip -q "$WORK/$name" -d "$WORK/archive" ;;
  *.tar.bz2) tar xjf "$WORK/$name" -C "$WORK/archive" ;;
  *) tar xzf "$WORK/$name" -C "$WORK/archive" ;;
esac
# Dossier du plugin : celui dont setup.php déclare plugin_version_<clé>.
old=$(grep -l "function plugin_version_$KEY\b" "$WORK"/archive/setup.php "$WORK"/archive/*/setup.php 2>/dev/null | head -1 || true)
if [ -z "$old" ]; then
  echo "::warning::archive $name sans setup.php du plugin $KEY : mise à jour testée depuis les sources de l'étiquette" >&2
  out "$src" "$src" "$tag"
  exit 0
fi
out "$(dirname "$old")" "$src" "$tag"
