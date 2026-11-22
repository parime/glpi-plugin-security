#!/usr/bin/env bash
# Toutes les lignes d'exceptions justifiées d'un plugin : le fichier d'exceptions
# (.glpi-security-exceptions), puis chaque fichier du dossier de fragments du même nom suivi de « .d »
# (.glpi-security-exceptions.d/*, par ordre alphabétique, fichiers cachés ignorés). Même syntaxe
# partout, une ligne par exception. Les fragments évitent que deux branches qui ajoutent chacune
# une exception se mettent en conflit sur le même fichier.
#
# Usage : exceptions-cat.sh <fichier d'exceptions>
# Affiche les lignes (rien si ni le fichier ni le dossier n'existent), chaque fichier terminé par un
# saut de ligne même si sa dernière ligne n'en a pas.
set -u
file=${1:-}
[ -n "$file" ] || exit 0
for f in "$file" "$file.d"/*; do
  [ -f "$f" ] || continue
  cat "$f"
  [ -z "$(tail -c1 "$f")" ] || echo
done
exit 0
