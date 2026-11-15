#!/usr/bin/env bash
#
# -------------------------------------------------------------------------
# TEMPLATE: Plugin Access Matrix Test Script
# This is a template that each GLPI plugin should adapt
# -------------------------------------------------------------------------
# SPDX-License-Identifier: GPL-3.0-or-later
# -------------------------------------------------------------------------
#

# Source the base script from glpi-plugin-security
# BASE_SCRIPT="$(dirname "$0")/../../glpi-plugin-security/scripts/security/access-matrix-base.sh"
# source "$BASE_SCRIPT"

# For now, copy the base functions until we have a proper dependency mechanism
# TODO: Replace with actual sourcing once the dependency structure is in place

# Plugin-specific configuration
PLUGIN_KEY="YOUR_PLUGIN_KEY"  # e.g., configurationglpiauto, assetsign, grcmanager

# Source the base functions (when glpi-plugin-security is available as a submodule or dependency)
# For now, we'll include the base script directly or copy its content

# Define plugin-specific expected HTTP codes based on role
set_expected_codes() {
  case "$ROLE" in
    none)     
      # No rights: all pages should be forbidden
      OPEN=403
      ;;
    delegate) 
      # Plugin rights only: plugin pages accessible, sensitive actions forbidden
      OPEN=200
      ;;
    admin) 
      # Super-admin: everything accessible
      OPEN=200
      ;;
  esac
}

# Define plugin-specific endpoints to test
run_access_tests() {
  # Example: Test front pages
  # expect "front/wizard.php" $OPEN "$(code "$PLUGIN_FRONT/wizard.php")"
  
  # Example: Test ajax endpoints
  # expect "ajax/endpoint.php" $OPEN "$(code "$PLUGIN_AJAX/endpoint.php")"
  
  # Each plugin should define its own endpoints here
  echo "Error: Plugin-specific tests not defined. Please implement run_access_tests() in your plugin's access-matrix.sh"
  exit 1
}

# Main execution
# Parse arguments: URL, role, login, password, setup.php JSON
BASE=$1
ROLE=$2
LOGIN=$3
PASSWORD=$4
IDS=$5

# Initialize base functionality
init_access_matrix() {
  set -u
  JAR=$(mktemp)
  FAIL=0
  
  id() { jq -r ".\$1" <<< "$IDS"; }
  code() { curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" "$@"; }
  page() { curl -s -c "$JAR" -b "$JAR" "$@"; }
  csrf() { curl -s -c "$JAR" -b "$JAR" "$BASE/front/central.php" | grep -o 'glpi:csrf_token" content="[^"]*"' | sed 's/.*content="//;s/"//'; }
  expect() { 
    if [ "$2" = "$3" ]; then 
      echo "OK  $1 ($3)"; 
    else 
      echo "::error::$ROLE - $1 : attendu $2, obtenu $3"; 
      FAIL=1; 
    fi
  }
  shows() { 
    local got=non
    grep -qF "$2" <<< "$4" && got=oui
    expect "$1" "$3" "$got"
  }
}

# Login and run tests
init_access_matrix

for try in 1 2 3 4 5; do
  rm -f "$JAR"
  token=$(curl -s -c "$JAR" -b "$JAR" "$BASE/" | grep -o 'name="_glpi_csrf_token" value="[^"]*"' | sed 's/.*value="//;s/"//')
  curl -s -o /dev/null -c "$JAR" -b "$JAR" \
    --data-urlencode "login_name=$LOGIN" \
    --data-urlencode "login_password=$PASSWORD" \
    --data-urlencode "_glpi_csrf_token=$token" \
    -d noAUTO=1 "$BASE/front/login.php"
  [ "$(code "$BASE/front/central.php")" = 200 ] && break
  [ "$try" = 5 ] && { echo "::error::connexion impossible pour $LOGIN"; rm -f "$JAR"; exit 1; }
  sleep 3
done

PLUGIN_FRONT="$BASE/plugins/$PLUGIN_KEY/front"
PLUGIN_AJAX="$BASE/plugins/$PLUGIN_KEY/ajax"

set_expected_codes
run_access_tests

rm -f "$JAR"
exit $FAIL
