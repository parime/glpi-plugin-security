<?php

/**
 * Classes de couleur des badges (charte section 8, parime/glpi-plugin-template#10, issue #20) :
 * GLPI et Tabler ne fournissent les classes Bootstrap 5.3 bg-X-subtle, text-X-emphasis et
 * border-X-subtle que pour les couleurs de thème (primary, secondary, success, info, warning,
 * danger, light, dark). Avec une couleur nommée de Tabler (orange, blue, green...) que le plugin ne
 * définit pas, le badge s'affiche sans fond.
 *
 * Le contrôle échoue quand un template Twig, un fichier PHP ou JS du plugin utilise une telle classe
 * sans qu'elle soit une couleur de thème ni définie dans une feuille CSS du plugin (public/css/...) :
 *   - écrite telle quelle : bg-orange-subtle ;
 *   - construite dans Twig : bg-{{ info.color }}-subtle, avec les couleurs citées entre apostrophes
 *     dans l'expression ({{ ok ? 'green' : 'red' }}) ;
 *   - fournie par le PHP : 'color' => 'orange', 'tone' => 'red', 'badge_color' => ...
 * Une couleur traduite par une table Twig vers une couleur définie ({ indigo: 'info' }, comme la
 * macro _tone.html.twig de Docker Inventory) est considérée comme traitée.
 * Même logique que tests/Unit/SubtleColorClassesTest.php du modèle, sans PHPUnit.
 *
 * Fichiers lus : ceux du dossier du plugin, hors vendor, node_modules, tests, lib, build, dist et
 * dossiers cachés. Faux positif : ligne « subtle-color <classe ou couleur> : raison » dans
 * .glpi-security-exceptions.
 *
 * Usage : php checks/check-subtle-colors.php <dossier du plugin>   (code de sortie 1 si un écart)
 */

declare(strict_types=1);

const THEME_COLORS = ['primary', 'secondary', 'success', 'info', 'warning', 'danger', 'light', 'dark'];
const EXCLUDED = '#^(\.|(vendor|node_modules|tests|lib|build|dist)/)#';
const EXCEPTIONS = '.glpi-security-exceptions';
const TEMPLATE_CSS = 'https://github.com/parime/glpi-plugin-template/blob/main/public/css/monplugin.css';

/** Couleur de thème la plus proche d'une couleur nommée, pour la correction proposée. */
const NEAREST_THEME = [
    'blue' => 'primary', 'azure' => 'info', 'indigo' => 'primary', 'purple' => 'primary',
    'pink' => 'danger', 'red' => 'danger', 'orange' => 'warning', 'yellow' => 'warning',
    'lime' => 'success', 'green' => 'success', 'teal' => 'success', 'cyan' => 'info',
    'gray' => 'secondary', 'grey' => 'secondary', 'muted' => 'secondary', 'black' => 'dark',
    'white' => 'light',
];

$dir = $argv[1] ?? '.';
if (!is_dir($dir) || !chdir($dir)) {
    fwrite(STDERR, "Dossier introuvable : $dir\n");
    exit(2);
}

/** @return list<string> fichiers du plugin dont l'extension est dans $extensions */
function files(array $extensions): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = substr(str_replace('\\', '/', $file->getPathname()), 2);
        if (in_array(strtolower($file->getExtension()), $extensions, true) && !preg_match(EXCLUDED, $path)) {
            $files[] = $path;
        }
    }
    sort($files);
    return $files;
}

function lineOf(string $contents, int $offset): int
{
    return substr_count($contents, "\n", 0, $offset) + 1;
}

// Classes connues : couleurs de thème de Tabler, plus celles des feuilles CSS du plugin.
$defined = ['text-body-emphasis' => 'Tabler'];
foreach (THEME_COLORS as $color) {
    foreach (["bg-$color-subtle", "text-$color-emphasis", "border-$color-subtle"] as $class) {
        $defined[$class] = 'Tabler';
    }
}
$stylesheets = files(['css']);
foreach ($stylesheets as $css) {
    $contents = (string) file_get_contents($css);
    $contents = (string) preg_replace('#/\*.*?\*/#s', '', $contents);
    preg_match_all('/\.((?:bg|text|border)-[a-z]+-(?:subtle|emphasis))(?![\w-])/', $contents, $m);
    foreach ($m[1] as $class) {
        $defined[$class] ??= $css;
    }
}

// Couleurs traduites par une table Twig ({ indigo: 'info', orange: 'orange' }) vers une couleur
// définie : la classe construite porte la couleur traduite, pas le nom d'origine.
$mapped = [];
foreach (files(['twig']) as $twig) {
    preg_match_all("/(?<![\w.-])([a-z]+)\s*:\s*'([a-z]+)'/", (string) file_get_contents($twig), $m, PREG_SET_ORDER);
    foreach ($m as [, $name, $target]) {
        if (isset($defined["bg-$target-subtle"], $defined["text-$target-emphasis"])) {
            $mapped[$name] = true;
        }
    }
}

$exceptions = [];
if (is_file(EXCEPTIONS)) {
    foreach (file(EXCEPTIONS, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\s*subtle-color\s+([\w-]+)\s*:\s*\S/', $line, $m)) {
            $exceptions[$m[1]] = true;
        }
    }
}

$sheet = 'public/css/<clé du plugin>.css';
$hint = static function (string $color) use ($sheet): string {
    $theme = NEAREST_THEME[$color] ?? 'secondary';
    return "Correction : utiliser une couleur de thème (bg-$theme-subtle text-$theme-emphasis), ou définir "
        . "bg-$color-subtle, text-$color-emphasis et border-$color-subtle dans $sheet chargée par "
        . "\$PLUGIN_HOOKS[Hooks::ADD_CSS] (feuille commune du modèle : " . TEMPLATE_CSS . ').';
};

$problems = 0;
$report = static function (string $file, int $line, string $message) use (&$problems): void {
    $problems++;
    echo "::error file=$file,line=$line::$message\n";
};

$literal = '/(?<![\w-])((?:bg|text|border)-([a-z]+)-(?:subtle|emphasis))(?![\w-])/';
$dynamic = '/(?:bg|text|border)-\{\{((?:(?!\}\}|\{\{).)*)\}\}-(?:subtle|emphasis)/s';
$comparison = '/(?:==|!=|\bin)\s*\'[a-z]+\'|\'[a-z]+\'\s*(?:==|!=)/';
$phpColor = "/'[a-z_]*(?:color|tone)' *=> *'([a-z]+)'/";

$sources = files(['twig', 'php', 'js']);
foreach ($sources as $path) {
    $contents = (string) file_get_contents($path);

    // 1. Classe écrite telle quelle.
    preg_match_all($literal, $contents, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
    foreach ($m as $match) {
        [$class, $offset] = $match[1];
        if (isset($defined[$class]) || isset($exceptions[$class])) {
            continue;
        }
        $report($path, lineOf($contents, $offset), "la classe $class n'est définie ni par Tabler (couleurs de thème : "
            . implode(', ', THEME_COLORS) . ') ni par une feuille CSS du plugin : le badge s\'affiche sans fond. '
            . $hint($match[2][0]));
    }

    // 2. Couleurs qui alimentent une classe construite (Twig) ou fournies par le PHP.
    $colors = [];
    preg_match_all($dynamic, $contents, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
    foreach ($m as $match) {
        [$expression, $offset] = $match[1];
        // Les opérandes d'une comparaison (line.decision == 'remove') ne sont pas des couleurs.
        $expression = (string) preg_replace($comparison, '', $expression);
        preg_match_all("/'([a-z]+)'/", $expression, $literals);
        foreach ($literals[1] as $color) {
            $colors[] = [$color, $offset];
        }
    }
    if (str_ends_with($path, '.php')) {
        preg_match_all($phpColor, $contents, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($m as $match) {
            $colors[] = [$match[1][0], $match[1][1]];
        }
    }
    $seen = [];
    foreach ($colors as [$color, $offset]) {
        $line = lineOf($contents, $offset);
        if (
            isset($seen["$color:$line"]) || isset($exceptions[$color]) || isset($mapped[$color])
            || (isset($defined["bg-$color-subtle"]) && isset($defined["text-$color-emphasis"]))
        ) {
            continue;
        }
        $seen["$color:$line"] = true;
        $report($path, $line, "la couleur « $color » sert à construire bg-$color-subtle / "
            . "text-$color-emphasis, qui ne sont définies ni par Tabler ni par une feuille CSS "
            . 'du plugin : le badge s\'affiche sans fond. ' . $hint($color));
    }
}

$checked = count($sources);
if ($problems > 0) {
    echo "$problems classe(s) de couleur subtle/emphasis non définie(s) ($checked fichier(s) lus).\n";
    exit(1);
}
$own = count(array_filter($defined, static fn (string $source): bool => $source !== 'Tabler'));
echo "Classes subtle/emphasis : toutes définies ($checked fichier(s) lus, $own classe(s) dans "
    . count($stylesheets) . " feuille(s) CSS du plugin).\n";
