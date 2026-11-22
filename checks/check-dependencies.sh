#!/usr/bin/env bash
# Vulnérabilités connues des dépendances d'un plugin GLPI (composer.lock, package-lock.json,
# yarn.lock... et vendor/ ou node_modules/ s'ils sont livrés) : Trivy, gravité HIGH et CRITICAL,
# seulement celles qu'une version corrigée permet de résoudre (--ignore-unfixed).
#
# Usage : check-dependencies.sh <dossier du plugin> [fichier d'exceptions]
# Trivy 0.76.1 tourne dans son image officielle, épinglée par son empreinte (Docker requis).
# TRIVY_CACHE_DIR (facultatif) : dossier local de la base de vulnérabilités, réutilisé d'un lancement
# à l'autre.
#
# Exception justifiée dans le fichier d'exceptions, par l'identifiant de l'avis :
#   trivy CVE-2026-12345 : code vulnérable jamais appelé par le plugin (issue #12)
# Code de sortie 1 si une vulnérabilité non justifiée est trouvée.
set -u
dir=${1:?dossier du plugin attendu} exceptions=${2:-}
here=$(cd "$(dirname "$0")" && pwd)
image=aquasec/trivy:0.76.1@sha256:790fd66d1697395d8693d1acaa1ea702406a20f109b6fc7bac8db623eca6c06c
dir=$(cd "$dir" && pwd) || exit 2

ignore=$(mktemp)
trap 'rm -f "$ignore"' EXIT
# Chemin relatif au dossier du plugin (.glpi-security-exceptions), ou chemin quelconque.
[ -n "$exceptions" ] && [ -f "$dir/$exceptions" ] && exceptions="$dir/$exceptions"
bash "$here/security-exceptions.sh" "$exceptions" trivy > "$ignore" || exit 1
chmod 644 "$ignore"
if [ -s "$ignore" ]; then
  echo "Exceptions justifiées : $(tr '\n' ' ' < "$ignore")"
fi

cache=()
[ -n "${TRIVY_CACHE_DIR:-}" ] && cache=(-v "$TRIVY_CACHE_DIR:/root/.cache")

if ! docker run --rm "${cache[@]}" -v "$dir:/src:ro" -v "$ignore:/tmp/trivyignore:ro" "$image" \
  fs --scanners vuln --pkg-types library --severity HIGH,CRITICAL --ignore-unfixed \
  --ignorefile /tmp/trivyignore --exit-code 1 --no-progress --skip-version-check /src; then
  echo "::error::dépendance vulnérable (Trivy) : passer à la version corrigée indiquée, ou justifier l'avis dans le fichier d'exceptions par la ligne « trivy <identifiant> : <raison> »"
  exit 1
fi
