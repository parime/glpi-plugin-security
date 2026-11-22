<?php

/**
 * Assertions qui comparent un libellé traduit (issue #26) : la CI des plugins tourne en anglais,
 * l'instance de développement en français. Un test qui attend un libellé d'interface passe dans
 * une langue et échoue dans l'autre (au moins cinq cas, dont GRC PR #256 avec un titre construit
 * par sprintf(__())).
 *
 * Le contrôle lit les fichiers PHP de tests/ et signale chaque assertion
 * assertSame, assertEquals, assertNotSame, assertNotEquals, assertStringContainsString,
 * assertStringNotContainsString, assertStringStartsWith, assertStringEndsWith ou assertContains
 *   - dont un argument appelle une fonction de traduction : __(), _n(), _x(), _nx(), __s(), _sx(),
 *     _sn() (y compris dans sprintf(__(...))) ;
 *   - ou dont la valeur attendue (premier argument) contient un texte d'interface du plugin : un
 *     msgid de locales/ (anglais, avec ses %s / %d) ou une traduction française (fr_*) ou anglaise
 *     (en_*) de ce msgid.
 * Correction : comparer un identifiant, une clé, un code ou un nombre, pas un libellé ; ou forcer
 * la langue dans le test (et la restaurer) quand c'est le libellé qui est testé.
 *
 * Faux positif : ligne « translated-assertion <fichier>::<méthode> : raison » (une méthode de test)
 * ou « translated-assertion <fichier> : raison » (tout le fichier) dans .glpi-security-exceptions.
 *
 * Usage : php checks/check-translated-assertions.php <dossier du plugin> [error|warning]
 *   error (défaut) : code de sortie 1 si une assertion est signalée ; warning : annotations seules.
 */

declare(strict_types=1);

const ASSERTIONS = [
    'assertSame', 'assertEquals', 'assertNotSame', 'assertNotEquals',
    'assertStringContainsString', 'assertStringNotContainsString',
    'assertStringStartsWith', 'assertStringEndsWith', 'assertContains',
];
const TRANSLATION_FUNCTIONS = ['__', '_n', '_x', '_nx', '__s', '_sx', '_sn'];
const EXCEPTIONS = '.glpi-security-exceptions';
/** Un texte d'interface compte au moins ce nombre de lettres (« OK », « ID », « %s » ne comptent pas). */
const MIN_LETTERS = 4;

$dir = $argv[1] ?? '.';
$mode = $argv[2] ?? 'error';
if (!is_dir($dir) || !chdir($dir)) {
    fwrite(STDERR, "Dossier introuvable : $dir\n");
    exit(2);
}

/** @return list<string> */
function phpFiles(string $root): array
{
    if (!is_dir($root)) {
        return [];
    }
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if (strtolower($file->getExtension()) === 'php' && !preg_match('#/(vendor|node_modules)/#', $path)) {
            $files[] = $path;
        }
    }
    sort($files);
    return $files;
}

/**
 * Entrées d'un fichier .po / .pot : liste de [msgid, msgstr...] (chaînes décodées).
 *
 * @return list<array{0: string, 1: list<string>}>
 */
function poEntries(string $file): array
{
    $entries = [];
    $msgid = null;
    $msgstr = [];
    $current = null;
    $flush = static function () use (&$entries, &$msgid, &$msgstr): void {
        if ($msgid !== null && $msgid !== '') {
            $entries[] = [$msgid, array_values(array_filter($msgstr, static fn ($s) => $s !== ''))];
        }
        $msgid = null;
        $msgstr = [];
    };
    $decode = static fn (string $s): string => stripcslashes($s);
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = rtrim($line, "\r");
        if (preg_match('/^msgid\s+"(.*)"$/', $line, $m)) {
            $flush();
            $msgid = $decode($m[1]);
            $current = 'id';
        } elseif (preg_match('/^msgid_plural\s+"(.*)"$/', $line, $m)) {
            $current = 'plural';
        } elseif (preg_match('/^msgstr(?:\[(\d+)\])?\s+"(.*)"$/', $line, $m)) {
            $current = 'str' . ($m[1] ?? '');
            $msgstr[$current] = $decode($m[2]);
        } elseif (preg_match('/^"(.*)"$/', $line, $m)) {
            if ($current === 'id') {
                $msgid .= $decode($m[1]);
            } elseif ($current !== null && str_starts_with($current, 'str')) {
                $msgstr[$current] .= $decode($m[1]);
            }
        } elseif (trim($line) === '' || str_starts_with($line, 'msgctxt')) {
            if (trim($line) === '') {
                $flush();
                $current = null;
            }
        }
    }
    $flush();
    return $entries;
}

// Textes d'interface du plugin dont le libellé change avec la langue : pour chaque msgid, le texte
// français (traductions fr_*, ou le msgid sans traduction) et le texte anglais (en_*, ou le msgid).
// Un texte identique dans les deux langues (« Incident », « CIS Controls v8 ») ne dépend pas de la
// langue. Le msgid peut être anglais ou français (plugin écrit d'abord en français).
$translations = [];
foreach (array_merge(glob('locales/*.pot') ?: [], glob('locales/*.po') ?: []) as $po) {
    $lang = basename(basename($po, '.po'), '.pot');
    $family = preg_match('/^(fr|en)(_[A-Z]{2})?$/', $lang, $m) ? $m[1] : '';
    foreach (poEntries($po) as [$msgid, $msgstrs]) {
        $translations[$msgid] ??= ['fr' => [], 'en' => []];
        if ($family !== '') {
            array_push($translations[$msgid][$family], ...$msgstrs);
        }
    }
}
$texts = [];
foreach ($translations as $msgid => $byFamily) {
    $msgid = (string) $msgid;
    $french = array_unique($byFamily['fr'] ?: [$msgid]);
    $english = array_unique($byFamily['en'] ?: [$msgid]);
    foreach (array_diff($french, $english) as $text) {
        $texts[$text] = true;
    }
    foreach (array_diff($english, $french) as $text) {
        // Un mot anglais écrit comme un identifiant (« critical », « overdue », « Computer ») sert
        // aussi de clé, de statut ou de nom de classe dans les tests : non retenu.
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $text)) {
            $texts[$text] = true;
        }
    }
}
$exact = [];
$patterns = [];
foreach (array_keys($texts) as $text) {
    $text = (string) $text;
    $letters = preg_replace('/%(\d+\$)?[-+ 0#]*\d*(\.\d+)?[sdfuxXbceEgG]|[^\p{L}]/u', '', $text);
    if ($letters === null || mb_strlen($letters) < MIN_LETTERS) {
        continue;
    }
    if (preg_match('/%(\d+\$)?[-+ 0#]*\d*(\.\d+)?[sdfuxXbceEgG]/', $text)) {
        $parts = preg_split('/%(?:\d+\$)?[-+ 0#]*\d*(?:\.\d+)?[sdfuxXbceEgG]/', str_replace('%%', '%', $text));
        // Partie fixe la plus longue : filtre rapide (strpos) avant l'expression régulière.
        $longest = array_reduce($parts, static fn ($a, $p) => strlen($p) > strlen($a) ? $p : $a, '');
        $patterns[$text] = [$longest, '/^' . implode('.+', array_map(static fn ($p) => preg_quote($p, '/'), $parts)) . '$/su'];
    } else {
        $exact[$text] = true;
    }
}

$exceptions = [];
// Fichier d'exceptions et ses fragments (EXCEPTIONS.d/*, par ordre alphabétique), même syntaxe.
$exceptionLines = [];
foreach (array_merge([EXCEPTIONS], glob(EXCEPTIONS . '.d/*') ?: []) as $exceptionFile) {
    if (is_file($exceptionFile)) {
        array_push($exceptionLines, ...(file($exceptionFile, FILE_IGNORE_NEW_LINES) ?: []));
    }
}
foreach ($exceptionLines as $line) {
    if (preg_match('/^\s*translated-assertion\s+(\S+)\s*:\s*\S/', $line, $m)) {
        $exceptions[$m[1]] = true;
    }
}

/** Valeur d'une chaîne PHP littérale (apostrophes ou guillemets sans variable). */
function literalValue(string $token): string
{
    $quote = $token[0];
    $body = substr($token, 1, -1);
    return $quote === "'" ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body) : stripcslashes($body);
}

/**
 * Chaînes littérales qui forment la valeur attendue : l'argument entier ('Libellé'), ou un élément
 * d'un tableau écrit tel quel (['Libellé', 'Autre']). Une chaîne passée à une fonction
 * (strtotime('+15 days')) ou concaténée à une variable ($nom . ' Pilote') n'est pas un libellé
 * attendu.
 *
 * @return list<string>
 */
function expectedLiterals(array $arg): array
{
    $isLiteral = static fn ($t) => is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING;
    if (count($arg) === 1) {
        return $isLiteral($arg[0]) ? [literalValue($arg[0][1])] : [];
    }
    if (($arg[0] ?? null) !== '[' || end($arg) !== ']') {
        return [];
    }
    $separator = static fn ($t) => in_array($t, ['[', ']', ','], true) || (is_array($t) && $t[0] === T_DOUBLE_ARROW);
    $values = [];
    $depth = 0;
    foreach ($arg as $k => $t) {
        if (in_array($t, ['[', '('], true)) {
            $depth++;
        } elseif (in_array($t, [']', ')'], true)) {
            $depth--;
        } elseif ($depth === 1 && $isLiteral($t) && $separator($arg[$k - 1] ?? null) && $separator($arg[$k + 1] ?? null)) {
            $values[] = literalValue($t[1]);
        }
    }
    return $values;
}

/** Texte d'interface reconnu dans $value, ou null. */
function uiText(string $value, array $exact, array $patterns): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (isset($exact[$value])) {
        return $value;
    }
    foreach ($patterns as $text => [$longest, $regex]) {
        if (str_contains($value, $longest) && preg_match($regex, $value)) {
            return (string) $text;
        }
    }
    return null;
}

$problems = 0;
$excepted = 0;
$files = phpFiles('tests');
foreach ($files as $path) {
    $tokens = token_get_all((string) file_get_contents($path));
    $tokens = array_values(array_filter(
        $tokens,
        static fn ($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
    ));
    $count = count($tokens);
    $method = '';
    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) {
            continue;
        }
        if ($t[0] === T_FUNCTION && isset($tokens[$i + 1]) && is_array($tokens[$i + 1]) && $tokens[$i + 1][0] === T_STRING) {
            $method = $tokens[$i + 1][1];
            continue;
        }
        if ($t[0] !== T_STRING || !in_array($t[1], ASSERTIONS, true) || ($tokens[$i + 1] ?? null) !== '(') {
            continue;
        }
        $prev = $tokens[$i - 1] ?? null;
        if (is_array($prev) && $prev[0] === T_FUNCTION) {
            continue;
        }
        $line = $t[2];
        // Arguments de l'assertion, séparés par les virgules de premier niveau.
        $args = [[]];
        $depth = 0;
        for ($j = $i + 1; $j < $count; $j++) {
            $tok = $tokens[$j];
            $text = is_array($tok) ? $tok[1] : $tok;
            if (in_array($text, ['(', '[', '{'], true) || (is_array($tok) && in_array($tok[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($text === ',' && $depth === 1) {
                $args[] = [];
                continue;
            }
            $args[count($args) - 1][] = $tok;
        }
        $reasons = [];
        foreach ($args as $n => $arg) {
            foreach ($arg as $k => $tok) {
                if (
                    is_array($tok) && $tok[0] === T_STRING && in_array($tok[1], TRANSLATION_FUNCTIONS, true)
                    && ($arg[$k + 1] ?? null) === '('
                    && !(is_array($arg[$k - 1] ?? null) && in_array($arg[$k - 1][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true))
                ) {
                    $reasons[] = 'l\'argument ' . ($n + 1) . ' appelle ' . $tok[1] . '()';
                    break;
                }
            }
        }
        foreach (expectedLiterals($args[0]) as $value) {
            $found = uiText($value, $exact, $patterns);
            if ($found !== null) {
                $reasons[] = "la valeur attendue est le texte d'interface « " . $found . ' »';
                break;
            }
        }
        if ($reasons === []) {
            continue;
        }
        if (isset($exceptions["$path::$method"]) || isset($exceptions[$path])) {
            $excepted++;
            continue;
        }
        $problems++;
        $level = $mode === 'warning' ? 'warning' : 'error';
        echo "::$level file=$path,line=$line::$t[1]() compare un libellé traduit ("
            . implode(' ; ', array_unique($reasons)) . ") : le test dépend de la langue (CI en anglais, "
            . "instance de développement en français). Comparer un identifiant, une clé ou un code, ou "
            . "forcer puis restaurer la langue dans le test. Exception justifiée : "
            . "« translated-assertion $path::$method : raison » dans " . EXCEPTIONS . ".\n";
    }
}

$summary = count($files) . ' fichier(s) de tests lus, ' . (count($exact) + count($patterns))
    . " texte(s) d'interface connus, $excepted exception(s) justifiée(s)";
if ($problems > 0) {
    echo "$problems assertion(s) comparant un libellé traduit ($summary).\n";
    exit($mode === 'warning' ? 0 : 1);
}
echo "Aucune assertion ne compare un libellé traduit ($summary).\n";
