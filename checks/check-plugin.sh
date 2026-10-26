#!/usr/bin/env bash
# Contrôle un plugin dans le GLPI démarré par start-glpi.sh : installation, onglets sans droit,
# cibles de notification, jeu de données facultatif, désinstallation, restes dans le cœur.
#
# Usage : check-plugin.sh <dossier du plugin> <clé du plugin> [fichier d'exceptions] [commande de jeu de données]
#   La commande de jeu de données s'exécute dans le conteneur (dossier de GLPI, utilisateur www-data)
#   avant la désinstallation, pour que le contrôle des restes porte sur de vraies données.
#
# Sortie : une ligne « RESULT tabs=<0|1> notifications=<0|1> leftovers=<0|1> », code 1 si un
# contrôle échoue.
set -uo pipefail
DIR=$1 KEY=$2 EXCEPTIONS=${3:-} SEED=${4:-}
C=${GLPI_CONTAINER:-glpisec}
HERE=$(cd "$(dirname "$0")" && pwd)
P=/var/www/glpi/plugins/$KEY

docker exec "$C" rm -rf "$P"
docker cp "$DIR/." "$C:$P"
docker cp "$HERE/glpi-runtime-checks.php" "$C":/tmp/glpi-runtime-checks.php
EXC_ARG=()
if [ -n "$EXCEPTIONS" ] && [ -f "$DIR/$EXCEPTIONS" ]; then
  docker cp "$DIR/$EXCEPTIONS" "$C":/tmp/glpi-security-exceptions
  EXC_ARG=(/tmp/glpi-security-exceptions)
fi
if [ -f "$DIR/composer.json" ]; then
  docker exec "$C" sh -c "cd $P && COMPOSER_HOME=/tmp/composer-home php /tmp/composer.phar install --no-dev --no-interaction --quiet" \
    || { echo "::error::composer install a échoué"; exit 1; }
fi
docker exec -u root "$C" chown -R www-data:www-data "$P" /var/glpi/files
docker exec -u www-data "$C" sh -c "cd /var/www/glpi && php bin/console plugin:install $KEY --force -n && php bin/console plugin:activate $KEY -n" \
  || { echo "::error::installation ou activation du plugin impossible"; exit 1; }

run() { docker exec -u www-data "$C" php /tmp/glpi-runtime-checks.php "$KEY" "$1" "${EXC_ARG[@]}"; }
run tabs; TABS=$?
run notifications; NOTIF=$?

if [ -n "$SEED" ]; then
  docker exec -u www-data "$C" sh -c "cd /var/www/glpi && $SEED" >/dev/null \
    || echo "::warning::la commande de jeu de données a échoué : contrôle des restes sur les seules données d'installation"
fi
docker exec -u www-data "$C" sh -c "cd /var/www/glpi && php bin/console plugin:deactivate $KEY -n && php bin/console plugin:uninstall $KEY -n" \
  || { echo "::error::désinstallation impossible"; exit 1; }
run leftovers; LEFT=$?

TABS=$([ $TABS -eq 0 ] && echo 0 || echo 1)
NOTIF=$([ $NOTIF -eq 0 ] && echo 0 || echo 1)
LEFT=$([ $LEFT -eq 0 ] && echo 0 || echo 1)
echo "RESULT tabs=$TABS notifications=$NOTIF leftovers=$LEFT"
[ "$TABS$NOTIF$LEFT" = "000" ]
