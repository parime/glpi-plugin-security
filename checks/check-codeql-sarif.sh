#!/usr/bin/env bash
# Résultats d'une analyse CodeQL (fichiers SARIF du dossier de sortie de github/codeql-action/analyze,
# lancé avec upload: never) : chaque résultat non justifié fait échouer le contrôle.
#
# Usage : check-codeql-sarif.sh <dossier SARIF> [fichier d'exceptions]
# Exception justifiée : « codeql <règle> <fichier> : <raison> », où <fichier> peut être un motif
# (« public/lib/* »), par exemple :
#   codeql js/xss-through-dom public/js/preview.js : contenu déjà purifié par DOMPurify
# Code de sortie 1 si un résultat non justifié reste.
set -u
sarif_dir=${1:?dossier SARIF attendu} exceptions=${2:-}
here=$(cd "$(dirname "$0")" && pwd)
command -v jq >/dev/null || { echo "::error::jq est requis"; exit 2; }

if [ -z "$(find "$sarif_dir" -name '*.sarif' 2>/dev/null | head -1)" ]; then
  echo "::error::aucun fichier SARIF dans $sarif_dir : l'analyse CodeQL n'a rien produit"
  exit 2
fi

mapfile -t allowed < <(bash "$here/security-exceptions.sh" "$exceptions" codeql) || exit 1

total=0 kept=0
while IFS=$'\t' read -r rule file line message; do
  [ -n "$rule" ] || continue
  total=$((total + 1))
  justified=
  for a in "${allowed[@]}"; do
    a_rule=${a%% *} a_path=${a#* }
    # shellcheck disable=SC2053 # motif voulu dans la partie droite
    if [ "$a_rule" = "$rule" ] && [[ $file == $a_path ]]; then justified=1; break; fi
  done
  if [ -n "$justified" ]; then
    echo "EXCEPTION  codeql $rule $file:$line (justifiée dans le fichier d'exceptions)"
    continue
  fi
  kept=$((kept + 1))
  echo "::error file=$file,line=$line::CodeQL $rule : $message"
done < <(find "$sarif_dir" -name '*.sarif' -print0 | xargs -0 -r jq -r '
  .runs[].results[]? |
  [ .ruleId,
    (.locations[0].physicalLocation.artifactLocation.uri // "?"),
    ((.locations[0].physicalLocation.region.startLine // 0) | tostring),
    (.message.text | gsub("[\t\n\r]"; " ")) ] | @tsv')

echo "CODEQL $total résultat(s), $kept non justifié(s)"
if [ "$kept" -gt 0 ]; then
  echo "::error::CodeQL : corriger chaque résultat, ou le justifier par « codeql <règle> <fichier> : <raison> » dans le fichier d'exceptions"
  exit 1
fi
