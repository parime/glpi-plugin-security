<?php

/**
 * Contrôles de sécurité exécutés dans un vrai GLPI, communs à tous les plugins.
 *
 * Usage (dans le conteneur GLPI, utilisateur www-data, plugin installé et activé sauf pour
 * « leftovers ») :
 *   php glpi-runtime-checks.php <clé du plugin> <tabs|notifications|leftovers> [fichier d'exceptions]
 *
 * - tabs : chaque onglet que le plugin ajoute à un objet du cœur est demandé comme le fait
 *   ajax/common.tabs.php (qui ne consulte jamais getTabNameForItem()), par un profil qui a tous les
 *   droits du cœur mais aucun droit du plugin. Le contenu doit être refusé.
 * - notifications : aucune cible de notification du plugin n'utilise un identifiant de cible du
 *   cœur (1 = administrateur global…), et chaque cible déclarée est une constante du cœur ou de
 *   la classe (jamais un identifiant calculé, comme un id d'utilisateur).
 * - leftovers : après désinstallation, plus aucune ligne des tables du cœur ne porte un type
 *   d'objet, un droit, une règle ou une configuration du plugin.
 *
 * Fichier d'exceptions : une ligne par exception justifiée, « <contrôle> <identifiant> : <raison> »,
 * l'identifiant étant celui qu'affiche le contrôle en échec. Lignes vides et « # » ignorées.
 *
 * Code de sortie : 0 si tout passe, 1 sinon. Aucune donnée n'est modifiée par « tabs » et
 * « notifications » hors de l'élément de test créé au besoin dans l'entité racine.
 */

declare(strict_types=1);

[$script, $key, $mode, $exceptionsFile] = $argv + [null, '', '', ''];
if ($key === '' || !in_array($mode, ['tabs', 'notifications', 'leftovers'], true)) {
    fwrite(STDERR, "Usage : php {$script} <clé du plugin> <tabs|notifications|leftovers> [exceptions]\n");
    exit(2);
}

require '/var/www/glpi/vendor/autoload.php';
$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();
Session::start();
if (!(new Auth())->login('glpi', 'glpi', false)) {
    fwrite(STDERR, "Connexion glpi/glpi impossible\n");
    exit(2);
}

$exceptions = [];
if ($exceptionsFile !== '' && is_readable($exceptionsFile)) {
    foreach (file($exceptionsFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        [$id] = explode(' : ', $line, 2);
        $exceptions[trim($id)] = true;
    }
}

$failures = 0;
$report = static function (string $check, string $id, string $message) use (&$failures, $exceptions): void {
    $full = $check . ' ' . $id;
    if (isset($exceptions[$full])) {
        echo "EXCEPTION  {$full} (justifiée dans le fichier d'exceptions)\n";
        return;
    }
    echo "::error::{$full} : {$message}\n";
    $failures++;
};

$legacyPrefix = 'Plugin' . ucfirst($key);
$namespacePrefix = 'GlpiPlugin\\' . ucfirst($key) . '\\';
$isPluginClass = static fn (string $class): bool => stripos($class, $legacyPrefix) === 0
    || stripos(ltrim($class, '\\'), $namespacePrefix) === 0;

/**
 * Classes du plugin, chargées par l'autoloader de GLPI : inc/*.class.php (convention historique)
 * et src/**.php (PSR-4).
 *
 * @return list<class-string>
 */
$pluginClasses = static function () use ($key): array {
    $dir = GLPI_ROOT . '/plugins/' . $key;
    $classes = [];
    $files = glob($dir . '/inc/*.php') ?: [];
    if (is_dir($dir . '/src')) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir . '/src'));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }
    foreach ($files as $file) {
        $code = (string) file_get_contents($file);
        $namespace = preg_match('/^namespace\s+([^;]+);/m', $code, $m) === 1 ? $m[1] . '\\' : '';
        if (preg_match_all('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $code, $matches) > 0) {
            foreach ($matches[1] as $class) {
                $fqcn = $namespace . $class;
                try {
                    if (class_exists($fqcn)) {
                        $classes[] = $fqcn;
                    }
                } catch (Throwable) {
                    // Classe qui ne se charge pas hors de son contexte : ignorée.
                }
            }
        }
    }

    return $classes;
};

if ($mode === 'tabs') {
    $property = new ReflectionProperty(CommonGLPI::class, 'othertabs');
    $registered = (array) $property->getValue();

    // Profil de contrôle : tous les droits du super-administrateur, sauf ceux du plugin.
    $adminProfile = $_SESSION['glpiactiveprofile'];
    $restricted = $adminProfile;
    foreach (array_keys($restricted) as $right) {
        if (is_string($right) && str_starts_with(strtolower($right), 'plugin_' . strtolower($key))) {
            $restricted[$right] = 0;
        }
    }
    $sample = static function (string $itemtype): ?CommonDBTM {
        global $DB;
        $item = new $itemtype();
        $table = $item::getTable();
        $where = [];
        foreach (['is_deleted', 'is_template'] as $flag) {
            if ($DB->fieldExists($table, $flag)) {
                $where[$flag] = 0;
            }
        }
        $row = $DB->request(['SELECT' => 'id', 'FROM' => $table, 'WHERE' => $where, 'LIMIT' => 1])->current();
        if ($row !== null && $item->getFromDB((int) $row['id'])) {
            return $item;
        }
        $id = $item->add([
            'name' => 'glpi-plugin-security', 'content' => 'glpi-plugin-security', 'entities_id' => 0,
        ]);

        return $id && $item->getFromDB((int) $id) ? $item : null;
    };

    $checked = 0;
    foreach ($registered as $host => $tabs) {
        if (!is_string($host) || $isPluginClass($host) || !is_a($host, CommonDBTM::class, true)) {
            // Un objet du plugin n'est lu qu'avec le droit du plugin : GLPI refuse déjà l'onglet.
            continue;
        }
        foreach (array_keys((array) $tabs) as $tab) {
            if (!is_string($tab) || !$isPluginClass($tab) || !method_exists($tab, 'displayTabContentForItem')) {
                continue;
            }
            $item = $sample($host);
            if ($item === null) {
                echo "SKIP  {$host} / {$tab} : aucun élément {$host} disponible pour le test\n";
                continue;
            }
            $names = (new $tab())->getTabNameForItem($item, 0);
            $tabNums = is_array($names) && $names !== [] ? array_keys($names) : [1];

            $_SESSION['glpiactiveprofile'] = $restricted;
            foreach ($tabNums as $num) {
                $checked++;
                ob_start();
                try {
                    $shown = $tab::displayTabContentForItem($item, $num, 0);
                } catch (\Glpi\Exception\Http\AccessDeniedHttpException | \Glpi\Exception\Http\NotFoundHttpException) {
                    $shown = false;
                } catch (Throwable $e) {
                    $shown = 'exception ' . $e::class . ' : ' . $e->getMessage();
                }
                $output = trim(strip_tags((string) ob_get_clean()));
                if (is_string($shown)) {
                    echo "WARN  tab-content {$host}/{$tab}\${$num} : {$shown}\n";
                } elseif ($shown !== false && $output !== '') {
                    $report(
                        'tab-content',
                        "{$host}/{$tab}\${$num}",
                        'contenu affiché sans aucun droit du plugin (ajax/common.tabs.php sert ce contenu sans '
                            . 'consulter getTabNameForItem()) : contrôler le droit dans displayTabContentForItem()'
                    );
                } else {
                    echo "OK  tab-content {$host}/{$tab}\${$num}\n";
                }
            }
            $_SESSION['glpiactiveprofile'] = $adminProfile;
        }
    }
    echo "{$checked} onglet(s) du plugin contrôlé(s) sur des objets du cœur.\n";
}

if ($mode === 'notifications') {
    $core = array_values(array_filter((new ReflectionClass(Notification::class))->getConstants(), 'is_int'));
    $checked = 0;
    foreach ($pluginClasses() as $class) {
        $reflection = new ReflectionClass($class);
        if (!$reflection->isSubclassOf(NotificationTarget::class) || $reflection->isAbstract()) {
            continue;
        }
        $class = $reflection->getName();
        $checked++;
        $own = [];
        foreach ($reflection->getReflectionConstants() as $constant) {
            if ($constant->getDeclaringClass()->getName() !== $class || !is_int($constant->getValue())) {
                continue;
            }
            if (stripos($constant->getName(), 'TARGET') === false) {
                continue;
            }
            $own[] = $constant->getValue();
            if ($constant->getValue() < 1000) {
                $report(
                    'notification-target',
                    "{$class}::{$constant->getName()}",
                    "identifiant {$constant->getValue()} dans la plage des cibles du cœur : GLPI le traite comme "
                        . 'la cible du cœur et n\'appelle pas addSpecificTargets(). Utiliser un identifiant ≥ 1000'
                );
            }
        }
        $itemtype = str_replace('NotificationTarget', '', $class);
        try {
            $item = is_a($itemtype, CommonDBTM::class, true) ? new $itemtype() : null;
            $target = new $class(0, '', $item);
            $target->notification_targets = [];
            $target->addAdditionalTargets();
        } catch (Throwable $e) {
            echo "WARN  notification-target {$class} : cibles non énumérables ({$e->getMessage()})\n";
            continue;
        }
        foreach (array_keys($target->notification_targets) as $targetKey) {
            [$type, $id] = array_map('intval', explode('_', (string) $targetKey) + [1 => 0]);
            if ($type === Notification::USER_TYPE && !in_array($id, array_merge($core, $own), true)) {
                $report(
                    'notification-target',
                    "{$class}#{$id}",
                    "cible {$id} déclarée sans constante : identifiant calculé (id d'utilisateur ?), jamais résolu "
                        . 'par addSpecificTargets() et confondu avec une cible du cœur s\'il est petit'
                );
            }
        }
        echo "OK  notification-target {$class}\n";
    }
    echo "{$checked} classe(s) de cibles de notification contrôlée(s).\n";
}

if ($mode === 'leftovers') {
    global $DB;
    $likes = [$legacyPrefix . '%', addcslashes($namespacePrefix, '\\') . '%'];
    $columns = $DB->request([
        'SELECT' => ['TABLE_NAME', 'COLUMN_NAME'],
        'FROM'   => 'information_schema.COLUMNS',
        'WHERE'  => [
            'TABLE_SCHEMA' => $DB->dbdefault,
            'COLUMN_NAME'  => ['itemtype', 'itemtype_link', 'sub_type'],
            ['NOT' => ['TABLE_NAME' => ['LIKE', 'glpi\_plugin\_%']]],
        ],
    ]);
    $checks = [];
    foreach ($columns as $column) {
        $prefixes = $column['COLUMN_NAME'] === 'sub_type'
            ? ['Rule' . $legacyPrefix . '%', 'Rule' . ucfirst($key) . '%', addcslashes($namespacePrefix, '\\') . '%']
            : $likes;
        $checks[] = [$column['TABLE_NAME'], $column['COLUMN_NAME'], $prefixes];
    }
    $checks[] = ['glpi_profilerights', 'name', ['plugin\_' . $key . '%']];
    $checks[] = ['glpi_configs', 'context', ['plugin:' . $key . '%']];
    foreach ($checks as [$table, $column, $prefixes]) {
        $count = countElementsInTable($table, ['OR' => array_map(
            static fn (string $like): array => [$column => ['LIKE', $like]],
            $prefixes
        )]);
        if ($count > 0) {
            $report(
                'uninstall-leftover',
                "{$table}.{$column}",
                "{$count} ligne(s) du plugin restent après désinstallation : les retirer dans la désinstallation "
                    . '(par la classe du cœur quand elle existe, pour que ses propres nettoyages s\'exécutent)'
            );
        }
    }
    echo count($checks) . " colonne(s) des tables du cœur contrôlée(s) après désinstallation.\n";
}

exit($failures > 0 ? 1 : 0);
