#!/usr/bin/env bash
# Exceptions justifiées d'un contrôle, lues dans le fichier d'exceptions du plugin
# (.glpi-security-exceptions) : une ligne par exception, « <contrôle> <identifiant> : <raison> ».
#
# Usage : security-exceptions.sh <fichier d'exceptions> <contrôle>
# Affiche un identifiant par ligne (rien si le fichier n'existe pas). Une ligne sans raison
# (« : ») est refusée : une exception doit toujours dire pourquoi elle existe.
set -u
file=${1:-} kind=${2:?contrôle attendu}
[ -n "$file" ] && [ -f "$file" ] || exit 0

status=0
while IFS= read -r line || [ -n "$line" ]; do
  line=${line%$'\r'}
  case "$line" in ''|'#'*) continue ;; esac
  set -f
  # shellcheck disable=SC2086 # découpage voulu : premier mot = contrôle
  set -- $line
  set +f
  [ "${1:-}" = "$kind" ] || continue
  case "$line" in
    *' : '?*) ;;
    *) echo "::error::exception sans raison dans $file : $line" >&2; status=1; continue ;;
  esac
  id=${line%% : *}
  id=${id#"$kind" }
  printf '%s\n' "$id"
done < "$file"
exit $status
