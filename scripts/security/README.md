# Scripts de test de sécurité mutualisés

Ce répertoire contient des scripts de base pour les tests de sécurité qui peuvent être partagés entre tous les plugins GLPI.

## Objectif

Mutualiser les scripts de test de sécurité copiés dans chaque plugin (issue [#11](../../../issues/11)).

## Scripts disponibles

### `access-matrix-base.sh`

Script de base pour les tests de matrice d'accès. Contient les fonctions communes pour :

- Connexion à GLPI
- Exécution de requêtes HTTP avec gestion des cookies
- Extraction du token CSRF
- Vérification des codes HTTP
- Recherche de contenu dans les pages

### `TEMPLATE-access-matrix.sh`

Template que chaque plugin doit adapter pour créer son propre script `access-matrix.sh`.

## Comment l'utiliser

### Option 1: Copier les fonctions de base (solution temporaire)

1. Dans votre plugin, créez un fichier `tests/security/access-matrix.sh`
2. Copiez le contenu de `TEMPLATE-access-matrix.sh`
3. Définissez `PLUGIN_KEY` avec le nom de votre plugin
4. Implémentez la fonction `run_access_tests()` avec vos endpoints spécifiques
5. Implémentez la fonction `set_expected_codes()` avec vos codes HTTP attendus par rôle

### Option 2: Utiliser glpi-plugin-security comme dépendance (à venir)

À l'avenir, lorsque glpi-plugin-security sera disponible comme dépendance (submodule ou package Composer), vous pourrez :

```bash
# Dans votre plugin
source ../../glpi-plugin-security/scripts/security/access-matrix-base.sh
```

## Fonctions de base disponibles

### `id() field`
Extrait une valeur du JSON de setup.php

### `code() url`
Récupère le code HTTP d'une URL

### `page() url`
Récupère le contenu d'une URL

### `csrf()`
Extrait le token CSRF de GLPI

### `expect() label expected obtained`
Vérifie si la valeur obtenue correspond à la valeur attendue

### `shows() label text expected content`
Vérifie si un texte est présent dans le contenu

## Exemple pour un plugin

```bash
#!/usr/bin/env bash
# tests/security/access-matrix.sh

PLUGIN_KEY="configurationglpiauto"

# Source the base script (when available)
# source "$BASE_SCRIPT"

set_expected_codes() {
  case "$ROLE" in
    none)     OPEN=403; AUDIT=403; ;;
    delegate) OPEN=200; AUDIT=403; ;;
    *)        OPEN=200; AUDIT=200; ;;
  esac
}

run_access_tests() {
  expect "front/wizard.php" $OPEN "$(code "$PLUGIN_FRONT/wizard.php")"
  expect "front/profile.php" $OPEN "$(code "$PLUGIN_FRONT/profile.php")"
  # ... autres tests spécifiques au plugin
}

# Inclure le code du template ici ou sourcer le script de base
# ...
```

## Contribuer

Pour améliorer les scripts mutualisés :

1. Identifiez les fonctionnalités communes dans vos scripts de test
2. Proposez des améliorations à `access-matrix-base.sh`
3. Mettez à jour ce README avec de nouveaux exemples

## Voir aussi

- [Issue #11: Mutualiser les scripts de CI copiés dans chaque plugin](../../../issues/11)
- [Workflow de sécurité partagé](../../.github/workflows/plugin-security.yml)
