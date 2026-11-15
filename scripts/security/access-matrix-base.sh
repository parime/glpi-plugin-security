#!/usr/bin/env bash
#
# -------------------------------------------------------------------------
# GLPI Plugin Security - Access Matrix Base Script
# Copyright (C) 2024-2026 Vincent GUILLOTTE
# https://github.com/parime/glpi-plugin-security
# -------------------------------------------------------------------------
# SPDX-License-Identifier: GPL-3.0-or-later
# -------------------------------------------------------------------------
#

# Base script for access matrix testing.
# Each plugin should source this script and define its PLUGIN_KEY and endpoints.
#
# Usage: This script should be sourced by plugin-specific access-matrix.sh scripts
#

# Initialize common variables and functions
init_access_matrix() {
  local PLUGIN_KEY=$1
  local BASE=$2
  local ROLE=$3
  local LOGIN=$4
  local PASSWORD=$5
  local IDS=$6
  
  set -u
  JAR=$(mktemp)
  FAIL=0
  
  # Extract plugin ID from setup.php JSON
  id() { jq -r ".\$1" <<< "$IDS"; }
  
  # HTTP utilities
  code() { curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" "$@"; }
  page() { curl -s -c "$JAR" -b "$JAR" "$@"; }
  csrf() { curl -s -c "$JAR" -b "$JAR" "$BASE/front/central.php" | grep -o 'glpi:csrf_token" content="[^"]*"' | sed 's/.*content="//;s/"//'; }
  
  # Test utilities
  expect() { # libelle, attendu, obtenu
    if [ "$2" = "$3" ]; then 
      echo "OK  $1 ($3)"; 
    else 
      echo "::error::$ROLE - $1 : attendu $2, obtenu $3"; 
      FAIL=1; 
    fi
  }
  
  shows() { # libelle, texte, attendu (oui|non), contenu
    local got=non
    grep -qF "$2" <<< "$4" && got=oui
    expect "$1" "$3" "$got"
  }
  
  # Login to GLPI
  login_to_glpi() {
    for try in 1 2 3 4 5; do
      rm -f "$JAR"
      token=$(curl -s -c "$JAR" -b "$JAR" "$BASE/" | grep -o 'name="_glpi_csrf_token" value="[^"]*"' | sed 's/.*value="//;s/"//')
      curl -s -o /dev/null -c "$JAR" -b "$JAR" \
        --data-urlencode "login_name=$LOGIN" \
        --data-urlencode "login_password=$PASSWORD" \
        --data-urlencode "_glpi_csrf_token=$token" \
        -d noAUTO=1 "$BASE/front/login.php"
      [ "$(code "$BASE/front/central.php")" = 200 ] && break
      [ "$try" = 5 ] && { echo "::error::connexion impossible pour $LOGIN"; exit 1; }
      sleep 3
    done
    
    # Set plugin paths
    PLUGIN_FRONT="$BASE/plugins/$PLUGIN_KEY/front"
    PLUGIN_AJAX="$BASE/plugins/$PLUGIN_KEY/ajax"
  }
  
  # Define expected HTTP codes based on role
  # This should be overridden by plugin-specific scripts
  set_expected_codes() {
    case "$ROLE" in
      none)     OPEN=403; AUDIT=403; AUDIT_FIX=403; IMPORT=403; EXPORT=403 ;;
      delegate) OPEN=200; AUDIT=403; AUDIT_FIX=403; IMPORT=200; EXPORT=200 ;;
      *)        OPEN=200; AUDIT=200; AUDIT_FIX=302; IMPORT=200; EXPORT=200 ;;
    esac
  }
}

# This function should be called by plugin-specific scripts
# after defining their endpoints and role expectations
run_access_tests() {
  # Run all tests defined by the plugin
  # This is a placeholder that plugin-specific scripts will implement
  :
}

# Cleanup
cleanup() {
  rm -f "$JAR"
  exit $FAIL
}

# Main entry point - should be called by plugin scripts
main_access_matrix() {
  init_access_matrix "$@"
  login_to_glpi
  set_expected_codes
  run_access_tests
  cleanup
}
