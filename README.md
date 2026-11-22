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
    uses: parime/glpi-plugin-security/.github/workflows/plugin-security.yml@v1.10.3
    with:
      plugin-key: monplugin   # nom du dossier dans plugins/ (plugin_version_monplugin)
      # Facultatif : jeu de données créé avant la désinstallation, pour que le contrôle des
      # restes porte sur de vraies données (dossier de GLPI, utilisateur www-data).
      # seed-command: php plugins/monplugin/tests/security/setup.php
      # Test de mise à jour depuis la dernière release (GLPI 11 et 12), actif par défaut :
      # upgrade-test: false pour le désactiver, upgrade-seed-command pour un autre jeu de données.
      # Parcours de toutes les pages (GLPI 11 et 12), actif par défaut : pages-test: false pour le
      # désactiver, pages-mode: warning le temps d'une issue de correction.
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

### Couleurs des badges (`checks/check-subtle-colors.php`)

GLPI et Tabler ne définissent les classes Bootstrap 5.3 `bg-X-subtle`, `text-X-emphasis` et
`border-X-subtle` que pour les couleurs de thème : primary, secondary, success, info, warning,
danger, light, dark. Avec une couleur nommée de Tabler (orange, blue, green, purple…), le badge
s'affiche sans fond (charte, section 8). Le contrôle échoue, avec le fichier, la ligne et la
correction, quand un template Twig, un fichier PHP ou JS du plugin (hors `vendor`, `node_modules`,
`tests`, `lib`, `build`, `dist`) utilise une telle classe qui n'est ni de thème ni définie dans une
feuille CSS du plugin :

- écrite telle quelle (`bg-orange-subtle`) ;
- construite dans Twig (`bg-{{ ok ? 'green' : 'red' }}-subtle` : les couleurs entre apostrophes de
  l'expression, hors opérandes d'une comparaison) ;
- fournie par le PHP (`'color' => 'orange'`, `'tone' => 'red'`, `'badge_color' => …`).

Une couleur traduite par une table Twig vers une couleur définie (`{ indigo: 'info' }`, comme la
macro `_tone.html.twig` de Docker Inventory) est considérée comme traitée. Correction : une couleur
de thème, ou la [feuille commune du modèle](https://github.com/parime/glpi-plugin-template/blob/main/public/css/monplugin.css)
(`public/css/<clé>.css`, chargée par `$PLUGIN_HOOKS[Hooks::ADD_CSS]`), qui couvre les douze
couleurs nommées en thème clair et sombre. Faux positif : `subtle-color <classe ou couleur> : raison`
dans `.glpi-security-exceptions`. Même logique que `tests/Unit/SubtleColorClassesTest.php` du
modèle, sans PHPUnit.

Lancement local, depuis le dossier du plugin : `php <glpi-plugin-security>/checks/check-subtle-colors.php .`

### Ergonomie : réglages, menus, fil d'Ariane (`checks/check-navigation.php`)

Règle « Où placer un écran » (charte, section 9) : les réglages dans la page de configuration du
plugin (Configuration > Plugins, en onglets), l'exploitation dans le menu GLPI où un utilisateur la
cherche (Parc, Assistance, Gestion, Outils ou le menu propre du plugin), et rien de l'exploitation
dans le paramétrage. Contrôle statique (sans GLPI), job `navigation` du workflow. Chaque écart
indique le fichier, la ligne, le problème, l'emplacement attendu, et son identifiant entre crochets :

| Identifiant | Écart |
|---|---|
| `config-action <fichier>` | Bouton « … maintenant » (récupérer, envoyer, importer, mettre à jour, télécharger, lire, synchroniser ; français ou anglais) dans la page de configuration. « Tester la connexion » reste un réglage |
| `config-tableau <fichier>` | Tableau de résultats ou de registre : `<table>` qui parcourt des résultats, journaux, imports, alertes, rapports…, ou `components/datatable.html.twig` |
| `config-lien <fichier> > <cible>` | Lien vers une page `front/` d'exploitation : adresse écrite, `getSearchURL()`, `getFormURL()`, `get…URL()`, `Menu::url('<page>')` d'une classe du plugin. Ne comptent pas : classes dont le nom contient `Config`, intitulés (`CommonDropdown`), classes du cœur (droits, notifications), pages rangées dans Configuration hors entrée de menu, traitements de formulaire sans affichage |
| `menus-secteur <secteur>` | Plus d'une entrée `menu_toadd` dans un même secteur GLPI |
| `menus-entree-native config > <classe>` | Entrée de menu dans le secteur Configuration : les réglages passent par `config_page` |
| `page-entete <front/page.php>` | Page qui affiche un écran GLPI (`Html::footer()`, `->display()`, `Search::show()`…) sans `Html::header()` ni en-tête équivalent. Ne comptent pas : téléchargements et pages autonomes (`header('Content-Type: …')`), pages du cœur incluses |
| `entete-secteur <front/page.php>` | `Html::header(…, <secteur>, <classe>)` incohérent avec le menu : secteur où le plugin n'a pas d'entrée (hors `config`), ou classe d'une entrée de menu déclarée dans un autre secteur (fil d'Ariane et menu actif faux) |
| `rubrique-onglets <déclaration>` | Plus de 7 sous-pages dans une rubrique (issue #34) : fonctions `sections()`, `screens()`, `tabs()`, `subPages()` qui renvoient un tableau (rubrique => liste de sous-pages, la rubrique comptant pour un onglet, ou liste des écrans d'une barre), clé `'pages' => [...]` |
| `module-inactif <Classe>::<méthode>` | Classe d'un module activable (`isEnabled()`, `isModuleEnabled()`, `isFrameworkEnabled()`, `isFeatureEnabled()`) dont `getTabNameForItem()` ou `getMenuContent()` ne consulte pas l'activation : onglet ou entrée affichés pour un module désactivé |
| `config-version <page>` | Page de configuration sans l'encadré « Version du plugin » (composant `templates/_plugin_version.html.twig` de [glpi-plugin-template](https://github.com/parime/glpi-plugin-template), ou classe `PluginVersion…`) |

Les trois derniers suivent leur propre mode, `tabs-mode` (comme les barres d'onglets du parcours des
pages) : un plugin peut garder le reste bloquant pendant la refonte de ses rubriques.

Fichiers lus pour la page de configuration : le fichier du hook `config_page`, les pages `front/`
rangées dans Configuration > Plugins (`Html::header(…, 'config', 'plugin')` ou `Plugin::class`), les
templates qu'elles affichent et incluent, les blocs de réglages découverts par fichier
(`src/Config/**/*.php` et `templates/config/**/*.twig`, comme les blocs de GRC), les classes du plugin dont le nom contient `Config`, les
objets qu'une sous-page de réglages crée (`new Connection()`) et le corps des méthodes `config*()`
appelées (`EndOfLife::configData()`). Les exceptions de menu sont celles du contrôle dans un vrai
GLPI : `menus-secteur-metier` (le secteur propre d'un outil métier complet compte pour une entrée)
et `menus-entree-native <secteur> > <classe>` (entrée justifiée, non comptée). Tout autre écart
justifié : ligne `<identifiant> : <raison>` dans `.glpi-security-exceptions`.

**Mode avertissement, le temps de l'issue de navigation.** Un plugin qui a des écarts connus, listés
dans son issue de navigation ouverte, passe le contrôle en avertissement dans son `security.yml` :

```yaml
    with:
      plugin-key: monplugin
      navigation-mode: warning   # jusqu'à la fermeture de parime/monplugin#NN (navigation)
      tabs-mode: warning         # rubriques, modules, encadré de version et barres d'onglets (#34)
```

Les écarts deviennent des annotations `::warning::` et le job ne bloque pas. À la fermeture de
l'issue, la PR qui la ferme retire la ligne : le contrôle redevient bloquant (`error`, par défaut).
Écarts relevés à la création du contrôle, sur les branches principales :

| Plugin | Issue de navigation | Écarts |
|---|---|---|
| GRC Manager (dev) | glpi-iso27001-management#1035 | 15 : en-tête de la configuration et de `riskscale.php` dans Outils, 3 boutons « … now », 10 liens vers des registres |
| Configuration GLPI Auto | Configuration-glpi-auto#495 | 5 : entrée dans Configuration, page de configuration = assistant (liens, tableau d'audit, envoi) |
| AssetSign | assetsign-glpi#392 | 3 liens « Voir les… » de la configuration |
| Inventaire Docker | glpi-docker-inventory#118 | 13 : 7 lectures « … maintenant », 6 liens vers les écrans d'usage |
| FinOps (dev) | glpi-finops#37 | 2 liens (coûts, inventaire Sangfor) |

Lancement local, depuis le dossier du plugin : `php <glpi-plugin-security>/checks/check-navigation.php . [navigation-mode] [tabs-mode]`

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

### Libellés traduits dans les tests (`checks/check-translated-assertions.php`)

La CI des plugins tourne en anglais, l'instance de développement en français : un test qui attend
un libellé d'interface passe dans une langue et échoue dans l'autre (au moins cinq cas, dont
GRC PR #256 avec un titre construit par `sprintf(__())`). Le contrôle lit les fichiers PHP de
`tests/` et signale chaque `assertSame`, `assertEquals`, `assertNotSame`, `assertNotEquals`,
`assertStringContainsString`, `assertStringNotContainsString`, `assertStringStartsWith`,
`assertStringEndsWith` ou `assertContains` :

- dont un argument appelle une fonction de traduction (`__()`, `_n()`, `_x()`, `_nx()`, `__s()`,
  `_sx()`, `_sn()`), y compris dans `sprintf(__(...))` ;
- ou dont la valeur attendue (le premier argument, ou un élément d'un tableau écrit tel quel) est
  un texte d'interface du plugin qui change avec la langue : msgid de `locales/` (avec ses `%s` et
  `%d`), ou sa traduction française (`fr_*`) ou anglaise (`en_*`). Un texte identique dans les deux
  langues n'est pas retenu, ni un mot anglais écrit comme un identifiant (`critical`, `Computer`),
  ni une chaîne passée à une fonction (`strtotime('+15 days')`) ou concaténée à une variable.

Correction : comparer un identifiant, une clé, un code ou un nombre plutôt qu'un libellé ; quand
c'est la traduction elle-même qui est testée, forcer la langue dans le test, la restaurer, et
déclarer l'exception. Faux positif ou cas voulu, une ligne dans `.glpi-security-exceptions`, pour
une méthode de test ou pour tout un fichier :

```
translated-assertion tests/Integration/RiskTest.php::testFrenchTitle : langue forcée en fr_FR, c'est la traduction qui est testée
translated-assertion tests/Unit/CatalogFrTest.php : catalogue écrit en français quelle que soit la langue
```

En **avertissement par défaut** (annotations sans échec), le temps de corriger les plugins : au
22/11/2026, 69 cas dans GRC Manager, 12 dans Configuration GLPI Auto, 97 dans AssetSign, aucun dans
Docker Inventory et FinOps. Un plugin corrigé le rend bloquant avec
`translated-assertions-mode: error`. Lancement local, depuis le dossier du plugin :
`php <glpi-plugin-security>/checks/check-translated-assertions.php . [error|warning]`.

### Tests d'intégration en français (`checks/run-integration-tests.sh`)

En plus des tests de la CI du plugin (en anglais), le job « Tests d'intégration en fr_FR » relance
sa suite d'intégration dans un GLPI dont la langue est `fr_FR` pour l'instance (configuration
`language`, suivie par toute session sans langue propre) **et** pour chaque utilisateur existant
(glpi, tech, normal, post-only), comme sur l'instance de développement. Étapes : `.mo` compilés
s'ils ne sont pas versionnés (`tools/build_locales.py` s'il existe, sinon `msgfmt`), dépendances
de production, installation et activation du plugin, langue, dépendances de développement, puis la
suite, lancée dans le dossier du plugin par www-data avec `GLPI_ROOT_DIR=/var/www/glpi` et
`GLPI_TEST_LANGUAGE=fr_FR`. La suite est détectée :

| Fichier du plugin | Commande |
|---|---|
| `phpunit-integration.xml(.dist)` | `php vendor/bin/phpunit -c phpunit-integration.xml.dist` |
| `phpunit.xml(.dist)` avec une suite `integration` | `php vendor/bin/phpunit -c phpunit.xml --testsuite integration` |
| `phpunit.xml(.dist)` sans cette suite | `php vendor/bin/phpunit -c phpunit.xml` |
| aucun | avertissement, pas d'échec |

Réglages dans le `security.yml` du plugin :

```yaml
    with:
      plugin-key: monplugin
      integration-command: php vendor/bin/phpunit -c phpunit.xml --testsuite integration  # facultatif
      integration-languages: '["fr_FR", "de_DE"]'   # défaut ["fr_FR"] ; '[]' pour ne pas lancer la passe
```

Lancement local : `bash checks/start-glpi.sh`, puis
`bash checks/run-integration-tests.sh <dossier du plugin> <clé> fr_FR [commande]`.

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
- **Menus** (bloquant depuis v1.9.0) : les entrées que le plugin ajoute aux menus de GLPI (hook
  `menu_toadd`) sont listées ; au-delà d'une, le job échoue (charte : une seule entrée de menu
  d'usage, réglages dans la page du plugin). Un outil métier complet qui
  déclare `menus-secteur-metier : <justification>` dans son fichier d'exceptions voit son secteur
  de menu propre compté comme une seule entrée (charte, section 9).
  Une entrée justifiée dans un secteur natif (par exemple un objet ITIL placé dans Assistance) se
  déclare par `menus-entree-native <secteur> > <classe> : <justification>` et n'est plus comptée.

### Mise à jour depuis la version publiée (`checks/check-upgrade.sh`, GLPI 11 et 12)

Ce que font tous les utilisateurs : mettre à jour une installation existante. Une installation
neuve ne le teste pas (une migration qui oublie une colonne passe la CI et casse chaque mise à
jour). Le job `upgrade`, une fois par image de `upgrade-glpi-images` (GLPI 11.0.11 et 12.0.1 par
défaut), dans un GLPI jetable :

1. installe le code de la PR à neuf : schéma, tâches automatiques, notifications et droits de
   référence ; la base est ensuite remise dans son état d'avant le plugin ;
2. installe et active la **dernière release publiée** (archive de la release GitHub, vérifiée par
   son `.sha256` s'il est publié ; sur un push d'étiquette, la release précédente) ;
3. crée le jeu de données de cette version : `upgrade-seed-command`, sinon `seed-command`, sinon
   `php plugins/<clé>/tests/security/setup.php` de l'**étiquette** publiée (les `tests/`, exclus de
   l'archive, sont repris des sources de l'étiquette : le jeu de données correspond à son schéma) ;
4. remplace le plugin par le code de la PR (dossier vidé d'abord) et lance la mise à jour
   (`plugin:install --force`, puis `plugin:activate`) ;
5. vérifie :
   - **aucune erreur PHP ni SQL** : sortie de la mise à jour, `php-errors.log` et `sql-errors.log`
     (hors entrées écrites par GLPI lui-même : changement de version détecté, message de test) ;
   - **schéma identique** à l'installation neuve, table par table (`SHOW CREATE TABLE` : colonnes et
     leur définition, index et clés uniques, moteur et interclassement ; `AUTO_INCREMENT` et ordre
     des colonnes ignorés). Table absente ou en trop : écart aussi ;
   - **données conservées** : nombre de lignes de chaque table du plugin au moins égal ;
   - **tâches automatiques, notifications et droits** : rien de ce qui existait avant n'a disparu,
     aucun droit donné à un profil n'est retiré, et rien de ce que crée une installation neuve ne
     manque (une tâche ajoutée à l'installation mais oubliée dans la migration, par exemple). Un
     état de tâche ou une activation de notification modifié est signalé en avertissement ;
   - **pages principales servies** (200, sans erreur affichée) pour l'administrateur : accueil,
     liste des plugins, entrées de menu du plugin et sa page de configuration.

Le job s'adapte, avec un avertissement et sans échec : aucune release publiée (premier plugin),
release sans archive (sources de l'étiquette utilisées), version publiée qui ne s'installe pas sur
cette version de GLPI, pas de jeu de données ou jeu de données en échec. `upgrade-test: false` le
désactive. Un écart justifié se déclare dans le fichier d'exceptions avec l'identifiant affiché :
`upgrade-schema <table>`, `upgrade-table-missing <table>`, `upgrade-table-extra <table>`,
`upgrade-data <table>`, `upgrade-crontask <classe>::<nom>`, `upgrade-notification <classe>::<événement>`,
`upgrade-right <droit>`.

Auto-test : le plugin `tests/fixtures/upgrade` (clé `secup`) en version 1.0.0 (`old`, avec son jeu de
données) est mis à jour vers une 1.1.0 correcte (`good`, qui doit passer) et vers une 1.1.0
défectueuse (`bad`) : migration qui oublie une colonne et un index, table reconstruite, tâche,
notification et droits retirés, avertissement PHP, page refusée. Chaque défaut doit être signalé.
Lancement local : `bash checks/check-upgrade.sh <code> <clé> <version publiée> <sources> [exceptions] [jeu de données]`,
la version publiée étant obtenue par `bash checks/fetch-previous-release.sh <owner/dépôt> <clé> <dossier> <checkout>`.

### Parcours de toutes les pages (`checks/check-pages.sh`, GLPI 11 et 12, issue #23)

Complète le contrôle statique d'ergonomie par ce que voit un utilisateur. Le job `pages`, une fois
par image de `pages-glpi-images` (GLPI 11.0.11 et 12.0.1 par défaut), installe et active le plugin
dans un GLPI jetable, lance `seed-command` s'il est fourni, lit dans GLPI les pages du plugin
(`checks/crawl-data.php`), puis les ouvre dans Chromium (image Playwright de la CI de GRC,
`checks/crawl-pages.py`, repris du parcours de GRC #1050) avec chaque compte de `pages-accounts` :
`glpi` (administrateur) puis `tech` (technicien, sans droit d'administration) par défaut.

Pages découvertes, sans liste à tenir : entrées `menu_toadd` et leurs sous-pages (menu de GLPI en
session), page de configuration (`config_page`), chaque fichier `front/*.php`, une fiche existante
de chaque formulaire du plugin, les objets du cœur auxquels il ajoute un onglet (une fiche est
créée au besoin), puis chaque lien vers le plugin trouvé en chemin. Les onglets du plugin sont
demandés comme le fait GLPI. Anomalies, avec leur identifiant :

| Identifiant | Anomalie |
|---|---|
| `http-500` | Page en erreur serveur (5xx) |
| `http-404` | Page proposée par un lien (menu, sous-page, fil d'Ariane) introuvable |
| `http-403` | Page refusée à l'administrateur, ou à un autre compte alors que son menu la propose. Un fichier `front/` ouvert sans paramètre peut être une action : 400, 403 et 404 y sont normaux |
| `php` | Erreur PHP ou Twig affichée dans la page ; aussi toute ligne écrite dans `php-errors.log` ou `sql-errors.log` pendant le parcours (`crawl php-errors`, `crawl sql-errors`, sans exception possible) |
| `blank` | Page blanche (200, HTML, aucun texte) |
| `js` | Erreur JavaScript ou boîte de dialogue qui revient à une seconde visite (une erreur isolée est seulement listée) |
| `ariane` | Fil d'Ariane absent ou incomplet, secteur où le plugin n'a pas d'entrée de menu, ou, pour l'administrateur, différent de l'entrée de menu (ou de Configuration > Plugins pour la page de configuration) |
| `tab` | Onglet du plugin en erreur (500, 404, 403 pour l'administrateur, erreur affichée) |
| `orphan` | Écran du plugin (`front/`, 200 dans la mise en page de GLPI pour l'administrateur) qu'aucun menu ne permet d'atteindre, même par des liens successifs. Formulaires et actions (`.form.php`, `.export.php`, `.csv.php`…) exclus |
| `axe:<règle>` | Violation d'accessibilité grave (axe-core 4.10.3, impact `critical` ou `serious`, WCAG 2.1 A et AA : contrastes, libellés de formulaires, rôles, textes de remplacement) dans le contenu de la page (`main#page`), hors violations déjà présentes sur les pages du cœur (accueil, ordinateurs, plugins) |
| `perf` | Temps de réponse du serveur (requête envoyée, premier octet reçu) au-delà de `pages-max-ms` (3000 ms par défaut, `0` pour ne pas mesurer). Les cinq pages les plus lentes de chaque compte sont affichées |
| `onglets-nombre` | Plus de 7 onglets visibles dans une barre d'un écran du plugin (issue #34) |
| `onglets-lignes` | Barre d'onglets sur plusieurs lignes à 1280 px de large |
| `onglets-role` | Barre sans `role="tablist"`, ou onglet sans `role="tab"` |
| `onglets-clavier` | Le focus ne passe pas du premier onglet au suivant au clavier (Tab ou flèche droite) |
| `onglet-refuse` | Onglet vers une page refusée (403) au compte qui le voit : un onglet que le profil ne peut pas ouvrir ne s'affiche pas |
| `onglet-inactif` | Onglet vers une page qui annonce un module, une fonction ou un référentiel désactivé |

Barres d'onglets contrôlées : `role="tablist"`, `.nav-tabs`, `.nav-pills` du contenu des écrans
`front/` du plugin, hors onglets des fiches du cœur (`data-glpi-ajax-content`, comptés par GLPI).
Ces six anomalies suivent leur propre mode, `tabs-mode` (le même que les rubriques du contrôle
statique), indépendant de `pages-mode`.

Une page s'identifie par son chemin depuis le dossier du plugin (`front/x.php`), sans sa requête,
un onglet par `tab:<classe>$<numéro>`. Exception justifiée dans `.glpi-security-exceptions`, motifs
`*` et `?` acceptés :

```
crawl orphan front/export.php : téléchargement ouvert depuis la liste, sans écran
crawl axe:color-contrast front/dashboard.php : couleurs de la bibliothèque de graphiques
crawl perf front/report.php : rapport annuel, calcul de plusieurs secondes connu
```

Réglages dans le `security.yml` du plugin :

```yaml
    with:
      plugin-key: monplugin
      pages-mode: warning        # le temps de l'issue de correction, comme navigation-mode
      tabs-mode: warning         # barres d'onglets et rubriques, le temps de l'issue de navigation
      pages-max-ms: 5000
      pages-accounts: 'glpi:glpi lecteur:MotDePasse#1'   # comptes créés par seed-command
      # pages-test: false pour désactiver le job
```

Auto-test : secbad a une page en 500 (`crash.php`), un écran hors menu avec une image sans texte de
remplacement (`hidden.php`) et sa page de configuration rangée dans Assistance (fil d'Ariane faux) ;
secgood, la version correcte, passe. Depuis l'issue #34, secbad a aussi une barre de 9 onglets
(`tabs.php`) sans rôles ARIA, sur plusieurs lignes, inatteignable au clavier, avec un onglet refusé
au technicien et un onglet vers un module désactivé ; secgood, une barre de deux onglets correcte.
Lancement local (Docker) : `bash checks/start-glpi.sh`, puis
`bash checks/check-pages.sh <dossier> <clé> [exceptions] [jeu de données] [error|warning] [seuil] [comptes] [tabs-mode]`
(`GLPI_CONTAINER` et `GLPI_DB_CONTAINER` pour d'autres noms de conteneurs, `CRAWL_WORK` pour le
dossier de travail).

### Points d'entrée, matrice d'accès et exigences marketplace (`checks/`)

Ces trois contrôles étaient auparavant copiés dans `.github/scripts/` de chaque plugin (issue #11).
Chaque plugin garde ses fichiers de réglage dans son dépôt.

| Contrôle | Défaut détecté | Réglage dans le plugin |
|---|---|---|
| `check-entrypoints-auth.sh` | Page `front/` ou `ajax/` sans contrôle d'autorisation (`Session::checkRight`, `checkLoginUser`, `->check()`…) | Exception justifiée dans `.github/security-entrypoints-allowlist.txt` (`front/x.php : raison`) |
| `check-security-matrix-coverage.sh` | Page absente du test de contrôle d'accès `tests/security/access-matrix.sh` | Exception justifiée dans `tests/security/not-covered.txt`. Sans matrice (nouveau plugin), simple avertissement : modèle dans `scripts/security/`. Une matrice écrite en PHPUnit se déclare par l'entrée `access-matrix-file` (par exemple `tests/Integration/AccessControlTest.php`). Une page peut aussi être testée dans un fragment du dossier voisin `tests/security/access-matrix.d/` (une fonctionnalité = un fichier, chargé par la matrice) |
| `check-marketplace-readiness.php` | SQL brut hors installation ; plus de HTML écrit en PHP que la référence | Exception dans `.github/marketplace-allowlist.txt` ; référence `.github/twig-baseline.txt`, à régénérer avec `--update-baseline` quand elle baisse |

Lancement local, depuis le dossier du plugin :
`php <glpi-plugin-security>/checks/check-marketplace-readiness.php . [--update-baseline]`.

### Charte commune (`checks/check-charter.sh`, bloquant)

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

**Bloquant par défaut depuis v1.9.0** : tout écart fait échouer le job (`::error::`). Les quatre
plugins (GRC Manager, Configuration GLPI Auto, AssetSign, Docker Inventory) étaient conformes en
mode bloquant avant ce changement. En v1.7.x et v1.8.x, le défaut était `warning` (annotations sans
échec). Un plugin en cours de mise en conformité (nouveau plugin repris d'un existant, par exemple)
peut repasser temporairement en avertissement avec `charter-mode: warning` dans son `security.yml`,
le temps d'une issue de mise en conformité ouverte dans son dépôt.

### Analyses communes : secrets, dépendances, CodeQL (`checks/`, issue #25)

Les mêmes quatre analyses, avec la même configuration, pour chaque plugin. Elles remplacent les jobs
équivalents que chaque plugin avait copiés dans sa propre CI, à retirer lors de la montée de version.

| Job | Outil | Défaut détecté | Exception (`.glpi-security-exceptions`) |
|---|---|---|---|
| `secrets` | Gitleaks 8.30.1 (`check-secrets.sh`) | Secret (mot de passe, jeton, clé d'API) dans les fichiers du dépôt, et sur une pull request dans chacun de ses commits | `gitleaks <Fingerprint> : raison`, l'empreinte affichée par Gitleaks (`fichier:règle:ligne`, précédée du commit pour un secret de l'historique) |
| `dependencies` | Trivy 0.76.1 (`check-dependencies.sh`) | Dépendance composer ou npm (`composer.lock`, `package-lock.json`, `yarn.lock`, `vendor/`…) avec une vulnérabilité HIGH ou CRITICAL corrigée dans une version publiée | `trivy CVE-2026-12345 : raison` |
| `codeql` | CodeQL, suite par défaut (`codeql-languages.sh`, `check-codeql-sarif.sh`) | Résultat CodeQL pour chaque langage présent : `actions` (workflows), `javascript` (JavaScript, TypeScript), `python`. PHP reste couvert par Semgrep | `codeql <règle> <fichier ou motif> : raison`, par exemple `codeql js/xss-through-dom public/lib/* : bibliothèque tierce` |
| `dependency-review` | Dependency Review, sur les pull requests seulement | Dépendance ajoutée ou mise à jour par la PR (composer, npm, actions) avec un avis de gravité `moderate` ou plus | `dependency-review GHSA-xxxx-xxxx-xxxx : raison` |

Gitleaks et Trivy tournent dans leur image officielle épinglée par son empreinte : pas de
gitleaks-action, refusée (403) au jeton en lecture seule des PR Dependabot. CodeQL lit ses résultats
dans le job (`upload: never`) : le plugin n'a aucun droit `security-events` à donner au workflow
réutilisable, et la configuration CodeQL par défaut du dépôt (onglet Security) peut rester active
sans conflit. Dependency Review demande le graphe de dépendances activé dans le dépôt (Settings >
Code security). Une exception sans raison (pas de « : ») fait échouer le job.

Lancement local (Docker requis), depuis ce dépôt : `bash checks/check-secrets.sh <dossier> [exceptions]`,
`bash checks/check-dependencies.sh <dossier> [exceptions]`.

## Exceptions justifiées

Un faux positif se déclare dans `.glpi-security-exceptions`, à la racine du dépôt du plugin, une ligne
par exception, avec l'identifiant affiché par le contrôle et la raison :

```
tab-content Computer/PluginMonpluginPassport$1 : contenu public par conception (droits du cœur)
```

Une assertion de test sur un libellé traduit : `translated-assertion <fichier>::<méthode> : raison`
(voir plus haut).

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
