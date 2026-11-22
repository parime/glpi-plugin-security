<?php

/**
 * Test commun de mise à jour depuis la version publiée précédente (issue #24), lancé par
 * check-upgrade.sh dans le conteneur GLPI (utilisateur www-data).
 *
 * Usage :
 *   php glpi-upgrade-checks.php snapshot <clé du plugin> <fichier JSON>
 *       État du plugin installé : schéma (SHOW CREATE TABLE normalisé) et nombre de lignes de
 *       chaque table glpi_plugin_<clé>_*, tâches automatiques, notifications, droits par profil,
 *       pages principales (entrées de menu et page de configuration).
 *   php glpi-upgrade-checks.php compare <fresh.json> <before.json> <after.json> [fichier d'exceptions]
 *       Compare l'état après mise à jour (after) à une installation neuve du même code (fresh) et
 *       à l'état avant mise à jour (before). Code 1 si un écart bloquant est trouvé.
 *
 * Identifiants d'exception (fichier d'exceptions du plugin, « <identifiant> : <raison> ») :
 *   upgrade-schema <table>, upgrade-table-missing <table>, upgrade-table-extra <table>,
 *   upgrade-data <table>, upgrade-crontask <itemtype>::<nom>, upgrade-notification <itemtype>::<événement>,
 *   upgrade-right <droit>.
 */

declare(strict_types=1);

[$script, $mode] = $argv + [null, ''];

if ($mode === 'snapshot') {
    [, , $key, $out] = $argv + [null, null, '', ''];
    if ($key === '' || $out === '') {
        fwrite(STDERR, "Usage : php {$script} snapshot <clé du plugin> <fichier JSON>\n");
        exit(2);
    }
    exit(snapshot($key, $out));
}
if ($mode === 'compare') {
    [, , $fresh, $before, $after, $exceptionsFile] = $argv + [null, null, '', '', '', ''];
    if ($fresh === '' || $before === '' || $after === '') {
        fwrite(STDERR, "Usage : php {$script} compare <fresh.json> <before.json> <after.json> [exceptions]\n");
        exit(2);
    }
    exit(compare($fresh, $before, $after, $exceptionsFile));
}
fwrite(STDERR, "Usage : php {$script} <snapshot|compare> …\n");
exit(2);

/**
 * Définition d'une table, normalisée pour comparer une table créée d'un coup (installation
 * neuve) à une table construite par migrations successives : compteur AUTO_INCREMENT retiré,
 * colonnes et index indexés par leur nom (l'ordre des colonnes n'est pas comparé).
 *
 * @return array{columns: array<string, string>, keys: array<string, string>, options: string}
 */
function normalizeCreateTable(string $create): array
{
    $columns = [];
    $keys = [];
    $options = '';
    foreach (preg_split('/\R/', $create) ?: [] as $line) {
        $line = rtrim(trim($line), ',');
        if (preg_match('/^`([^`]+)`\s+(.*)$/', $line, $m)) {
            $columns[$m[1]] = $m[2];
        } elseif (preg_match('/^PRIMARY KEY\s+(.*)$/', $line, $m)) {
            $keys['PRIMARY'] = 'PRIMARY KEY ' . $m[1];
        } elseif (preg_match('/^((?:UNIQUE |FULLTEXT |SPATIAL )?KEY|CONSTRAINT)\s+`([^`]+)`\s*(.*)$/', $line, $m)) {
            $keys[$m[2]] = $m[1] . ' ' . $m[3];
        } elseif (str_starts_with($line, ')')) {
            $options = trim(preg_replace('/\s+AUTO_INCREMENT=\d+/', '', substr($line, 1)) ?? '');
        }
    }
    ksort($columns);
    ksort($keys);

    return ['columns' => $columns, 'keys' => $keys, 'options' => $options];
}

function snapshot(string $key, string $out): int
{
    require '/var/www/glpi/vendor/autoload.php';
    $kernel = new \Glpi\Kernel\Kernel();
    $kernel->boot();
    Session::start();
    if (!(new Auth())->login('glpi', 'glpi', false)) {
        fwrite(STDERR, "Connexion glpi/glpi impossible\n");
        return 2;
    }
    // Le hook d'initialisation s'est exécuté avant la connexion : le relancer, comme à chaque
    // requête HTTP d'un administrateur, pour que les entrées de menu soumises à un droit existent.
    $init = 'plugin_init_' . $key;
    if (function_exists($init)) {
        try {
            $init();
        } catch (\Throwable $e) {
            fwrite(STDERR, "plugin_init_{$key}() : {$e->getMessage()}\n");
        }
    }

    global $DB, $PLUGIN_HOOKS;
    $legacyPrefix = 'Plugin' . ucfirst($key);
    $namespacePrefix = 'GlpiPlugin\\' . ucfirst($key) . '\\';
    $itemtypeWhere = ['OR' => [
        ['itemtype' => ['LIKE', $legacyPrefix . '%']],
        ['itemtype' => ['LIKE', addcslashes($namespacePrefix, '\\') . '%']],
    ]];

    $tables = [];
    foreach ($DB->listTables('glpi\_plugin\_' . $key . '\_%') as $row) {
        $table = $row['TABLE_NAME'];
        $create = $DB->doQuery('SHOW CREATE TABLE ' . $DB->quoteName($table))->fetch_row()[1] ?? '';
        $tables[$table] = normalizeCreateTable((string) $create) + ['rows' => countElementsInTable($table)];
    }
    ksort($tables);

    $crontasks = [];
    foreach ($DB->request(['FROM' => 'glpi_crontasks', 'WHERE' => $itemtypeWhere]) as $row) {
        $crontasks[$row['itemtype'] . '::' . $row['name']] = [
            'state'     => (int) $row['state'],
            'frequency' => (int) $row['frequency'],
        ];
    }
    ksort($crontasks);

    $notifications = [];
    foreach ($DB->request(['FROM' => 'glpi_notifications', 'WHERE' => $itemtypeWhere]) as $row) {
        $id = $row['itemtype'] . '::' . $row['event'];
        $notifications[$id]['count'] = ($notifications[$id]['count'] ?? 0) + 1;
        $notifications[$id]['active'] = ($notifications[$id]['active'] ?? 0) + (int) $row['is_active'];
    }
    ksort($notifications);

    $rights = [];
    $rows = $DB->request([
        'FROM'  => 'glpi_profilerights',
        'WHERE' => ['OR' => [
            ['name' => ['LIKE', 'plugin\_' . $key . '%']],
            ['name' => ['LIKE', 'rule\_' . $key . '%']],
        ]],
    ]);
    foreach ($rows as $row) {
        $rights[$row['name']][(string) $row['profiles_id']] = (int) $row['rights'];
    }
    ksort($rights);

    // Pages principales : entrées de menu (hook menu_toadd) et page de configuration.
    $pages = [];
    foreach ((array) ($PLUGIN_HOOKS[\Glpi\Plugin\Hooks::MENU_TOADD][$key] ?? []) as $classes) {
        foreach ((array) $classes as $class) {
            if (!is_string($class) || !class_exists($class)) {
                continue;
            }
            try {
                $menu = method_exists($class, 'getMenuContent') ? $class::getMenuContent() : false;
                $page = is_array($menu) && isset($menu['page']) ? (string) $menu['page'] : $class::getSearchURL(false);
            } catch (\Throwable $e) {
                fwrite(STDERR, "Entrée de menu {$class} : {$e->getMessage()}\n");
                continue;
            }
            if ($page !== '') {
                $pages[] = $page;
            }
        }
    }
    $configPage = $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::CONFIG_PAGE][$key] ?? '';
    if (is_string($configPage) && $configPage !== '') {
        $pages[] = '/plugins/' . $key . '/' . ltrim($configPage, '/');
    }
    $pages = array_values(array_unique(array_map(
        static fn (string $p): string => '/' . ltrim((string) preg_replace('#^https?://[^/]+#', '', $p), '/'),
        $pages
    )));

    $state = compact('tables', 'crontasks', 'notifications', 'rights', 'pages');
    file_put_contents($out, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    printf(
        "Instantané : %d table(s), %d tâche(s) automatique(s), %d notification(s), %d droit(s), %d page(s).\n",
        count($tables),
        count($crontasks),
        count($notifications),
        count($rights),
        count($pages)
    );

    return 0;
}

function compare(string $freshFile, string $beforeFile, string $afterFile, string $exceptionsFile): int
{
    $load = static fn (string $f): array => json_decode((string) file_get_contents($f), true) ?: [];
    $fresh = $load($freshFile);
    $before = $load($beforeFile);
    $after = $load($afterFile);

    $exceptions = [];
    if ($exceptionsFile !== '' && is_readable($exceptionsFile)) {
        foreach (file($exceptionsFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') {
                $exceptions[trim(explode(' : ', $line, 2)[0])] = true;
            }
        }
    }
    $failures = ['schema' => 0, 'data' => 0, 'objects' => 0];
    $report = static function (string $family, string $id, string $message) use (&$failures, $exceptions): void {
        if (isset($exceptions[$id])) {
            echo "EXCEPTION  {$id} (justifiée dans le fichier d'exceptions)\n";
            return;
        }
        echo "::error::{$id} : {$message}\n";
        $failures[$family]++;
    };
    $warn = static fn (string $message) => print("::warning::{$message}\n");

    // 1. Schéma : identique à une installation neuve du même code.
    $freshTables = $fresh['tables'] ?? [];
    $afterTables = $after['tables'] ?? [];
    foreach ($freshTables as $table => $def) {
        if (!isset($afterTables[$table])) {
            $report('schema', "upgrade-table-missing {$table}", "table créée par une installation neuve mais absente après mise à jour (migration manquante)");
            continue;
        }
        $up = $afterTables[$table];
        $diffs = [];
        foreach ($def['columns'] as $col => $sql) {
            if (!isset($up['columns'][$col])) {
                $diffs[] = "colonne `{$col}` absente (attendue : {$sql})";
            } elseif ($up['columns'][$col] !== $sql) {
                $diffs[] = "colonne `{$col}` : « {$up['columns'][$col]} » au lieu de « {$sql} »";
            }
        }
        foreach (array_diff_key($up['columns'], $def['columns']) as $col => $sql) {
            $diffs[] = "colonne `{$col}` en trop ({$sql}), absente d'une installation neuve";
        }
        foreach ($def['keys'] as $name => $sql) {
            if (!isset($up['keys'][$name])) {
                $diffs[] = "index {$name} absent (attendu : {$sql})";
            } elseif ($up['keys'][$name] !== $sql) {
                $diffs[] = "index {$name} : « {$up['keys'][$name]} » au lieu de « {$sql} »";
            }
        }
        foreach (array_diff_key($up['keys'], $def['keys']) as $name => $sql) {
            $diffs[] = "index {$name} en trop ({$sql})";
        }
        if (($up['options'] ?? '') !== ($def['options'] ?? '')) {
            $diffs[] = "options « {$up['options']} » au lieu de « {$def['options']} »";
        }
        if ($diffs !== []) {
            $report('schema', "upgrade-schema {$table}", "après mise à jour, différente d'une installation neuve : " . implode(' ; ', $diffs));
        }
    }
    foreach (array_diff_key($afterTables, $freshTables) as $table => $def) {
        $report('schema', "upgrade-table-extra {$table}", "table présente après mise à jour mais pas dans une installation neuve (table obsolète à supprimer ou à renommer par la migration)");
    }
    echo count($freshTables) . " table(s) comparée(s) à une installation neuve.\n";

    // 2. Données : le jeu de données de la version précédente est toujours là.
    foreach ($before['tables'] ?? [] as $table => $def) {
        $rows = (int) ($def['rows'] ?? 0);
        if (!isset($afterTables[$table])) {
            if ($rows > 0 && !isset($freshTables[$table])) {
                $warn("{$table} ({$rows} ligne(s)) n'existe plus après mise à jour : vérifier que la migration a repris ses données");
            } elseif ($rows > 0) {
                $report('data', "upgrade-data {$table}", "{$rows} ligne(s) avant mise à jour, table supprimée par la mise à jour");
            }
            continue;
        }
        $now = (int) ($afterTables[$table]['rows'] ?? 0);
        if ($now < $rows) {
            $report('data', "upgrade-data {$table}", "{$rows} ligne(s) avant mise à jour, {$now} après : données perdues");
        }
    }
    $seeded = array_sum(array_map(static fn (array $t): int => (int) ($t['rows'] ?? 0), $before['tables'] ?? []));
    echo "{$seeded} ligne(s) du plugin avant mise à jour, contrôlées après.\n";

    // 3. Tâches automatiques, notifications et droits conservés, et complets.
    foreach (['crontasks' => ['upgrade-crontask', 'tâche automatique'], 'notifications' => ['upgrade-notification', 'notification']] as $kind => [$prefix, $label]) {
        foreach ($before[$kind] ?? [] as $id => $def) {
            if (!isset($after[$kind][$id])) {
                $report('objects', "{$prefix} {$id}", "{$label} présente avant mise à jour, disparue après");
            } elseif ($kind === 'crontasks' && $after[$kind][$id]['state'] !== $def['state']) {
                $warn("tâche automatique {$id} : état {$def['state']} avant mise à jour, {$after[$kind][$id]['state']} après (réglage de l'administrateur écrasé ?)");
            } elseif ($kind === 'notifications' && ($after[$kind][$id]['count'] ?? 0) < ($def['count'] ?? 0)) {
                $report('objects', "{$prefix} {$id}", "{$def['count']} {$label}(s) avant mise à jour, {$after[$kind][$id]['count']} après");
            } elseif ($kind === 'notifications' && ($after[$kind][$id]['active'] ?? 0) !== ($def['active'] ?? 0)) {
                $warn("notification {$id} : activation modifiée par la mise à jour (réglage de l'administrateur écrasé ?)");
            }
        }
        foreach (array_diff_key($fresh[$kind] ?? [], $after[$kind] ?? []) as $id => $def) {
            if (!isset($before[$kind][$id])) {
                $report('objects', "{$prefix} {$id}", "{$label} créée par une installation neuve mais pas par la mise à jour");
            }
        }
    }
    foreach ($before['rights'] ?? [] as $right => $profiles) {
        foreach ($profiles as $profile => $value) {
            $now = $after['rights'][$right][$profile] ?? null;
            if ($now === null) {
                $report('objects', "upgrade-right {$right}", "droit du profil {$profile} disparu après mise à jour");
            } elseif (($now & $value) !== $value) {
                $report('objects', "upgrade-right {$right}", "profil {$profile} : droits {$value} avant mise à jour, {$now} après (droits retirés)");
            }
        }
    }
    foreach (array_diff_key($fresh['rights'] ?? [], $after['rights'] ?? []) as $right => $profiles) {
        $report('objects', "upgrade-right {$right}", "droit créé par une installation neuve mais pas par la mise à jour : aucun profil ne peut le recevoir");
    }
    printf(
        "%d tâche(s) automatique(s), %d notification(s), %d droit(s) contrôlé(s).\n",
        count($before['crontasks'] ?? []) + count($fresh['crontasks'] ?? []),
        count($before['notifications'] ?? []) + count($fresh['notifications'] ?? []),
        count($before['rights'] ?? []) + count($fresh['rights'] ?? [])
    );

    echo "COMPARE schema=" . ($failures['schema'] > 0 ? 1 : 0)
        . ' data=' . ($failures['data'] > 0 ? 1 : 0)
        . ' objects=' . ($failures['objects'] > 0 ? 1 : 0) . "\n";

    return array_sum($failures) > 0 ? 1 : 0;
}
