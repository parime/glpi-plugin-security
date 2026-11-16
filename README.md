# glpi-plugin-security

Contrôles de sécurité communs à tous les plugins GLPI de parime : les mêmes vérifications, à chaque
push et pull request, sur chaque dépôt de plugin actuel et à venir.

Ils encodent les familles de défauts relevées par les revues de sécurité de la marketplace GLPI
(assetsign-glpi, Configuration-glpi-auto, glpi-iso27001-management). Chaque contrôle est prouvé par
l'auto-test du dépôt : il doit détecter le défaut volontaire du plugin de test `secbad` et rien sur
sa version correcte `secgood`.

## Brancher un plugin (une fois par dépôt)

Créer `.github/workflows/security.yml` dans le dépôt du plugin :

```yaml
name: Sécurité
on: [push, pull_request]
jobs:
  security:
    uses: parime/glpi-plugin-security/.github/workflows/plugin-security.yml@v1.8.0
    with:
      plugin-key: monplugin   # nom du dossier dans plugins/ (plugin_version_monplugin)
      # Facultatif : jeu de données créé avant la désinstallation, pour que le contrôle des
      # restes porte sur de vraies données (dossier de GLPI, utilisateur www-data).
      # seed-command: php plugins/monplugin/tests/security/setup.php
```

Puis rendre le job « security » obligatoire dans la protection de la branche principale. Un nouveau
plugin créé depuis [glpi-plugin-template](https://github.com/parime/glpi-plugin-template) l'a déjà.
L'**audit hebdomadaire** de ce dépôt ouvre une issue pour tout dépôt de plugin qui ne l'appelle pas,
ou qui l'appelle dans une version antérieure à la dernière étiquette (branche par défaut).

## Ce qui est vérifié

### Règles Semgrep (`semgrep/glpi-plugin-security.yml`)

| Règle | Défaut |
|---|---|
| `glpi-access-decision-from-global-right` | Accès décidé sur le seul droit global, sans l'entité de l'enregistrement |
| `glpi-request-entity-without-access-check`, `glpi-request-entity-in-array` | `entities_id` lu dans la requête sans `Session::haveAccessToEntity()` |
| `glpi-twig-raw-output` | `\|raw` sur une donnée non purifiée |
| `glpi-csv-cell-from-user-input` | Injection de formule dans un export CSV |
| `glpi-generated-document-recursive` | Document généré créé récursif (lisible des sous-entités sans droit sur sa source) |
| `glpi-notification-target-core-id` | Cible de notification du plugin < 1000 : confondue avec une cible du cœur |
| `glpi-or-die` | `or die()` à l'installation |
| `glpi-session-message-unescaped-input` | Saisie non échappée dans un message de session (affiché avec `\|raw`) |
| `glpi-unconditional-vendor-autoload` | `vendor/autoload.php` chargé sans condition : erreur fatale sur chaque page sans `vendor/` |
| `glpi-visibility-from-canviewitem-only` (avertissement) | `canViewItem()` seul pour décider d'afficher un élément |

### Traductions (`checks/check-translations.php`)

Une traduction de `locales/*.po` ne doit rien ajouter d'actif à son texte source : ni balise HTML,
ni URL, ni JavaScript (`javascript:`, `onerror=`…), ni entité HTML, ni balise de notification
`##…##`. Les chaînes traduites sont souvent affichées sans échappement (messages de session, aides,
notifications) : une traduction fournie par un contributeur, une plateforme ou un outil
automatique ne peut ainsi pas injecter de contenu.

### Typographie (`checks/check-em-dash.sh`)

Aucun tiret cadratin dans les fichiers du plugin suivis par git (hors `vendor`, `node_modules` et
`.mo`) : chaînes affichées, traductions, documentation. Le remplacer par « : », une virgule, des
parenthèses, ou « - » pour un séparateur. Un nom historique à retrouver lors d'une migration
s'écrit avec la séquence d'échappement `"\u{2014}"`.

### Archive publiée (`checks/check-archive.sh`)

L'archive de release est construite avec `git archive` : elle ne doit contenir aucun fichier de
développement. Le contrôle liste ce que `git archive HEAD` produit et échoue sur l'outillage de test,
de CI ou d'analyse à la racine du dépôt : `tests/`, `.github/`, `.semgrep/`, `phpstan*`,
`.phpstan-bootstrap.php`, `phpunit*`, `.php-cs-fixer*`, `phpcs.xml*`, `.editorconfig`,
`.yamllint*`, `.gitignore`, `.gitattributes`, `.glpi-security-exceptions`, `docker-compose*`,
`package.json`… Un fichier en trop s'exclut avec `export-ignore` dans `.gitattributes`.

### Workflows GitHub Actions (`checks/check-workflows.sh`)

Chaque fichier de `.github/workflows/` doit être un YAML valide à la syntaxe GitHub Actions correcte
(actionlint 1.7.12, téléchargé et vérifié par son empreinte SHA-256). Un workflow invalide ne démarre
plus du tout : la CI du plugin se tait au lieu d'échouer, et les PR paraissent vertes. Cas réel : un
« : » ajouté dans un nom d'étape non cité a coupé la CI d'assetsign pendant deux jours (assetsign
#295) ; un nom qui contient « : » s'écrit entre guillemets. Les remarques de style des scripts shell
(shellcheck) ne bloquent pas.

### Contrôles dans un vrai GLPI (`checks/`)

Le plugin est installé dans le GLPI officiel (11.0.11 par défaut), puis :

- **Onglets** : chaque onglet que le plugin ajoute à un objet du cœur est demandé comme le fait
  `ajax/common.tabs.php` (qui ne consulte jamais `getTabNameForItem()`), par un profil qui a tous les
  droits du cœur mais aucun droit du plugin. Le contenu doit être refusé.
- **Droits attribuables** : chaque droit du plugin (`plugin_<clé>…`, `rule_<clé>…`) figure dans la
  matrice d'un onglet du plugin sur les profils (`Profile::displayRightsChoiceMatrix()`). Sinon seul
  le profil servi à l'installation l'a, et un administrateur ne peut le donner qu'en écrivant en base.
- **Notifications** : aucune constante de cible du plugin sous 1000, et chaque cible déclarée est une
  constante du cœur ou de la classe (jamais un identifiant calculé).
- **Désinstallation** : après le jeu de données facultatif puis la désinstallation, plus aucune ligne
  des tables du cœur ne porte un type d'objet, une règle, un droit ou une configuration du plugin.
- **Menus** (avertissement) : les entrées que le plugin ajoute aux menus de GLPI (hook `menu_toadd`)
  sont listées ; au-delà d'une, une annotation rappelle la charte (une seule entrée de menu d'usage,
  réglages dans la page du plugin). Sans effet sur le résultat du job. Un outil métier complet qui
  déclare `menus-secteur-metier : <justification>` dans son fichier d'exceptions voit son secteur
  de menu propre compté comme une seule entrée (charte, section 9).
  Une entrée justifiée dans un secteur natif (par exemple un objet ITIL placé dans Assistance) se
  déclare par `menus-entree-native <secteur> > <classe> : <justification>` et n'est plus comptée.

### Points d'entrée, matrice d'accès et exigences marketplace (`checks/`)

Ces trois contrôles étaient auparavant copiés dans `.github/scripts/` de chaque plugin (issue #11).
Chaque plugin garde ses fichiers de réglage dans son dépôt.

| Contrôle | Défaut détecté | Réglage dans le plugin |
|---|---|---|
| `check-entrypoints-auth.sh` | Page `front/` ou `ajax/` sans contrôle d'autorisation (`Session::checkRight`, `checkLoginUser`, `->check()`…) | Exception justifiée dans `.github/security-entrypoints-allowlist.txt` (`front/x.php : raison`) |
| `check-security-matrix-coverage.sh` | Page absente du test de contrôle d'accès `tests/security/access-matrix.sh` | Exception justifiée dans `tests/security/not-covered.txt`. Sans matrice (nouveau plugin), simple avertissement : modèle dans `scripts/security/`. Une matrice écrite en PHPUnit se déclare par l'entrée `access-matrix-file` (par exemple `tests/Integration/AccessControlTest.php`) |
| `check-marketplace-readiness.php` | SQL brut hors installation ; plus de HTML écrit en PHP que la référence | Exception dans `.github/marketplace-allowlist.txt` ; référence `.github/twig-baseline.txt`, à régénérer avec `--update-baseline` quand elle baisse |

Lancement local, depuis le dossier du plugin :
`php <glpi-plugin-security>/checks/check-marketplace-readiness.php . [--update-baseline]`.

### Charte commune (`checks/check-charter.sh`, avertissements)

La part mécanique de la [charte commune des plugins](https://github.com/parime/glpi-plugin-template/blob/main/CHARTE.md) :

- `LICENSE` (GNU GPL v3), `NOTICE`, `CITATION.cff`, `AGENTS.md`, `SECURITY.md` présents ;
- licence `GPL-3.0-or-later` déclarée dans `setup.php`, `composer.json` et `CITATION.cff` ;
- en-tête de licence dans les 30 premières lignes de chaque fichier PHP et Twig suivi par git (hors
  `vendor`, `node_modules`, `tests` et fichiers cachés comme `.github/`) : le bloc de `tools/HEADER`
  ou la ligne `SPDX-License-Identifier: GPL-3.0-or-later` ;
- `README.md` (français) avec sa section « Réutilisation et citation », `README.en.md` avec
  « Reuse and citation », `CHANGELOG.md` avec `## [Unreleased]` (Keep a Changelog) ;
- lien vers `CHARTE.md` dans `AGENTS.md` et `README.md` ;
- les 18 langues communes dans `locales/` : de_DE, en_GB, en_US, es_419, es_AR, es_CL, es_CO, es_EC,
  es_ES, es_MX, es_VE, fr_BE, fr_CA, fr_FR, it_IT, pl_PL, pt_BR, pt_PT.

**Mode avertissement (v1.7.x et v1.8.x)** : les écarts sont annotés (`::warning::`) sans faire échouer le job,
le temps que chaque plugin corrige les siens (issues ouvertes dans chaque dépôt). **Passage en
bloquant** : un plugin conforme peut l'activer dès maintenant avec `charter-mode: error` dans son
`security.yml`. Quand les quatre plugins sont conformes, une version mineure suivante passe
le défaut de `charter-mode` à `error` et le contrôle des menus en erreur (avec exception justifiée
possible dans `.glpi-security-exceptions`), annoncée dans ses notes de version.

## Exceptions justifiées

Un faux positif se déclare dans `.glpi-security-exceptions`, à la racine du dépôt du plugin, une ligne
par exception, avec l'identifiant affiché par le contrôle et la raison :

```
tab-content Computer/PluginMonpluginPassport$1 : contenu public par conception (droits du cœur)
```

Pour Semgrep, `// nosemgrep: <id de la règle>` sur la ligne, avec un commentaire qui justifie.

## Faire évoluer les contrôles

1. Ajouter la règle ou le contrôle, son cas dans `semgrep/glpi-plugin-security.php` (`ruleid:` /
   `ok:`) ou le défaut dans `tests/fixtures/secbad` (et sa correction dans `secgood`).
2. L'auto-test doit passer.
3. Publier une nouvelle étiquette `vX.Y.Z` (jamais déplacer une étiquette publiée), avec
   `default: vX.Y.Z` pour `security-ref` dans `plugin-security.yml`, dans le commit étiqueté.
   L'auto-test le vérifie sur chaque étiquette : sans cela, les plugins qui appellent `@vX.Y.Z`
   exécutent les scripts de la version précédente (cas de v1.7.1 et v1.8.1).
4. Dans chaque plugin, une PR passe `@vX.Y.Z` dans `.github/workflows/security.yml` : une règle
   plus stricte arrive avec ses corrections, jamais en cassant la CI sans prévenir.

Lancement local : `bash checks/start-glpi.sh`, puis `bash checks/check-plugin.sh <dossier> <clé>`.
