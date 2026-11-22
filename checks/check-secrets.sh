#!/usr/bin/env bash
# Secrets (mots de passe, jetons, clés d'API) écrits dans les fichiers d'un plugin GLPI : Gitleaks.
#
# Usage : check-secrets.sh <dossier du plugin> [fichier d'exceptions] [plage de commits]
#   - les fichiers du dossier sont toujours analysés (état actuel du dépôt) ;
#   - avec une plage (« base..tête », celle d'une pull request), chaque commit de la plage l'est
#     aussi : un secret ajouté puis retiré dans la même PR reste dans l'historique publié.
# Gitleaks 8.30.1 tourne dans son image officielle, épinglée par son empreinte (Docker requis).
#
# Exceptions justifiées dans le fichier d'exceptions, par l'empreinte qu'affiche Gitleaks :
#   gitleaks tests/fixtures/fake.php:generic-api-key:12 : clé factice d'un test unitaire
#   gitleaks <commit>:<fichier>:<règle>:<ligne> : secret révoqué, resté dans l'historique
# Code de sortie 1 si un secret non justifié est trouvé.
set -u
dir=${1:?dossier du plugin attendu} exceptions=${2:-} range=${3:-}
here=$(cd "$(dirname "$0")" && pwd)
image=zricethezav/gitleaks:v8.30.1@sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f
dir=$(cd "$dir" && pwd) || exit 2

ignore=$(mktemp)
trap 'rm -f "$ignore"' EXIT
# Chemin relatif au dossier du plugin (.glpi-security-exceptions et ses fragments .d/), ou chemin quelconque.
[ -n "$exceptions" ] && { [ -f "$dir/$exceptions" ] || [ -d "$dir/$exceptions.d" ]; } && exceptions="$dir/$exceptions"
bash "$here/security-exceptions.sh" "$exceptions" gitleaks > "$ignore" || exit 1
chmod 644 "$ignore"
if [ -s "$ignore" ]; then
  echo "Exceptions justifiées : $(wc -l < "$ignore")"
fi

run() {
  docker run --rm -v "$dir:/repo" -v "$ignore:/tmp/gitleaksignore:ro" -w /repo "$image" \
    "$@" --no-banner --redact --verbose --gitleaks-ignore-path /tmp/gitleaksignore --exit-code 1
}

status=0
echo "== Fichiers du dépôt"
run dir . || status=1
if [ -n "$range" ]; then
  echo "== Commits $range"
  run git . --log-opts="$range" || status=1
fi

if [ "$status" -ne 0 ]; then
  echo "::error::secret trouvé par Gitleaks : le retirer (et le révoquer s'il est réel), ou le justifier dans le fichier d'exceptions par la ligne « gitleaks <Fingerprint> : <raison> »"
fi
exit $status
