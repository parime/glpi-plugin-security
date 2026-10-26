#!/usr/bin/env bash
# Démarre MariaDB et un GLPI officiel auto-installé (conteneurs « glpisec-db » et « glpisec »).
# Usage : start-glpi.sh [image GLPI]   (défaut : glpi/glpi:11.0.11)
set -euo pipefail
IMAGE=${1:-glpi/glpi:11.0.11}

docker network create glpisec >/dev/null
docker run -d --name glpisec-db --network glpisec \
  -e MARIADB_ROOT_PASSWORD=glpi -e MARIADB_DATABASE=glpidb -e MARIADB_USER=glpi -e MARIADB_PASSWORD=glpi \
  mariadb:11 >/dev/null
for _ in $(seq 1 30); do
  docker exec glpisec-db mariadb-admin ping -h 127.0.0.1 -uroot -pglpi --silent && break
  sleep 2
done

docker run -d --name glpisec --network glpisec \
  -e TIMEZONE=Europe/Paris -e GLPI_DB_HOST=glpisec-db -e GLPI_DB_PORT=3306 \
  -e GLPI_DB_NAME=glpidb -e GLPI_DB_USER=glpi -e GLPI_DB_PASSWORD=glpi \
  "$IMAGE" >/dev/null
for _ in $(seq 1 60); do
  docker exec glpisec test -f /var/glpi/config/config_db.php && break
  sleep 5
done
docker exec glpisec test -f /var/glpi/config/config_db.php || {
  echo "::error::GLPI n'a pas terminé son auto-installation"; docker logs glpisec --tail 100; exit 1;
}
# database:update absorbe la fin de l'auto-installation (peut échouer tant qu'elle n'est pas finie).
for _ in $(seq 1 12); do
  docker exec glpisec php bin/console database:update --force --no-interaction >/dev/null 2>&1 && break
  sleep 5
done
docker exec glpisec sh -c 'cd /tmp && php -r "copy(\"https://getcomposer.org/download/latest-stable/composer.phar\", \"composer.phar\");"'
echo "GLPI prêt ($IMAGE)"
