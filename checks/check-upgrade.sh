#!/usr/bin/env bash
# Test commun de mise à jour depuis la version publiée précédente (issue #24), dans le GLPI démarré
# par start-glpi.sh :
#   1. installation neuve du code de la PR : schéma, tâches, notifications et droits de référence ;
#   2. base remise à son état initial, installation et activation de la version publiée ;
#   3. jeu de données de cette version (tests/security/setup.php de son étiquette, ou commande donnée) ;
#   4. remplacement par le code de la PR, mise à jour (plugin:install --force, plugin:activate) ;
#   5. contrôles : aucune erreur PHP ni SQL, schéma identique à l'installation neuve (ordre des
#      colonnes et AUTO_INCREMENT ignorés), données toujours présentes, tâches automatiques,
#      notifications et droits conservés, pages principales servies (200).
#
# Usage : check-upgrade.sh <code de la PR> <clé> <version publiée> [sources de la version publiée]
#                          [fichier d'exceptions] [commande de jeu de données]
#   <version publiée> : dossier du plugin extrait de l'archive de la release (vide : aucune release,
#   le test est sauté avec un avertissement). <sources> : arbre de l'étiquette, d'où viennent
#   tests/ (exclus de l'archive) et le script de jeu de données. La commande de jeu de données
#   s'exécute dans le conteneur (dossier de GLPI, www-data) ; par défaut
#   « php plugins/<clé>/tests/security/setup.php » si ce script existe dans les sources.
#
# Sortie : « RESULT errors=<0|1> schema=<0|1> data=<0|1> objects=<0|1> pages=<0|1> », code 1 si un
# contrôle échoue. Conteneurs : GLPI_CONTAINER (glpisec), GLPI_DB_CONTAINER (glpisec-db).
set -uo pipefail
DIR=$1 KEY=$2 OLD=${3:-} OLD_SRC=${4:-} EXCEPTIONS=${5:-} SEED=${6:-}
C=${GLPI_CONTAINER:-glpisec}
DB=${GLPI_DB_CONTAINER:-glpisec-db}
HERE=$(cd "$(dirname "$0")" && pwd)
P=/var/www/glpi/plugins/$KEY
LOGS=/var/glpi/logs
W=$(mktemp -d)

sql() { docker exec -i "$DB" mariadb -uroot -pglpi "$@"; }
console() { docker exec -u www-data "$C" sh -c "cd /var/www/glpi && php bin/console $*"; }
# Copie un plugin dans le conteneur (dossier vidé d'abord, comme une mise à jour par l'archive :
# sinon les fichiers de l'ancienne version resteraient à côté des nouveaux).
deploy() {
  docker exec -u root "$C" rm -rf "$P"
  docker cp "$1/." "$C:$P"
  docker exec -u root "$C" chown -R www-data:www-data "$P" /var/glpi/files
  if docker exec "$C" test -f "$P/composer.json" && ! docker exec "$C" test -f "$P/vendor/autoload.php"; then
    docker exec -u www-data "$C" sh -c "cd $P && COMPOSER_HOME=/tmp/composer-home php /tmp/composer.phar install --no-dev --no-interaction --quiet" \
      || { echo "::error::composer install a échoué ($1)"; return 1; }
  fi
}
snapshot() { docker exec -u www-data "$C" php /tmp/glpi-upgrade-checks.php snapshot "$KEY" "/tmp/upgrade-$1.json"; }
# Base remise à son état d'avant le plugin : installation neuve et mise à jour partent du même GLPI.
restore() {
  sql -e "DROP DATABASE glpidb; CREATE DATABASE glpidb" && docker exec "$DB" sh -c 'mariadb -uroot -pglpi glpidb < /tmp/glpi-base.sql'
  docker exec -u root "$C" rm -rf "$P" "/var/glpi/files/_plugins/$KEY"
  console cache:clear -n >/dev/null 2>&1 || true
}
skip() { echo "::warning::$1"; echo "RESULT errors=0 schema=0 data=0 objects=0 pages=0 (mise à jour non testée)"; exit 0; }

docker cp "$HERE/glpi-upgrade-checks.php" "$C":/tmp/glpi-upgrade-checks.php
EXC=""
if [ -n "$EXCEPTIONS" ] && [ -f "$DIR/$EXCEPTIONS" ]; then
  docker cp "$DIR/$EXCEPTIONS" "$C":/tmp/glpi-security-exceptions
  EXC=/tmp/glpi-security-exceptions
fi
docker exec "$DB" sh -c 'mariadb-dump -uroot -pglpi --single-transaction glpidb > /tmp/glpi-base.sql' \
  || { echo "::error::sauvegarde de la base impossible"; exit 1; }
# GLPI rendu dans son état de départ en sortant, même en échec : un autre contrôle peut suivre dans
# le même conteneur.
trap 'restore; rm -rf "$W"' EXIT

echo "== Installation neuve du code de la PR (référence)"
deploy "$DIR" || exit 1
console plugin:install "$KEY" --force -n && console plugin:activate "$KEY" -n \
  || { echo "::error::installation neuve du code de la PR impossible"; exit 1; }
snapshot fresh || { echo "::error::instantané de l'installation neuve impossible"; exit 1; }

[ -n "$OLD" ] && [ -d "$OLD" ] || skip "aucune version publiée du plugin (premier plugin ou release sans archive) : mise à jour non testée, seule l'installation neuve l'a été"

echo "== Version publiée : installation et activation"
restore
deploy "$OLD" || skip "la version publiée ne se déploie pas (dépendances) : mise à jour non testée"
if ! console plugin:install "$KEY" -n || ! console plugin:activate "$KEY" -n; then
  skip "la version publiée ne s'installe pas sur cette version de GLPI (exigences de version ?) : mise à jour non testée"
fi

echo "== Jeu de données de la version publiée"
if [ -n "$OLD_SRC" ] && [ -d "$OLD_SRC/tests" ]; then
  docker cp "$OLD_SRC/tests/." "$C:$P/tests"
  docker exec -u root "$C" chown -R www-data:www-data "$P/tests"
fi
if [ -z "$SEED" ] && docker exec "$C" test -f "$P/tests/security/setup.php"; then
  SEED="php plugins/$KEY/tests/security/setup.php"
fi
if [ -z "$SEED" ]; then
  echo "::warning::aucun jeu de données (tests/security/setup.php absent de la version publiée, pas de seed-command) : la conservation des données ne porte que sur ce que crée l'installation"
elif ! docker exec -u www-data "$C" sh -c "cd /var/www/glpi && $SEED" > "$W/seed.out" 2>&1; then
  tail -20 "$W/seed.out"
  echo "::warning::le jeu de données de la version publiée a échoué ($SEED) : conservation des données contrôlée sur ce qui a été créé"
fi
snapshot before || { echo "::error::instantané avant mise à jour impossible"; exit 1; }

echo "== Mise à jour vers le code de la PR"
docker exec -u root "$C" sh -c "for f in $LOGS/*.log; do : > \"\$f\"; done"
deploy "$DIR" || exit 1
ERR=0
{ console plugin:install "$KEY" --force -n && console plugin:activate "$KEY" -n; } > "$W/upgrade.out" 2>&1 || ERR=1
cat "$W/upgrade.out"
[ $ERR -eq 0 ] || echo "::error::la mise à jour (plugin:install --force puis plugin:activate) a échoué"
if grep -Eiq '(PHP )?(Fatal error|Warning|Notice|Uncaught|SQL error|Exception)' "$W/upgrade.out"; then
  echo "::error::erreur ou avertissement affiché pendant la mise à jour"; ERR=1
fi
snapshot after || { echo "::error::instantané après mise à jour impossible"; exit 1; }

echo "== Schéma, données, tâches automatiques, notifications et droits"
docker exec -u www-data "$C" php /tmp/glpi-upgrade-checks.php compare /tmp/upgrade-fresh.json /tmp/upgrade-before.json /tmp/upgrade-after.json $EXC \
  | tee "$W/compare.out"
SCHEMA=$(grep -q 'COMPARE schema=1' "$W/compare.out" && echo 1 || echo 0)
DATA=$(grep -q 'COMPARE.* data=1' "$W/compare.out" && echo 1 || echo 0)
OBJECTS=$(grep -q 'COMPARE.* objects=1' "$W/compare.out" && echo 1 || echo 0)
grep -q '^COMPARE' "$W/compare.out" || { echo "::error::comparaison impossible"; SCHEMA=1; }

echo "== Pages principales (administrateur glpi)"
PAGES=0
mapfile -t URLS < <(docker exec "$C" php -r '$s = json_decode(file_get_contents("/tmp/upgrade-after.json"), true);
  foreach (array_merge(["/front/central.php", "/front/plugin.php"], $s["pages"] ?? []) as $p) { echo $p, PHP_EOL; }')
JAR=/tmp/upgrade-cookies
docker exec "$C" sh -c "rm -f $JAR; t=\$(curl -s -c $JAR -b $JAR http://localhost/ | grep -o 'name=\"_glpi_csrf_token\" value=\"[^\"]*\"' | sed 's/.*value=\"//;s/\"//'); curl -s -o /dev/null -c $JAR -b $JAR --data-urlencode login_name=glpi --data-urlencode login_password=glpi --data-urlencode _glpi_csrf_token=\$t -d noAUTO=1 http://localhost/front/login.php"
for url in "${URLS[@]}"; do
  [ -n "$url" ] || continue
  code=$(docker exec "$C" curl -s -L -o /tmp/upgrade-page.html -w '%{http_code}' -c "$JAR" -b "$JAR" "http://localhost$url")
  if [ "$code" != 200 ]; then
    echo "::error::page $url : code $code après mise à jour (200 attendu)"; PAGES=1
  elif docker exec "$C" grep -Eq 'Fatal error|Uncaught |SQL error' /tmp/upgrade-page.html; then
    echo "::error::page $url : erreur affichée dans la page après mise à jour"; PAGES=1
  else
    echo "OK  page $url (200)"
  fi
done

echo "== Journaux PHP et SQL de GLPI"
# Entrées attendues, écrites par GLPI lui-même : changement de version détecté (plugin désactivé le
# temps de la mise à jour) et message de test du journal (page d'état du système).
export BENIGN='version changed[.] It has been deactivated as its update process has to be launched|[*][*][*] Test logger$'
for log in php-errors sql-errors; do
  docker exec "$C" cat "$LOGS/$log.log" > "$W/$log.log" 2>/dev/null || true
  awk '/^\[[0-9][0-9][0-9][0-9]-/ { keep = ($0 !~ ENVIRON["BENIGN"]) } keep' "$W/$log.log" > "$W/$log.kept"
  if [ -s "$W/$log.kept" ]; then
    echo "::error::$log.log : erreurs pendant la mise à jour ou l'affichage des pages :"
    head -40 "$W/$log.kept"
    ERR=1
  fi
done

echo "RESULT errors=$ERR schema=$SCHEMA data=$DATA objects=$OBJECTS pages=$PAGES"
[ "$ERR$SCHEMA$DATA$OBJECTS$PAGES" = "00000" ]
