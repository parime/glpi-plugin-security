#!/usr/bin/env bash
# Charte commune des plugins GLPI de parime (CHARTE.md de parime/glpi-plugin-template) : la part
# qui se vérifie mécaniquement et sans faux positif.
#
#   - fichiers de licence et de citation : LICENSE (GPL v3), NOTICE, CITATION.cff, AGENTS.md ;
#   - licence déclarée GPL-3.0-or-later dans setup.php, composer.json et CITATION.cff ;
#   - en-tête de licence en tête de chaque fichier PHP et Twig du plugin (hors vendor, tests et
#     outillage de développement : .github, fichiers cachés) ;
#   - documentation : README.md (français) et README.en.md (anglais), chacun avec sa section
#     « Réutilisation » / « Reuse » ; CHANGELOG.md au format Keep a Changelog avec [Unreleased] ;
#     SECURITY.md ; lien vers la charte dans AGENTS.md et README.md ;
#   - les 18 langues communes dans locales/.
#
# Usage : check-charter.sh <dossier du plugin> [warning|error]
#   warning (par défaut) : annotations « ::warning:: », code de sortie 0 ;
#   error : annotations « ::error:: », code de sortie 1 si un écart est trouvé.
# Les fichiers sont ceux suivis par git (comme check-em-dash.sh).
set -u
dir=${1:-.}
mode=${2:-warning}
cd "$dir" || exit 2

case "$mode" in
  warning | error) ;;
  *) echo "Mode inconnu : $mode (warning ou error)"; exit 2 ;;
esac

LICENSE_ID='GPL-3.0-or-later'
LANGUAGES='de_DE en_GB en_US es_419 es_AR es_CL es_CO es_EC es_ES es_MX es_VE fr_BE fr_CA fr_FR it_IT pl_PL pt_BR pt_PT'
CHARTER='https://github.com/parime/glpi-plugin-template/blob/main/CHARTE.md'

problems=0
report() {
  # report <fichier ou vide> <message>
  problems=$((problems + 1))
  if [ -n "$1" ]; then
    echo "::$mode file=$1::$2"
  else
    echo "::$mode::$2"
  fi
}

# 1. Fichiers de licence, de citation et de documentation.
for f in LICENSE NOTICE CITATION.cff AGENTS.md README.md README.en.md CHANGELOG.md SECURITY.md; do
  [ -f "$f" ] || report "" "fichier $f absent (charte : licence et documentation)"
done

# 2. Licence : texte GPL v3 et identifiant déclaré partout.
if [ -f LICENSE ] && ! { grep -q 'GNU GENERAL PUBLIC LICENSE' LICENSE && grep -q 'Version 3' LICENSE; }; then
  report LICENSE "LICENSE n'est pas le texte de la GNU GPL version 3"
fi
if [ -f setup.php ] && ! grep -qE "'license'[[:space:]]*=>[[:space:]]*'$LICENSE_ID'" setup.php; then
  report setup.php "licence déclarée dans plugin_version_<clé>() : 'license' => '$LICENSE_ID' attendu"
fi
if [ -f composer.json ] && ! grep -qE "^[[:space:]]{2,4}\"license\"[[:space:]]*:[[:space:]]*\"$LICENSE_ID\"" composer.json; then
  report composer.json "licence de composer.json : \"license\": \"$LICENSE_ID\" attendu"
fi
if [ -f CITATION.cff ] && ! grep -qE "^license:[[:space:]]*\"?$LICENSE_ID\"?[[:space:]]*$" CITATION.cff; then
  report CITATION.cff "licence de CITATION.cff : license: $LICENSE_ID attendu"
fi

# 3. En-tête de licence des fichiers PHP et Twig (dans les 30 premières lignes).
total=0
missing=0
while IFS= read -r -d '' f; do
  total=$((total + 1))
  if ! head -n 30 "$f" | grep -qE "SPDX-License-Identifier:[[:space:]]*$LICENSE_ID|GNU General Public License"; then
    missing=$((missing + 1))
    if [ "$missing" -le 20 ]; then
      report "$f" "en-tête de licence absent (bloc de tools/HEADER ou ligne SPDX-License-Identifier: $LICENSE_ID)"
    fi
  fi
done < <(git ls-files -z -- '*.php' '*.twig' ':!.*' ':!vendor' ':!node_modules' ':!tests')
if [ "$missing" -gt 20 ]; then
  echo "... et $((missing - 20)) autre(s) fichier(s) sans en-tête."
  problems=$((problems + missing - 20))
fi
echo "En-têtes de licence : $((total - missing)) fichier(s) PHP et Twig sur $total."

# 4. Documentation.
if [ -f CHANGELOG.md ] && ! grep -qE '^## \[Unreleased\]' CHANGELOG.md; then
  report CHANGELOG.md "section « ## [Unreleased] » absente (format Keep a Changelog)"
fi
if [ -f README.md ] && ! grep -qiE '^#+ .*réutilisation' README.md; then
  report README.md "section « Réutilisation et citation » absente"
fi
if [ -f README.en.md ] && ! grep -qiE '^#+ .*reuse' README.en.md; then
  report README.en.md "section « Reuse and citation » absente"
fi
for f in AGENTS.md README.md; do
  if [ -f "$f" ] && ! grep -q 'CHARTE.md' "$f"; then
    report "$f" "aucun lien vers la charte commune ($CHARTER)"
  fi
done

# 5. Langues communes.
absent=''
for lang in $LANGUAGES; do
  [ -f "locales/$lang.po" ] || absent="$absent $lang"
done
if [ -n "$absent" ]; then
  report "" "langue(s) absente(s) de locales/ :$absent"
fi

if [ "$problems" -gt 0 ]; then
  echo "$problems écart(s) à la charte commune ($CHARTER)."
  if [ "$mode" = error ]; then
    exit 1
  fi
  echo "Mode avertissement : écarts signalés, contrôle non bloquant."
  exit 0
fi
echo "Conforme à la charte commune (part vérifiée automatiquement)."
