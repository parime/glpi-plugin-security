#!/usr/bin/env bash
# Verdict du parcours des pages découpé en tranches (issue #45) : réunit les rapports écrits par
# check-pages.sh avec CRAWL_SHARD=k/N (un sous-dossier par tranche : report.json, journaux PHP et SQL
# retenus *.kept), puis applique les exceptions du plugin (.glpi-security-exceptions et ses
# fragments .d/) et les modes comme le parcours d'un seul tenant. Aucun navigateur ni GLPI requis.
#
# Usage : check-pages-merge.sh <dossier du plugin> <dossier des rapports> <nombre de tranches>
#                              [fichier d'exceptions] [mode error|warning] [mode des barres d'onglets]
# Sortie : celle de check-pages.sh (anomalies, « RESULT crawl pages=<n> anomalies=<n> »,
# « RESULT logs=<0|1> ») ; code 1 s'il reste une anomalie, ou s'il manque une tranche, en mode error.
set -uo pipefail
DIR=$1 REPORTS=$2 COUNT=$3 EXCEPTIONS=${4:-} MODE=${5:-error} TABS_MODE=${6:-error}
LEVEL=$([ "$MODE" = warning ] && echo warning || echo error)
HERE=$(dirname "$0")
W=$(mktemp -d)

EXC_ARG=()
if [ -n "$EXCEPTIONS" ] && { [ -f "$DIR/$EXCEPTIONS" ] || [ -d "$DIR/$EXCEPTIONS.d" ]; }; then
  bash "$HERE/exceptions-cat.sh" "$DIR/$EXCEPTIONS" > "$W/exceptions"
  EXC_ARG=(--exceptions "$W/exceptions")
fi

shopt -s nullglob
reports=("$REPORTS"/*/report.json)
CRAWL=1
if [ ${#reports[@]} -eq 0 ]; then
  echo "::$LEVEL::crawl : aucun rapport de tranche dans $REPORTS ($COUNT attendus)"
  echo "RESULT crawl pages=0 anomalies=1"
else
  python3 "$HERE/crawl-pages.py" --merge "${reports[@]}" --shards "$COUNT" --mode "$MODE" --tabs-mode "$TABS_MODE" "${EXC_ARG[@]}"
  CRAWL=$?
fi

echo "== Journaux PHP et SQL de GLPI pendant le parcours"
LOGERR=0
for r in "${reports[@]}"; do
  d=$(dirname "$r")
  for log in php-errors sql-errors; do
    if [ -s "$d/$log.kept" ]; then
      echo "::$LEVEL::crawl $log : erreurs écrites dans $log.log pendant le parcours ($(basename "$d")) :"
      head -40 "$d/$log.kept"
      LOGERR=1
    fi
  done
done
echo "RESULT logs=$LOGERR"
[ "$MODE" = warning ] && exit 0
[ "$CRAWL" = 0 ] && [ "$LOGERR" = 0 ]
