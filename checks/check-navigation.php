<?php

/**
 * Ergonomie commune des plugins (issue #22, charte section 9, règle « Où placer un écran ») : les
 * réglages dans la page de configuration du plugin, l'exploitation dans le menu GLPI le plus logique,
 * rien de l'exploitation dans le paramétrage. Contrôle statique, sans GLPI :
 *
 * 1. Page de configuration (hook config_page, plus les pages front/ rangées dans Configuration >
 *    Plugins par Html::header(..., 'config', 'plugin' ou Plugin::class), les templates qu'elles
 *    affichent et incluent, et les classes du plugin dont le nom contient « Config ») : marqueurs
 *    d'exploitation :
 *      - config-action : bouton « ... maintenant » (récupérer, envoyer, importer, mettre à jour,
 *        télécharger, lire, synchroniser, lancer ; en français ou en anglais) ;
 *      - config-tableau : tableau de résultats ou de registre (boucle d'un <table> sur des
 *        résultats, journaux, imports, alertes..., ou composant components/datatable.html.twig) ;
 *      - config-lien : lien vers une page front/ d'exploitation du plugin (adresse écrite, ou
 *        getSearchURL(), getFormURL(), Menu::url() d'une classe du plugin qui n'est ni un réglage
 *        ni un intitulé).
 * 2. Menus (hook menu_toadd) : au plus une entrée par secteur GLPI, et aucune dans le secteur
 *    « config » (les réglages passent par config_page). Exceptions, les mêmes que le contrôle des
 *    menus dans un vrai GLPI : « menus-secteur-metier » (le secteur propre du plugin compte pour une
 *    entrée) et « menus-entree-native <secteur> > <classe> » (entrée justifiée, non comptée).
 * 3. En-tête des pages : chaque front/*.php qui affiche une page GLPI appelle Html::header() (ou un
 *    en-tête équivalent), avec un secteur où le plugin a une entrée de menu (ou « config ») et, si
 *    la classe passée est une entrée de menu, le secteur de cette entrée (fil d'Ariane).
 *      - page-entete : page affichée sans en-tête ;
 *      - entete-secteur : secteur de l'en-tête incohérent avec le menu déclaré.
 *
 * Faux positif ou écart justifié : ligne « <contrôle> <identifiant> : <raison> » dans
 * .glpi-security-exceptions, avec l'identifiant affiché entre crochets dans le message.
 *
 * Usage : php checks/check-navigation.php <dossier du plugin> [error|warning]
 *   error (par défaut) : code de sortie 1 si un écart ; warning : annotations ::warning::, code 0
 *   (plugin dont l'issue de navigation est ouverte, voir le README).
 */

declare(strict_types=1);

const EXCLUDED = '#^(\.|(vendor|node_modules|tests|lib|build|dist)/)#';
const EXCEPTIONS = '.glpi-security-exceptions';
const CHARTER = 'https://github.com/parime/glpi-plugin-template/blob/main/CHARTE.md';
const NATIVE_SECTORS = ['assets', 'helpdesk', 'management', 'tools', 'plugins', 'admin', 'config', 'preference'];
const SECTOR_LABELS = [
    'assets' => 'Parc', 'helpdesk' => 'Assistance', 'management' => 'Gestion', 'tools' => 'Outils',
    'plugins' => 'Plugins', 'admin' => 'Administration', 'config' => 'Configuration',
    'preference' => 'Préférences',
];
/** Verbe d'une action d'exploitation suivi de « maintenant » / « now » (libellé d'un bouton). */
const ACTION_NOW = '/\b(?:récupérer|recuperer|envoyer|importer|mettre à jour|mettre a jour|télécharger|telecharger|'
    . 'lire|synchroniser|lancer|exécuter|executer|actualiser|rafraîchir|retrieve|fetch|send|import|update|download|'
    . 'read|sync|synchroni[sz]e|run|refresh|pull)\b[^\'"<>{}\n]{0,40}?\b(?:maintenant|now)\s*[.!…]?\s*$/iu';
/** Libellés candidats d'une ligne : chaînes entre apostrophes ou guillemets, texte entre balises. */
const LABELS = '/(?=\'((?:[^\'\\\\\n]|\\\\.){1,60})\'|"((?:[^"\\\\\n]|\\\\.){1,60})"|>([^<>{}\n]{1,60})<)/u';
/** Dernier nom de la collection parcourue par un tableau de résultats ou de registre. */
const RESULT_WORDS = '/^(results?|logs?|history|histories|runs?|imports?|reports?|alerts?|findings?|scans?|events?|'
    . 'tickets?|records?|entries|journal|executions?|registers?|registry|readings?|reads?|fetched)$/i';

$dir = $argv[1] ?? '.';
$mode = $argv[2] ?? 'error';
if (!in_array($mode, ['error', 'warning'], true)) {
    fwrite(STDERR, "Mode inconnu : $mode (error ou warning)\n");
    exit(2);
}
if (!is_dir($dir) || !chdir($dir)) {
    fwrite(STDERR, "Dossier introuvable : $dir\n");
    exit(2);
}

/** @return list<string> fichiers du plugin dont l'extension est dans $extensions, sous $root */
function files(array $extensions, string $root = '.'): array
{
    if (!is_dir($root)) {
        return [];
    }
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = (string) preg_replace('#^\./#', '', str_replace('\\', '/', $file->getPathname()));
        if (in_array(strtolower($file->getExtension()), $extensions, true) && !preg_match(EXCLUDED, $path)) {
            $files[] = $path;
        }
    }
    sort($files);
    return $files;
}

/** Jetons PHP utiles (sans espaces ni commentaires) : [type, texte, ligne]. */
function tokens(string $code): array
{
    $out = [];
    $line = 1;
    foreach (token_get_all($code) as $t) {
        if (is_array($t)) {
            [$type, $text, $line] = $t;
            if (!in_array($type, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML], true)) {
                $out[] = [$type, $text, $line];
            }
        } else {
            $out[] = [0, $t, $line];
        }
    }
    return $out;
}

/** Source PHP sans commentaires (lignes conservées), pour chercher des libellés. */
function stripPhpComments(string $code): string
{
    $out = '';
    foreach (token_get_all($code) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $out .= str_repeat("\n", substr_count($t[1], "\n"));
        } else {
            $out .= is_array($t) ? $t[1] : $t;
        }
    }
    return $out;
}

/** Corps de la méthode $method dans $source : [ligne de début - 1, texte], ou null. */
function methodBody(string $source, string $method): ?array
{
    if (!preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/i', $source, $f, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $open = strpos($source, '{', $f[0][1]);
    if ($open === false) {
        return null;
    }
    for ($depth = 0, $i = $open, $len = strlen($source); $i < $len; $i++) {
        $depth += $source[$i] === '{' ? 1 : ($source[$i] === '}' ? -1 : 0);
        if ($depth === 0) {
            break;
        }
    }
    return [substr_count($source, "\n", 0, $f[0][1]), substr($source, $f[0][1], $i - $f[0][1] + 1)];
}

function stripTwigComments(string $code): string
{
    return (string) preg_replace_callback('/\{#.*?#\}/s', static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")), $code);
}

/** Index de la parenthèse/crochet fermant celui ouvert en $i. */
function closing(array $tokens, int $i): int
{
    $depth = 0;
    for ($n = count($tokens); $i < $n; $i++) {
        $c = $tokens[$i][1];
        if (in_array($c, ['(', '[', '{'], true) || $tokens[$i][0] === T_CURLY_OPEN || $tokens[$i][0] === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;
        } elseif (in_array($c, [')', ']', '}'], true)) {
            if (--$depth === 0) {
                return $i;
            }
        }
    }
    return count($tokens) - 1;
}

/** Découpe les jetons entre $open et sa fermeture en éléments séparés par des virgules. */
function splitTopLevel(array $tokens, int $open): array
{
    $end = closing($tokens, $open);
    $parts = [];
    $current = [];
    $depth = 0;
    for ($i = $open + 1; $i < $end; $i++) {
        $c = $tokens[$i][1];
        if (in_array($c, ['(', '[', '{'], true)) {
            $depth++;
        } elseif (in_array($c, [')', ']', '}'], true)) {
            $depth--;
        }
        if ($c === ',' && $depth === 0) {
            $parts[] = $current;
            $current = [];
            continue;
        }
        $current[] = $tokens[$i];
    }
    if ($current !== []) {
        $parts[] = $current;
    }
    return $parts;
}

function text(array $tokens): string
{
    return implode('', array_column($tokens, 1));
}

function unquote(string $s): string
{
    return stripcslashes(substr($s, 1, -1));
}

/** Nom court d'une classe (dernier segment). */
function short(string $class): string
{
    $parts = explode('\\', $class);
    return end($parts);
}

/** Constantes de classe du plugin : NOM => valeurs chaîne (pour Menu::TAB_X, secteurs...). */
$constants = [];
/** Classes du plugin : nom court => fichier. */
$classes = [];
$phpFiles = files(['php']);
foreach ($phpFiles as $php) {
    $code = (string) file_get_contents($php);
    preg_match_all("/\bconst\s+(\w+)\s*=\s*'([^'\\\\]*)'/", $code, $m, PREG_SET_ORDER);
    foreach ($m as [, $name, $value]) {
        $constants[$name][$value] = true;
    }
    if (!str_starts_with($php, 'front/') && !str_starts_with($php, 'ajax/')) {
        preg_match_all('/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $code, $m);
        foreach ($m[1] as $name) {
            $classes[$name] ??= $php;
        }
    }
}

/** Valeur chaîne d'une expression : littéral, ou constante de classe à valeur unique. */
$stringValue = static function (array $expr) use ($constants): ?string {
    if (count($expr) === 1 && $expr[0][0] === T_CONSTANT_ENCAPSED_STRING) {
        return unquote($expr[0][1]);
    }
    if (count($expr) === 3 && $expr[1][0] === T_DOUBLE_COLON && $expr[2][1] !== 'class') {
        $values = array_keys($constants[$expr[2][1]] ?? []);
        return count($values) === 1 ? $values[0] : null;
    }
    return null;
};

/** Classe désignée par X::class ou par une chaîne 'X' : nom tel qu'écrit, sans « \ » initial. */
$classOf = static function (array $expr): ?string {
    if (count($expr) === 3 && $expr[1][0] === T_DOUBLE_COLON && strtolower($expr[2][1]) === 'class') {
        return ltrim($expr[0][1], '\\');
    }
    if (count($expr) === 1 && $expr[0][0] === T_CONSTANT_ENCAPSED_STRING) {
        return ltrim(unquote($expr[0][1]), '\\');
    }
    return null;
};

// --- Exceptions -------------------------------------------------------------------------------
$exceptions = [];
if (is_file(EXCEPTIONS)) {
    foreach (file(EXCEPTIONS, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        [$id] = explode(' : ', $line, 2);
        $exceptions[trim($id)] = true;
    }
}

$problems = 0;
$excused = 0;
$level = $mode === 'error' ? 'error' : 'warning';
$report = static function (string $id, string $file, int $line, string $message) use (&$problems, &$excused, $exceptions, $level): void {
    if (isset($exceptions[$id])) {
        $excused++;
        echo "EXCEPTION  $id (justifiée dans " . EXCEPTIONS . ")\n";
        return;
    }
    $problems++;
    $where = $file === '' ? '' : " file=$file" . ($line > 0 ? ",line=$line" : '');
    echo "::$level$where::$message [$id]\n";
};

// --- Hooks de setup.php : clé, config_page, menu_toadd ----------------------------------------
if (!is_file('setup.php')) {
    fwrite(STDERR, "setup.php introuvable : ce dossier n'est pas un plugin GLPI\n");
    exit(2);
}
$setup = (string) file_get_contents('setup.php');
$key = preg_match('/function\s+plugin_version_(\w+)\s*\(/', $setup, $m) ? $m[1] : '';
$tk = tokens($setup);

// Noms importés par « use » (résolution de Menu::class en GlpiPlugin\X\Menu).
$uses = [];
$namespace = '';
for ($i = 0, $n = count($tk); $i < $n; $i++) {
    if ($tk[$i][0] === T_NAMESPACE) {
        $namespace = $tk[$i + 1][1] ?? '';
    }
    if ($tk[$i][0] === T_USE && in_array($tk[$i + 1][0] ?? 0, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
        $name = ltrim($tk[$i + 1][1] ?? '', '\\');
        $alias = ($tk[$i + 2][0] ?? 0) === T_AS ? $tk[$i + 3][1] : short($name);
        $uses[$alias] = $name;
    }
}
$resolve = static function (string $class) use ($uses, $namespace): string {
    $first = explode('\\', $class)[0];
    if (isset($uses[$first])) {
        return $uses[$first] . substr($class, strlen($first));
    }
    return $namespace !== '' && !str_contains($class, '\\') && !str_starts_with($class, 'Plugin') ? "$namespace\\$class" : $class;
};

/** Position de « $PLUGIN_HOOKS[<hook>]['<clé>'] » : liste [index après la clé, ligne]. */
$hookSites = static function (array $names) use ($tk): array {
    $sites = [];
    for ($i = 0, $n = count($tk); $i < $n - 6; $i++) {
        if ($tk[$i][1] !== '$PLUGIN_HOOKS' || $tk[$i + 1][1] !== '[') {
            continue;
        }
        $end = closing($tk, $i + 1);
        $hook = text(array_slice($tk, $i + 2, $end - $i - 2));
        if (!in_array($hook, $names, true) || ($tk[$end + 1][1] ?? '') !== '[') {
            continue;
        }
        $sites[] = [closing($tk, $end + 1) + 1, $tk[$i][2]];
    }
    return $sites;
};

/** Expression à droite de « = » à partir de $i : jetons jusqu'au « ; » de même niveau. */
$rhs = static function (int $i) use ($tk): array {
    $out = [];
    $depth = 0;
    for ($n = count($tk); $i < $n; $i++) {
        $c = $tk[$i][1];
        if ($c === ';' && $depth === 0) {
            break;
        }
        if (in_array($c, ['(', '[', '{'], true)) {
            $depth++;
        } elseif (in_array($c, [')', ']', '}'], true)) {
            $depth--;
        }
        $out[] = $tk[$i];
    }
    return $out;
};

$configPage = null;
foreach ($hookSites(["Hooks::CONFIG_PAGE", "'config_page'", '"config_page"']) as [$i]) {
    if (($tk[$i][1] ?? '') === '=') {
        $configPage = $stringValue($rhs($i + 1)) ?? $configPage;
    }
}
$configPage = $configPage !== null ? ltrim($configPage, '/') : null;

/** @var list<array{0:string,1:string,2:int}> $menu entrées [secteur, classe, ligne] */
$menu = [];
$addEntries = static function (string $sector, array $expr, int $line) use (&$menu, $classOf, $resolve): void {
    $first = strtolower($expr[0][1] ?? '');
    $items = $first === '[' ? splitTopLevel($expr, 0) : ($first === 'array' ? splitTopLevel($expr, 1) : [$expr]);
    foreach ($items as $item) {
        $class = $classOf($item);
        if ($item !== []) {
            $menu[] = [$sector, $class !== null ? $resolve($class) : text($item), $line];
        }
    }
};
$addSectors = static function (array $expr, int $line) use ($addEntries, $stringValue): void {
    if (($expr[0][1] ?? '') !== '[' && strtolower($expr[0][1] ?? '') !== 'array') {
        return;
    }
    $open = $expr[0][1] === '[' ? 0 : 1;
    foreach (splitTopLevel($expr, $open) as $element) {
        foreach ($element as $j => $t) {
            if ($t[0] === T_DOUBLE_ARROW) {
                $sector = $stringValue(array_slice($element, 0, $j));
                if ($sector !== null) {
                    $addEntries($sector, array_slice($element, $j + 1), $line);
                }
                break;
            }
        }
    }
};
foreach ($hookSites(['Hooks::MENU_TOADD', "'menu_toadd'", '"menu_toadd"']) as [$i, $line]) {
    if (($tk[$i][1] ?? '') === '[') {
        // $PLUGIN_HOOKS[...]['clé']['secteur'] = ...
        $end = closing($tk, $i);
        $sector = $stringValue(array_slice($tk, $i + 1, $end - $i - 1));
        if ($sector !== null && ($tk[$end + 1][1] ?? '') === '=') {
            $addEntries($sector, $rhs($end + 2), $line);
        }
        continue;
    }
    if (($tk[$i][1] ?? '') !== '=') {
        continue;
    }
    $expr = $rhs($i + 1);
    if (count($expr) === 1 && $expr[0][0] === T_VARIABLE) {
        // Tableau construit dans une variable : $menu = [...] ; $menu['secteur'] = [...].
        $var = $expr[0][1];
        for ($j = 0, $n = count($tk); $j < $n - 2; $j++) {
            if ($tk[$j][1] !== $var) {
                continue;
            }
            if ($tk[$j + 1][1] === '=') {
                $addSectors($rhs($j + 2), $tk[$j][2]);
            } elseif ($tk[$j + 1][1] === '[') {
                $end = closing($tk, $j + 1);
                $sector = $stringValue(array_slice($tk, $j + 2, $end - $j - 2));
                $next = $tk[$end + 1][1] ?? '';
                if ($sector !== null && $next === '=') {
                    $addEntries($sector, $rhs($end + 2), $tk[$j][2]);
                } elseif ($sector !== null && $next === '[' && ($tk[$end + 3][1] ?? '') === '=') {
                    $addEntries($sector, $rhs($end + 4), $tk[$j][2]);
                }
            }
        }
    } else {
        $addSectors($expr, $line);
    }
}

// --- 2. Menus : une entrée par secteur, pas de secteur config --------------------------------
$bySector = [];
foreach ($menu as [$sector, $class, $line]) {
    $bySector[$sector][] = [$class, $line];
}
$businessSector = isset($exceptions['menus-secteur-metier']);
foreach ($bySector as $sector => $entries) {
    $label = SECTOR_LABELS[$sector] ?? "secteur propre « $sector »";
    $counted = [];
    foreach ($entries as [$class, $line]) {
        $id = "menus-entree-native $sector > $class";
        if (isset($exceptions[$id]) || isset($exceptions["menus-entree-native $sector > " . short($class)])) {
            $excused++;
            echo "EXCEPTION  $id (justifiée dans " . EXCEPTIONS . ")\n";
            continue;
        }
        if ($sector === 'config') {
            $report($id, 'setup.php', $line, "menu_toadd : entrée « $class » dans le secteur Configuration. "
                . "Les réglages du plugin passent par sa page de configuration (\$PLUGIN_HOOKS[Hooks::CONFIG_PAGE], "
                . "Configuration > Plugins, en onglets), l'exploitation par le menu où un utilisateur la cherche "
                . "(Parc, Assistance, Gestion, Outils ou le menu propre du plugin). Charte, section 9 : " . CHARTER);
            continue;
        }
        $counted[] = [$class, $line];
    }
    if (count($counted) > 1 && !($businessSector && !in_array($sector, NATIVE_SECTORS, true))) {
        $names = implode(', ', array_column($counted, 0));
        $report("menus-secteur $sector", 'setup.php', $counted[1][1], 'menu_toadd : ' . count($counted)
            . " entrées dans $label ($names). Une seule entrée par secteur : les autres écrans deviennent "
            . "des onglets ou sous-pages de cette entrée. Outil métier complet dans son secteur propre : "
            . "« menus-secteur-metier : <raison> » ; entrée justifiée dans un secteur natif : « menus-entree-native "
            . "$sector > <classe> : <raison> » dans " . EXCEPTIONS . '. Charte, section 9 : ' . CHARTER);
    }
}
$declaredSectors = array_keys($bySector);
$menuSectorOf = [];
foreach ($menu as [$sector, $class]) {
    $menuSectorOf[short($class)][$sector] = true;
}

// --- 3. En-tête des pages front/ ---------------------------------------------------------------
$pages = [];   // fichier => ['displays' => bool, 'headers' => list<[secteur|null, classe|null, ligne]>, 'other' => bool]
foreach (files(['php'], 'front') as $front) {
    $code = (string) file_get_contents($front);
    $t = tokens($code);
    $info = ['displays' => false, 'standalone' => false, 'headers' => [], 'other' => false];
    for ($i = 0, $n = count($t); $i < $n; $i++) {
        $name = strtolower($t[$i][1]);
        $prev = $t[$i - 1][0] ?? 0;
        $call = ($t[$i + 1][1] ?? '') === '(';
        if (!$call) {
            if (in_array($t[$i][0], [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)
                && preg_match('#[\'"]/front/[\w.]+\.php[\'"]#', text($rhs($i)) ?: '')) {
                $info['other'] = true; // page du cœur incluse (dropdown.common.php...) : en-tête fourni
            }
            continue;
        }
        if ($prev === T_DOUBLE_COLON) {
            $owner = strtolower(ltrim($t[$i - 2][1] ?? '', '\\'));
            if ($owner === 'html' && $name === 'header') {
                $args = splitTopLevel($t, $i + 1);
                $info['headers'][] = [
                    isset($args[2]) ? $stringValue($args[2]) : null,
                    isset($args[3]) ? ($classOf($args[3]) ?? null) : null,
                    $t[$i][2],
                ];
            } elseif (in_array($name, ['header', 'helpheader', 'popheader', 'simpleheader', 'nullheader', 'displayfullpageforitem'], true)) {
                $info['other'] = true;
            } elseif (($owner === 'html' && in_array($name, ['footer', 'popfooter', 'helpfooter'], true))
                || ($owner === 'search' && in_array($name, ['show', 'showlist'], true))
                || $name === 'display' || $name === 'showform') {
                $info['displays'] = true;
            }
        } elseif ($prev === T_OBJECT_OPERATOR || $prev === T_NULLSAFE_OBJECT_OPERATOR) {
            if (in_array($name, ['display', 'showform'], true)) {
                $info['displays'] = true;
            }
        } elseif ($name === 'header' && $prev !== T_FUNCTION) {
            // header('Content-Type: ...') : téléchargement ou page autonome (portail public).
            $arg = $t[$i + 2][1] ?? '';
            if (preg_match('/^[\'"](Content-Type|Content-Disposition|Content-Security-Policy)\s*:/i', $arg)) {
                $info['standalone'] = true;
            }
        }
    }
    $pages[$front] = $info;
}

foreach ($pages as $front => $info) {
    if ($info['displays'] && !$info['standalone'] && $info['headers'] === [] && !$info['other']) {
        $report("page-entete $front", $front, 0, "la page affiche un écran GLPI sans appeler Html::header() : "
            . 'ni menu, ni fil d\'Ariane, ni secteur actif. Appeler Html::header(<titre>, <url>, <secteur>, '
            . '<classe du menu>, <onglet>) avant l\'affichage, avec le secteur et la classe de l\'entrée de menu '
            . 'où se trouve l\'écran (ou \'config\', \'plugin\' pour une page de réglages). Charte, section 9 : ' . CHARTER);
    }
    foreach ($info['headers'] as [$sector, $class, $line]) {
        if ($sector === null) {
            continue;
        }
        $expected = $class !== null ? array_keys($menuSectorOf[short($class)] ?? []) : [];
        if ($expected !== [] && !in_array($sector, $expected, true)) {
            $report("entete-secteur $front", $front, $line, "Html::header() range la page dans « "
                . (SECTOR_LABELS[$sector] ?? $sector) . " » ($sector), mais l'entrée de menu « $class » est déclarée "
                . 'dans ' . implode(', ', $expected) . ' (menu_toadd) : le fil d\'Ariane et le menu actif ne '
                . "correspondent pas à l'emplacement de l'écran. Emplacement attendu : Html::header(..., '"
                . $expected[0] . "', " . short($class) . '::class, ...). Charte, section 9 : ' . CHARTER);
        } elseif ($expected === [] && $sector !== 'config' && $declaredSectors !== [] && !in_array($sector, $declaredSectors, true)) {
            $report("entete-secteur $front", $front, $line, "Html::header() range la page dans « "
                . (SECTOR_LABELS[$sector] ?? $sector) . " » ($sector), où le plugin n'a aucune entrée de menu "
                . '(secteurs déclarés : ' . implode(', ', $declaredSectors) . ') : l\'utilisateur ne retrouve pas '
                . "l'écran par le menu affiché dans le fil d'Ariane. Emplacement attendu : le secteur de l'entrée "
                . "de menu dont l'écran est une sous-page, ou 'config' pour une page de réglages. Charte, section 9 : " . CHARTER);
        }
    }
}

// --- 1. Page de configuration -----------------------------------------------------------------
$configFiles = [];
$queue = [];
if ($configPage !== null && is_file($configPage)) {
    $queue[] = $configPage;
}
foreach ($pages as $front => $info) {
    foreach ($info['headers'] as [$sector, $class]) {
        if ($sector === 'config' && $class !== null && strtolower(short($class)) === 'plugin') {
            $queue[] = $front; // sous-page de Configuration > Plugins
        }
    }
}
$isConfigClass = static fn (string $class): bool => str_contains(short($class), 'Config');
while ($queue !== []) {
    $file = array_shift($queue);
    if (isset($configFiles[$file]) || !is_file($file)) {
        continue;
    }
    $configFiles[$file] = true;
    $code = (string) file_get_contents($file);
    preg_match_all('#[\'"]@' . preg_quote($key, '#') . '/([\w/.-]+\.twig)[\'"]#', $code, $m);
    foreach ($m[1] as $tpl) {
        $queue[] = "templates/$tpl";
    }
    if (str_ends_with($file, '.php')) {
        // Templates choisis par leur nom ('connection_actions' => templates/connection_actions.html.twig).
        preg_match_all("/'(\w+)'/", $code, $m);
        foreach (array_unique($m[1]) as $name) {
            if (is_file("templates/$name.html.twig")) {
                $queue[] = "templates/$name.html.twig";
            }
        }
        // Objet affiché par une sous-page de réglages (front/connection.form.php : new Connection()).
        if (str_starts_with($file, 'front/') && $file !== $configPage) {
            preg_match_all('/\bnew\s+\\\\?([\w\\\\]+)\s*\(/', $code, $m);
            foreach ($m[1] as $class) {
                if (isset($classes[short($class)])) {
                    $queue[] = $classes[short($class)];
                }
            }
        }
        preg_match_all('/([\w\\\\]*Config\w*)\s*::/', $code, $m);
        foreach ($m[1] as $class) {
            if (isset($classes[short($class)])) {
                $queue[] = $classes[short($class)];
            }
        }
    }
}
ksort($configFiles);

/**
 * Page de réglages : toutes ses en-têtes la rangent dans Configuration, sans classe d'une entrée de
 * menu (Configuration > Plugins, Configuration > Intitulés...).
 */
$isSettingsPage = static function (string $page) use ($pages, $menuSectorOf): bool {
    $headers = $pages[$page]['headers'] ?? [];
    foreach ($headers as [$sector, $class]) {
        if ($sector !== 'config' || ($class !== null && isset($menuSectorOf[short($class)]))) {
            return false;
        }
    }
    return $headers !== [];
};

/** Page front/ d'une adresse de classe ou de Menu::url('<page>'), et si elle est d'exploitation. */
$exploitationTarget = static function (string $class, string $method, ?string $arg) use ($classes, $pages, &$configFiles, $isConfigClass, $isSettingsPage): ?string {
    if ($isConfigClass($class) || !isset($classes[short($class)])) {
        return null; // réglage du plugin, ou classe du cœur (droits, notifications...)
    }
    $source = (string) file_get_contents($classes[short($class)]);
    if (preg_match('/extends\s+\\\\?(CommonDropdown|CommonTreeDropdown)\b/', $source)) {
        return null; // intitulé : un réglage (Configuration > Intitulés)
    }
    $page = match (strtolower($method)) {
        'getsearchurl' => 'front/' . strtolower(short($class)) . '.php',
        'getformurl', 'getformurlwithid' => 'front/' . strtolower(short($class)) . '.form.php',
        default => $arg !== null ? "front/$arg.php" : null,
    };
    if ($page === null) {
        // getPageURL(), getEolURL()... : la page que la méthode construit, ou une page de réglages.
        $body = methodBody($source, $method)[1] ?? '';
        if (preg_match('/\w*Config\w*\s*::/', $body)) {
            return null;
        }
        if (preg_match('#front/([\w/.-]+\.php)#', $body, $m)) {
            $page = "front/{$m[1]}";
        }
    }
    if ($page !== null && (isset($configFiles[$page]) || $isSettingsPage($page) || (isset($pages[$page]) && !$pages[$page]['displays']))) {
        return null; // page de réglages, ou simple traitement d'un formulaire
    }
    return $page !== null && isset($pages[$page]) ? $page : short($class) . "::$method()";
};

// Morceaux lus : les fichiers de la page de configuration, plus le corps des méthodes config*() d'une
// classe d'exploitation qu'ils appellent (EndOfLife::configData(), PersonnelCompliance::configData()...),
// qui fournissent au template des réglages et parfois l'adresse d'un écran d'usage.
$chunks = [];
foreach (array_keys($configFiles) as $file) {
    $raw = (string) file_get_contents($file);
    $chunks[] = [$file, 0, $raw, null];
    if (!str_ends_with($file, '.php')) {
        continue;
    }
    preg_match_all('/([\w\\\\]+)::(config\w*)\s*\(/', stripPhpComments($raw), $m, PREG_SET_ORDER);
    foreach ($m as [, $class, $method]) {
        $classFile = $classes[short($class)] ?? null;
        if ($classFile === null || isset($configFiles[$classFile]) || isset($chunks["$classFile::$method"])) {
            continue;
        }
        $body = methodBody((string) file_get_contents($classFile), $method);
        if ($body !== null) {
            $chunks["$classFile::$method"] = [$classFile, $body[0], '<?php ' . $body[1], short($class)];
        }
    }
}

foreach ($chunks as [$file, $offset, $raw, $context]) {
    $isTwig = str_ends_with($file, '.twig');
    $code = $isTwig ? stripTwigComments($raw) : stripPhpComments($raw);
    $lines = explode("\n", $code);
    $where = $file === $configPage ? 'la page de configuration' : ($context === null
        ? 'un fichier de la page de configuration' : 'une méthode config*() appelée par la page de configuration');

    // Boutons « ... maintenant ».
    // Le libellé entier est l'action (« Fetch now », « Importer maintenant ») : une phrase d'aide qui
    // cite « l'envoyer maintenant » n'est pas un bouton.
    $seen = false;
    foreach ($lines as $n => $line) {
        preg_match_all(LABELS, $line, $candidates, PREG_SET_ORDER);
        $label = null;
        foreach ($candidates as $c) {
            $candidate = stripslashes(trim(implode('', array_slice($c, 1))));
            if (preg_match(ACTION_NOW, $candidate)) {
                $label = $candidate;
                break;
            }
        }
        if ($label !== null && !$seen) {
            $seen = true;
            $report("config-action $file", $file, $offset + $n + 1, "« $label » dans $where : une lecture ou un envoi "
                . 'manuel est de l\'exploitation. Placer l\'action dans l\'écran d\'usage qui montre son résultat (menu '
                . 'du plugin, Parc, Assistance, Gestion ou onglet de la fiche) ; la configuration garde les réglages et '
                . '« Tester la connexion ». Charte, section 9 : ' . CHARTER);
        }
    }

    // Tableaux de résultats ou de registre.
    if ($isTwig) {
        $flagged = false;
        if (preg_match('#components/datatable\.html\.twig#', $code, $m, PREG_OFFSET_CAPTURE)) {
            $flagged = true;
            $report("config-tableau $file", $file, substr_count($code, "\n", 0, $m[0][1]) + 1, "liste de résultats "
                . "(components/datatable.html.twig) dans $where : un registre ou des résultats se consultent dans "
                . "l'écran d'usage, pas dans les réglages. Charte, section 9 : " . CHARTER);
        }
        preg_match_all('#<table\b.*?</table>#s', $code, $tables, PREG_OFFSET_CAPTURE);
        foreach ($tables[0] as [$table, $tableOffset]) {
            preg_match_all('/\{%-?\s*for\s+[\w\s,]+\s+in\s+([\w.]+)/', $table, $loops);
            foreach ($loops[1] as $collection) {
                // Mots du dernier nom (products_read, lastResults) : « catalogs » n'est pas « logs ».
                $last = (string) preg_replace('/^.*\./', '', $collection);
                $words = preg_split('/_|(?<=[a-z])(?=[A-Z])/', $last) ?: [];
                if (!$flagged && preg_grep(RESULT_WORDS, $words) !== []) {
                    $flagged = true;
                    $report("config-tableau $file", $file, substr_count($code, "\n", 0, $tableOffset) + 1, "tableau de "
                        . "« $collection » dans $where : un tableau de résultats ou de registre est de l'exploitation. "
                        . 'Le placer dans l\'écran d\'usage ; la configuration peut afficher la date et l\'état de la '
                        . 'dernière lecture, sans le détail. Charte, section 9 : ' . CHARTER);
                }
            }
        }
    }

    // Liens vers des pages d'exploitation.
    $targets = [];
    foreach ($lines as $n => $line) {
        if (preg_match('/redirect|Html::header|Html::back|action\s*=|[\'"]\w*action[\'"]\s*=>/i', $line)) {
            continue; // retour après un enregistrement, en-tête, cible d'un formulaire de réglage
        }
        if (preg_match_all('#front/([\w/.-]+\.php)#', $line, $m)) {
            foreach ($m[1] as $page) {
                $page = "front/$page";
                $displays = isset($pages[$page]) && $pages[$page]['displays'];
                if ($displays && !isset($configFiles[$page]) && !$isSettingsPage($page)) {
                    $targets[$page] ??= $offset + $n + 1;
                }
            }
        }
        if (!$isTwig && preg_match_all("/([\\\\\\w]+)::(get\\w*URL\\w*|url)\(\s*([^)]*)\)/", $line, $m, PREG_SET_ORDER)) {
            foreach ($m as [, $class, $method, $arg]) {
                $argValue = null;
                if (preg_match("/^'([\\w.-]+)'$/", trim($arg), $a)) {
                    $argValue = $a[1];
                } elseif (preg_match('/::(\w+)$/', trim($arg), $a)) {
                    $values = array_keys($constants[$a[1]] ?? []);
                    $argValue = count($values) === 1 ? $values[0] : null;
                }
                if (strtolower($method) === 'url' && $argValue === null) {
                    continue;
                }
                if (in_array(strtolower($class), ['self', 'static'], true) && $context !== null) {
                    $class = $context;
                }
                $target = $exploitationTarget($class, $method, $argValue);
                if ($target !== null) {
                    $targets[$target] ??= $offset + $n + 1;
                }
            }
        }
    }
    foreach ($targets as $target => $line) {
        $report("config-lien $file > $target", $file, $line, "lien vers $target dans $where : la page de "
            . 'configuration ne sert pas de point d\'entrée vers l\'exploitation. Retirer le lien (ou le remplacer par '
            . 'une phrase qui indique le menu), l\'écran étant joignable par son entrée de menu. Charte, section 9 : ' . CHARTER);
    }
}

// --- Bilan ------------------------------------------------------------------------------------
$summary = sprintf(
    '%d entrée(s) de menu dans %d secteur(s), %d page(s) front/, page de configuration %s (%d fichier(s) lus), %d exception(s) justifiée(s)',
    count($menu),
    count($bySector),
    count($pages),
    $configPage ?? 'non déclarée',
    count($configFiles),
    $excused
);
if ($problems > 0) {
    echo "$problems écart(s) d'ergonomie ($summary).\n";
    if ($mode === 'warning') {
        echo "Mode avertissement (navigation-mode: warning) : le job ne bloque pas, le temps de l'issue de navigation du plugin.\n";
        exit(0);
    }
    exit(1);
}
echo "Ergonomie : réglages, menus et en-têtes conformes ($summary).\n";
