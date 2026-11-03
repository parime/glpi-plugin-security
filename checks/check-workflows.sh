#!/usr/bin/env bash
# Workflows GitHub Actions d'un plugin : YAML valide et syntaxe GitHub Actions correcte (actionlint).
# Un workflow invalide ne démarre plus du tout (« workflow file issue ») : la CI se tait au lieu
# d'échouer, et les PR paraissent vertes. Cas réel : un « : » dans un nom d'étape non cité a coupé
# la CI d'assetsign pendant deux jours (assetsign #295).
#
# Usage : check-workflows.sh <dossier du plugin>   (code de sortie 1 si un workflow est invalide)
# Seules les erreurs de structure et d'expression comptent : les remarques de style des scripts
# shell (shellcheck) ne bloquent pas. actionlint est téléchargé à une version épinglée et vérifié
# par son empreinte SHA-256 publiée.
set -u
dir=${1:-.}
cd "$dir" || exit 2

shopt -s nullglob
workflows=(.github/workflows/*.yml .github/workflows/*.yaml)
if [ ${#workflows[@]} -eq 0 ]; then
  echo "Aucun workflow à contrôler."
  exit 0
fi

version=1.7.7
sha256=023070a287cd8cccd71515fedc843f1985bf96c436b7effaecce67290e7e0757
bin=${ACTIONLINT:-}
if [ -z "$bin" ]; then
  tmp=$(mktemp -d)
  archive="$tmp/actionlint.tar.gz"
  curl -fsSL -o "$archive" \
    "https://github.com/rhysd/actionlint/releases/download/v${version}/actionlint_${version}_linux_amd64.tar.gz" \
    || { echo "::error::téléchargement d'actionlint impossible"; exit 2; }
  echo "$sha256  $archive" | sha256sum -c --quiet - \
    || { echo "::error::empreinte d'actionlint inattendue : archive refusée"; exit 2; }
  tar -xzf "$archive" -C "$tmp" actionlint
  bin="$tmp/actionlint"
fi

# Modèle de sortie d'actionlint (syntaxe Go), pas une expansion du shell.
# shellcheck disable=SC2016
if "$bin" -no-color -shellcheck= -pyflakes= \
  -format '{{range $err := .}}::error file={{$err.Filepath}},line={{$err.Line}}::{{$err.Message}} [{{$err.Kind}}]\n{{end}}' "${workflows[@]}"; then
  echo "${#workflows[@]} workflow(s) valide(s)."
  exit 0
fi
echo "Workflow invalide : il ne démarrerait pas sur GitHub."
exit 1
