#!/usr/bin/env bash
# Parcours de toutes les pages du plugin dans le GLPI démarré par start-glpi.sh (issue #23) :
# installation, jeu de données facultatif, données lues dans GLPI (crawl-data.php), parcours dans
# Chromium par chaque compte (crawl-pages.py, image Playwright), puis journaux PHP et SQL de GLPI.
#
# Usage : check-pages.sh <dossier du plugin> <clé> [fichier d'exceptions] [commande de jeu de données]
#                        [mode error|warning] [seuil en ms] [comptes « identifiant:mot-de-passe » séparés par des espaces]
#   Comptes par défaut : « glpi:glpi tech:tech » (administrateur, puis technicien sans droit
#   d'administration). Dossier de travail : CRAWL_WORK (défaut : dossier temporaire).
#
# Sortie : les anomalies, puis « RESULT crawl pages=<n> anomalies=<n> » et « RESULT logs=<0|1> » ;
# code 1 s'il reste une anomalie en mode error.
set -uo pipefail
DIR=$1 KEY=$2 EXCEPTIONS=${3:-} SEED=${4:-} MODE=${5:-error} MAX_MS=${6:-3000} ACCOUNTS=${7:-glpi:glpi tech:tech}
C=${GLPI_CONTAINER:-glpisec}
HERE=$(cd "$(dirname "$0")" && pwd)
P=/var/www/glpi/plugins/$KEY
LOGS=/var/glpi/logs
W=${CRAWL_WORK:-$(mktemp -d)}
mkdir -p "$W"
LEVEL=$([ "$MODE" = warning ] && echo warning || echo error)
# Image Playwright de la CI de GRC (tests/security/js-errors.py) ; playwright Python, même version,
# installé au lancement. axe-core épinglé par son empreinte.
PLAYWRIGHT_IMAGE=mcr.microsoft.com/playwright/python:v1.57.0-noble@sha256:3de745b23fc4b33fccbcb3f592ee52dd5c80ce79f19f839c825ce23364e403c1
AXE_URL=https://cdn.jsdelivr.net/npm/axe-core@4.10.3/axe.min.js
AXE_SHA256=880970c081707360e64f34cea25ff91892f5bc95675b0776925b9709dd8a68bb
# Chemin d'un dossier pour docker run -v (Git Bash sous Windows : F:/...).
hostpath() { (cd "$1" && { pwd -W 2>/dev/null || pwd; }); }

docker exec -u root "$C" rm -rf "$P"
docker cp "$DIR/." "$C:$P"
docker exec -u root "$C" chown -R www-data:www-data "$P" /var/glpi/files
if [ -f "$DIR/composer.json" ]; then
  docker exec -u www-data "$C" sh -c "cd $P && COMPOSER_HOME=/tmp/composer-home php /tmp/composer.phar install --no-dev --no-interaction --quiet" \
    || { echo "::error::composer install a échoué"; exit 1; }
fi
docker exec -u www-data "$C" sh -c "cd /var/www/glpi && php bin/console plugin:install $KEY --force -n && php bin/console plugin:activate $KEY -n" \
  || { echo "::error::installation ou activation du plugin impossible"; exit 1; }
if [ -n "$SEED" ]; then
  docker exec -u www-data "$C" sh -c "cd /var/www/glpi && $SEED" \
    || echo "::warning::la commande de jeu de données a échoué : parcours sur les données existantes"
fi
for log in php-errors sql-errors; do docker exec -u root "$C" sh -c ": > $LOGS/$log.log" 2>/dev/null || true; done

ADMIN=${ACCOUNTS%% *}
docker cp "$HERE/crawl-data.php" "$C":/tmp/crawl-data.php
docker exec -u www-data "$C" php /tmp/crawl-data.php "$KEY" "${ADMIN%%:*}" "${ADMIN#*:}" > "$W/data.json" \
  || { echo "::error::données du parcours illisibles (crawl-data.php)"; cat "$W/data.json"; exit 1; }
echo "Données : $(tail -1 "$W/data.json" | head -c 2000)"
if [ "$(sha256sum "$W/axe.min.js" 2>/dev/null | cut -d' ' -f1)" != "$AXE_SHA256" ]; then
  curl -fsSL "$AXE_URL" -o "$W/axe.min.js" || { echo "::error::axe-core indisponible"; exit 1; }
  [ "$(sha256sum "$W/axe.min.js" | cut -d' ' -f1)" = "$AXE_SHA256" ] || { echo "::error::empreinte d'axe-core inattendue"; exit 1; }
fi
EXC_ARG=()
if [ -n "$EXCEPTIONS" ] && [ -f "$DIR/$EXCEPTIONS" ]; then
  cp "$DIR/$EXCEPTIONS" "$W/exceptions"
  EXC_ARG=(--exceptions /work/exceptions)
fi
ACC_ARG=()
for a in $ACCOUNTS; do ACC_ARG+=(--account "$a"); done

# Le navigateur partage le réseau du conteneur GLPI : http://localhost est GLPI lui-même.
MSYS_NO_PATHCONV=1 docker run --rm --network "container:$C" \
  -v "$(hostpath "$HERE"):/checks:ro" -v "$(hostpath "$W"):/work" "$PLAYWRIGHT_IMAGE" \
  sh -c 'pip install --quiet --disable-pip-version-check --root-user-action=ignore playwright==1.57.0 && exec python /checks/crawl-pages.py "$@"' sh \
  http://localhost --key "$KEY" --data /work/data.json --axe /work/axe.min.js --max-ms "$MAX_MS" --mode "$MODE" \
  "${EXC_ARG[@]}" "${ACC_ARG[@]}" | tee "$W/crawl.log"
CRAWL=${PIPESTATUS[0]}
grep -q '^RESULT crawl' "$W/crawl.log" || { echo "::error::le parcours n'a pas abouti"; CRAWL=1; }

echo "== Journaux PHP et SQL de GLPI pendant le parcours"
LOGERR=0
export BENIGN='[*][*][*] Test logger$'
for log in php-errors sql-errors; do
  docker exec "$C" cat "$LOGS/$log.log" > "$W/$log.log" 2>/dev/null || true
  awk '/^\[[0-9][0-9][0-9][0-9]-/ { keep = ($0 !~ ENVIRON["BENIGN"]) } keep' "$W/$log.log" > "$W/$log.kept"
  if [ -s "$W/$log.kept" ]; then
    echo "::$LEVEL::crawl $log : erreurs écrites dans $log.log pendant le parcours :"
    head -40 "$W/$log.kept"
    LOGERR=1
  fi
done
echo "RESULT logs=$LOGERR"
[ "$MODE" = warning ] && exit 0
[ "$CRAWL" = 0 ] && [ "$LOGERR" = 0 ]
