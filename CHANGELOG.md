# Journal des versions

Les versions antérieures à v1.11.0 sont décrites dans les
[publications GitHub](https://github.com/parime/glpi-plugin-security/releases) du dépôt.

## v1.11.3

### Ajouté
- Ergonomie, nouvel écart `config-version-premier <gabarit>` (mode `tabs-mode`, comme
  `config-version`) : l'encadré « Version du plugin » doit être le premier bloc de la page de
  configuration, en tête de son premier onglet (glpi-plugin-template#18, charte section 9 bis).
  Analyse statique du gabarit qui inclut `_plugin_version.html.twig` : l'onglet est le
  `<div class="tab-pane">` ou la branche `{% if tab == '...' %}` qui le contient, et il doit être
  le premier de la page ; avant l'encadré, seuls l'aide de page (`_page_help`), la barre d'onglets
  (`_section_nav`) et des conteneurs sont admis (pas de champ, titre, tableau, paragraphe, alerte
  ni autre inclusion). Cas ajoutés à l'auto-test.

## v1.11.2

### Corrigé
- Parcours des pages, écart `onglets-clavier` (depuis v1.10.3) : une barre d'onglets sans onglet
  visible (masquée par un ancêtre, comme le panneau « Recherches enregistrées » du cœur GLPI présent
  sur les listes et certaines fiches) n'est plus comptée ni parcourue au clavier, et une barre dont
  un seul onglet est visible n'a plus à passer au suivant (#46, faux positifs sur AssetSign). Cas
  ajoutés à secgood.

## v1.11.1

### Corrigé
- Ergonomie, écart `module-inactif` (depuis v1.10.3) : un `getTabNameForItem()` ou un
  `getMenuContent()` qui ne renvoie jamais rien (`return false;`, `return '';`, `return [];`) n'a
  rien à masquer quand le module est désactivé ; il n'est plus signalé (faux positifs constatés sur
  GRC, registres de référentiels sans entrée de menu). Cas ajouté à secgood et à l'auto-test.

## v1.11.0

### Ajouté
- Entrée facultative `heavy-paths` du workflow commun : les contrôles qui démarrent un vrai GLPI
  (« Contrôles dans un vrai GLPI », tests d'intégration en français, mise à jour depuis la version
  publiée, parcours de toutes les pages) ne tournent sur une pull request que si elle modifie un
  fichier concerné ; sinon ils sont « skipped ». Nouveau job « Contrôles lourds nécessaires
  (fichiers modifiés) » et script `checks/heavy-changes.sh`. Sans l'entrée, sur un push, une
  étiquette ou un lancement manuel, ou en cas de doute : tout tourne, comme avant.
- Exceptions en fragments : chaque fichier de `.glpi-security-exceptions.d/` est lu en plus de
  `.glpi-security-exceptions`, même syntaxe (`checks/exceptions-cat.sh`), par tous les contrôles
  (Gitleaks, Trivy, Dependency Review, CodeQL, onglets et notifications, mise à jour, parcours des
  pages, ergonomie, couleurs, libellés traduits). Le fichier racine reste accepté.
- README : groupe `concurrency` conseillé dans le workflow appelant (un nouveau commit sur une PR
  annule l'exécution précédente, jamais sur un push).

### Modifié
- Contrôle de l'archive : `.glpi-security-exceptions.d/` est un fichier de développement, à exclure
  avec `export-ignore` comme le fichier racine.
- Auto-test : une seule exécution par commit de PR (push limité à main et aux étiquettes),
  exécutions dépassées d'une PR annulées ; cas de `heavy-changes.sh` (documentation
  seule, code, fichier déplacé, push, entrée absente) et des fragments d'exceptions.

### Corrigé
- Trivy : une panne de téléchargement de l'image est distinguée d'une vulnérabilité (#41).
