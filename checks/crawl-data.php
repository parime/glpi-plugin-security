<?php

/**
 * Données du parcours des pages (checks/crawl-pages.py, issue #23), lues dans GLPI.
 *
 * Usage (dans le conteneur GLPI, utilisateur www-data, plugin installé et activé) :
 *   php crawl-data.php <clé du plugin> [identifiant] [mot de passe]
 *
 * Affiche une ligne JSON :
 * - « prefix » : chemin du plugin depuis la racine web (plugins/<clé>/ ou marketplace/<clé>/) ;
 * - « front » : fichiers front/*.php du plugin ;
 * - « menu » : chaque page du plugin du menu de GLPI (entrées menu_toadd et leurs sous-pages), avec
 *   le fil d'Ariane que GLPI doit y afficher (secteur, rubrique, sous-page), pour le compte donné ;
 * - « sectors » : titres des secteurs où le plugin a une entrée, plus Configuration ;
 * - « config » : page de configuration (hook config_page) et son fil d'Ariane (Configuration > Plugins) ;
 * - « forms » : une fiche existante de chaque formulaire du plugin, et une fiche de chaque objet du
 *   cœur auquel le plugin ajoute un onglet (créée dans l'entité racine si aucune n'existe).
 */

declare(strict_types=1);

[$script, $key, $login, $password] = $argv + [null, '', 'glpi', 'glpi'];
if ($key === '') {
    fwrite(STDERR, "Usage : php {$script} <clé du plugin> [identifiant] [mot de passe]\n");
    exit(2);
}

require '/var/www/glpi/vendor/autoload.php';
$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();
Session::start();
if (!(new Auth())->login($login, $password, false)) {
    fwrite(STDERR, "Connexion de $login impossible\n");
    exit(1);
}
Session::changeActiveEntities(0, true);

global $DB, $PLUGIN_HOOKS;
$dir = Plugin::getPhpDir($key);
$prefix = ltrim(str_replace('/var/www/glpi', '', (string) $dir), '/') . '/';
$prefix = preg_replace('#^public/#', '', $prefix);

$front = [];
foreach (glob("$dir/front/*.php") ?: [] as $file) {
    $front[] = 'front/' . basename($file);
}

$relative = static function ($page) use ($key): ?string {
    $page = (string) $page;
    if (!preg_match('#/(?:plugins|marketplace)/' . preg_quote($key, '#') . '/(.*)$#', $page, $m)) {
        return null;
    }

    return $m[1];
};

// Fil d'Ariane attendu : celui que layout/parts/breadcrumbs.html.twig rend pour l'entrée de menu.
$menu = [];
$sectors = [];
try {
    $session = Html::generateMenuSession(true);
    // Menu affiché : Html::header() applique le crochet REDEFINE_MENUS des plugins après
    // generateMenuSession() ; le fil attendu doit être lu sur le même menu (issue #50).
    $session = Plugin::doHookFunction(\Glpi\Plugin\Hooks::REDEFINE_MENUS, $session);
    if (!is_array($session)) {
        $session = [];
    }
} catch (Throwable $e) {
    $session = [];
}
foreach ($session as $sectorKey => $sector) {
    foreach ((array) ($sector['content'] ?? []) as $item) {
        if (!is_array($item) || !isset($item['page'])) {
            continue;
        }
        $crumb = [(string) ($sector['title'] ?? ''), (string) ($item['title'] ?? '')];
        $page = $relative($item['page']);
        if ($page !== null) {
            $sectors[$sectorKey] = (string) ($sector['title'] ?? '');
            $menu[$page] ??= $crumb;
        }
        foreach ((array) ($item['options'] ?? []) as $option) {
            if (!is_array($option) || !isset($option['page'], $option['title'])) {
                continue;
            }
            $page = $relative($option['page']);
            if ($page !== null && count($menu[$page] ?? []) < 3) {
                $sectors[$sectorKey] = (string) ($sector['title'] ?? '');
                $menu[$page] = array_merge($crumb, [(string) $option['title']]);
            }
        }
    }
}

$config = null;
if (!empty($PLUGIN_HOOKS['config_page'][$key])) {
    $plugins = (string) ($session['config']['content']['plugin']['title'] ?? Plugin::getTypeName(Session::getPluralNumber()));
    $configTitle = (string) ($session['config']['title'] ?? __('Setup'));
    $sectors['config'] = $configTitle;
    $config = ['page' => ltrim((string) $PLUGIN_HOOKS['config_page'][$key], '/'), 'crumbs' => [$configTitle, $plugins]];
}

// Classes du plugin : inc/*.class.php (PluginCleNom) et src/ (GlpiPlugin\Cle\...).
$classes = [];
foreach (glob("$dir/inc/*.class.php") ?: [] as $file) {
    $classes[] = 'Plugin' . ucfirst($key) . basename($file, '.class.php');
}
if (is_dir("$dir/src")) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$dir/src")) as $file) {
        if (str_ends_with((string) $file, '.php')) {
            $classes[] = 'GlpiPlugin\\' . ucfirst($key) . '\\'
                . str_replace('/', '\\', substr((string) $file, strlen("$dir/src/"), -4));
        }
    }
}

// Objets du cœur auxquels une classe du plugin ajoute un onglet (Plugin::registerClass addtabon).
$core = [];
try {
    $tabs = (new ReflectionClass(CommonGLPI::class))->getProperty('othertabs');
    $tabs->setAccessible(true);
    foreach ((array) $tabs->getValue() as $itemtype => $tabClasses) {
        foreach (array_keys((array) $tabClasses) as $tabClass) {
            if (in_array(strtolower(ltrim((string) $tabClass, '\\')), array_map('strtolower', $classes), true)) {
                $core[] = (string) $itemtype;
            }
        }
    }
} catch (Throwable $e) {
}

$forms = [];
foreach (array_unique(array_merge($classes, $core)) as $class) {
    try {
        if (!class_exists($class) || !is_subclass_of($class, CommonDBTM::class)
            || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }
        $path = ltrim((string) parse_url($class::getFormURL(false), PHP_URL_PATH), '/');
        $table = $class::getTable();
        if (isset($forms[$path]) || !str_ends_with($path, '.form.php') || !$DB->tableExists($table)) {
            continue;
        }
        $where = $DB->fieldExists($table, 'is_deleted') ? ['is_deleted' => 0] : [];
        $row = $DB->request(['SELECT' => ['MAX' => 'id AS id'], 'FROM' => $table, 'WHERE' => $where])->current();
        $id = (int) ($row['id'] ?? 0);
        if ($id === 0 && in_array($class, $core, true)) {
            $item = new $class();
            $fields = ['name' => 'crawl-' . $key];
            if ($DB->fieldExists($table, 'entities_id')) {
                $fields['entities_id'] = 0;
            }
            $id = (int) $item->add($fields);
        }
        if ($id > 0) {
            $forms[preg_replace('#^public/#', '', $path)] = $id;
        }
    } catch (Throwable $e) {
        continue;
    }
}

echo json_encode([
    'prefix'  => $prefix,
    'front'   => $front,
    'menu'    => (object) $menu,
    'sectors' => array_values(array_unique($sectors)),
    'config'  => $config,
    'forms'   => (object) $forms,
]), "\n";
