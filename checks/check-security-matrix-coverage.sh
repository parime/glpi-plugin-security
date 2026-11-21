#!/bin/sh
# Chaque page front/*.php et ajax/*.php doit etre exercee par le test de controle d'acces de bout
# en bout (son chemin apparait dans tests/security/access-matrix.sh), ou listee avec sa
# justification dans tests/security/not-covered.txt. Une nouvelle page sans test d'acces fait donc
# echouer la CI au lieu de passer inapercue.
#
# Fragments : une page peut aussi etre exercee par un fichier du dossier voisin de la matrice,
# du meme nom suivi de .d (tests/security/access-matrix.d/*.sh, charges par la matrice elle-meme) :
# chaque fonctionnalite ajoute ainsi son propre fichier au lieu d'allonger la matrice (moins de
# conflits entre branches paralleles).
#
# Un plugin qui n'a pas encore de matrice (nouveau plugin cree depuis le gabarit) recoit un
# avertissement, pas une erreur : la matrice se cree avec scripts/security/TEMPLATE-access-matrix.sh.
#
# Usage : check-security-matrix-coverage.sh [dossier du plugin] [fichier de la matrice]
#   (par defaut : le dossier courant et tests/security/access-matrix.sh). Un plugin dont la matrice
#   est un test PHPUnit (tests/Integration/AccessControlTest.php, par exemple) passe son chemin.
cd "${1:-.}" || exit 2
MATRIX=${2:-tests/security/access-matrix.sh}
EXEMPT=tests/security/not-covered.txt
if [ ! -f "$MATRIX" ]; then
  echo "::warning::$MATRIX absent : aucune page n'est couverte par un test de controle d'acces (modele : scripts/security/TEMPLATE-access-matrix.sh de glpi-plugin-security)"
  exit 0
fi
FRAGMENTS="${MATRIX%.*}.d"
status=0
for f in front/*.php ajax/*.php; do
  [ -f "$f" ] || continue
  if grep -qF "$f" "$MATRIX"; then
    continue
  fi
  if [ -d "$FRAGMENTS" ] && grep -rqF "$f" "$FRAGMENTS"; then
    continue
  fi
  if [ -f "$EXEMPT" ] && grep -qE "^${f}[[:space:]]*:[[:space:]]*[^[:space:]]" "$EXEMPT"; then
    continue
  fi
  echo "::error file=$f::page absente de $MATRIX et de $FRAGMENTS/ (ni testee, ni justifiee dans $EXEMPT)"
  status=1
done
exit $status
