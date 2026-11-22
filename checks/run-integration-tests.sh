#!/usr/bin/env bash
# Tests d'intégration du plugin dans une langue donnée (issue #26), dans le GLPI démarré par
# start-glpi.sh : la CI des plugins tourne en anglais, l'instance de développement en français, et
# un test qui dépend de la langue passe dans l'une et échoue dans l'autre.
#
# La langue est réglée pour l'instance (configuration « language » de GLPI, utilisée pour toute
# session sans langue propre) et pour chaque utilisateur existant (glpi, tech, normal, post-only),
# puis la suite d'intégration du plugin est lancée dans le dossier du plugin (utilisateur
# www-data, GLPI_ROOT_DIR=/var/www/glpi, GLPI_TEST_LANGUAGE=<langue>).
#
# Usage : run-integration-tests.sh <dossier du plugin> <clé> <langue> [commande de test]
#   Sans commande, la suite est détectée :
#     phpunit-integration.xml(.dist)            -> php vendor/bin/phpunit -c <fichier>
#     phpunit.xml(.dist) avec une suite « integration » -> ... --testsuite integration
#     phpunit.xml(.dist) sans cette suite         -> php vendor/bin/phpunit -c <fichier>
#   Aucune suite trouvée : avertissement, code 0.
#
# Les .mo absents (non versionnés) sont compilés avant la copie : par tools/build_locales.py s'il
# existe, sinon par msgfmt pour chaque locales/*.po sans .mo.
set -uo pipefail
DIR=$1 KEY=$2 LANG_CODE=$3 COMMAND=${4:-}
C=${GLPI_CONTAINER:-glpisec}
DB=${GLPI_DB_CONTAINER:-glpisec-db}
P=/var/www/glpi/plugins/$KEY

if ! [[ $LANG_CODE =~ ^[a-z]{2,3}_[A-Z]{2,3}$ ]]; then
  echo "::error::langue invalide : $LANG_CODE (attendu : fr_FR, en_GB...)"; exit 2
fi

if [ -z "$COMMAND" ]; then
  for f in phpunit-integration.xml phpunit-integration.xml.dist; do
    [ -f "$DIR/$f" ] && { COMMAND="php vendor/bin/phpunit -c $f"; break; }
  done
fi
if [ -z "$COMMAND" ]; then
  for f in phpunit.xml phpunit.xml.dist; do
    [ -f "$DIR/$f" ] || continue
    if grep -q '<testsuite[^>]*name="integration"' "$DIR/$f"; then
      COMMAND="php vendor/bin/phpunit -c $f --testsuite integration"
    else
      COMMAND="php vendor/bin/phpunit -c $f"
    fi
    break
  done
fi
if [ -z "$COMMAND" ]; then
  echo "::warning::aucune suite d'intégration trouvée (phpunit-integration.xml.dist, phpunit.xml) : passe en $LANG_CODE non lancée. Commande explicite : entrée integration-command."
  exit 0
fi

# Traductions compilées : sans .mo, la passe en français afficherait les textes anglais.
if [ -d "$DIR/locales" ]; then
  # gettext (msgfmt, msgcat) est nécessaire aux deux chemins : build_locales.py l'appelle aussi (GRC, v1.10.0).
  command -v msgfmt >/dev/null || { sudo apt-get update -qq && sudo apt-get install -y -qq --no-install-recommends gettext >/dev/null; }
  if [ -f "$DIR/tools/build_locales.py" ]; then
    (cd "$DIR" && python3 tools/build_locales.py) || { echo "::error::tools/build_locales.py a échoué"; exit 1; }
  fi
  for po in "$DIR"/locales/*.po; do
    [ -f "$po" ] && [ ! -f "${po%.po}.mo" ] || continue
    msgfmt -o "${po%.po}.mo" "$po" || { echo "::error::compilation de $po impossible"; exit 1; }
  done
fi

docker exec -u root "$C" rm -rf "$P"
docker cp "$DIR/." "$C:$P"
docker exec -u root "$C" chown -R www-data:www-data "$P" /var/glpi/files
AUTH=()
if [ -n "${GITHUB_TOKEN:-}" ]; then
  AUTH=(-e "COMPOSER_AUTH={\"github-oauth\":{\"github.com\":\"$GITHUB_TOKEN\"}}")
fi
composer() {
  docker exec "${AUTH[@]}" -u www-data "$C" sh -c "cd $P && COMPOSER_HOME=/tmp/composer-home php /tmp/composer.phar install --no-interaction --no-progress $1"
}
if [ -f "$DIR/composer.json" ]; then
  composer --no-dev || { echo "::error::composer install --no-dev a échoué"; exit 1; }
fi
docker exec -u www-data "$C" sh -c "cd /var/www/glpi && php bin/console plugin:install $KEY --username=glpi --force -n && php bin/console plugin:activate $KEY -n" \
  || { echo "::error::installation ou activation du plugin impossible"; exit 1; }

# Langue de l'instance, puis de chaque utilisateur (un utilisateur sans langue suit l'instance).
docker exec -u www-data "$C" php /var/www/glpi/bin/console config:set language "$LANG_CODE" -n \
  || { echo "::error::langue de l'instance non réglée"; exit 1; }
docker exec "$DB" mariadb -uglpi -pglpi glpidb -e "UPDATE glpi_users SET language = '$LANG_CODE'" \
  || { echo "::error::langue des utilisateurs non réglée"; exit 1; }
docker exec "$DB" mariadb -uglpi -pglpi glpidb -N -e \
  "SELECT CONCAT('Langue : instance ', (SELECT value FROM glpi_configs WHERE context = 'core' AND name = 'language'), ', utilisateurs ', GROUP_CONCAT(DISTINCT IFNULL(language, '(instance)'))) FROM glpi_users"

# Dépendances de développement (PHPUnit) après les commandes console : la symfony/console du
# plugin pourrait sinon entrer en collision avec celle de GLPI.
if [ -f "$DIR/composer.json" ]; then
  composer "" || { echo "::error::composer install (dépendances de développement) a échoué"; exit 1; }
fi

echo "Tests d'intégration en $LANG_CODE : $COMMAND"
docker exec -u www-data -e GLPI_ROOT_DIR=/var/www/glpi -e GLPI_TEST_LANGUAGE="$LANG_CODE" "$C" sh -c "cd $P && $COMMAND"
status=$?
if [ $status -ne 0 ]; then
  echo "::error::les tests d'intégration échouent en $LANG_CODE : un test dépend sans doute de la langue (libellé traduit attendu, sprintf(__()), tri alphabétique). Comparer un identifiant ou une clé, ou forcer puis restaurer la langue dans le test."
fi
exit $status
