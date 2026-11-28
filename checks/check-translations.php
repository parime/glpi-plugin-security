<?php

/**
 * Contrôle des traductions d'un plugin GLPI : une traduction ne doit rien ajouter d'actif au texte
 * source. Les chaînes traduites sont souvent affichées sans échappement (message de session, texte
 * d'aide, contenu de notification) : une traduction, qu'elle vienne d'un contributeur, d'une
 * plateforme de traduction ou d'un outil automatique, ne doit donc pas pouvoir injecter de balise
 * HTML, d'URL, de JavaScript, d'entité HTML ou de balise de notification GLPI (##…##) qui ne
 * figure pas déjà dans le msgid.
 *
 * Les fragments de traduction <dossier locales>/unreleased/*.po sont contrôlés comme les catalogues.
 *
 * Usage : php check-translations.php <dossier locales>   (code de sortie 1 si un écart est trouvé)
 */

declare(strict_types=1);

$dir = $argv[1] ?? 'locales';
if (!is_dir($dir)) {
    echo "Aucun dossier $dir : rien à contrôler.\n";
    exit(0);
}

/**
 * Lecture minimale d'un catalogue .po.
 *
 * @return list<array{id: string, plural: string, str: list<string>, line: int}>
 */
function readPo(string $file): array
{
    $entries = [];
    $cur = null;
    $last = null;
    $unquote = static fn (string $s): string => stripcslashes(substr($s, 1, -1));
    foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $n => $line) {
        if (trim($line) === '' || ($line[0] === '#')) {
            if ($cur !== null && trim($line) === '') {
                $entries[] = $cur;
                $cur = null;
            }
            continue;
        }
        $cur ??= ['id' => '', 'plural' => '', 'str' => [], 'line' => $n + 1];
        if (preg_match('/^msgctxt (".*")$/', $line)) {
            $last = null;
        } elseif (preg_match('/^msgid (".*")$/', $line, $m)) {
            $cur['id'] = $unquote($m[1]);
            $last = ['id'];
        } elseif (preg_match('/^msgid_plural (".*")$/', $line, $m)) {
            $cur['plural'] = $unquote($m[1]);
            $last = ['plural'];
        } elseif (preg_match('/^msgstr(?:\[(\d+)\])? (".*")$/', $line, $m)) {
            $i = $m[1] === '' ? 0 : (int) $m[1];
            $cur['str'][$i] = $unquote($m[2]);
            $last = ['str', $i];
        } elseif (preg_match('/^(".*")$/', $line, $m) && $last !== null) {
            if ($last[0] === 'str') {
                $cur['str'][$last[1]] .= $unquote($m[1]);
            } else {
                $cur[$last[0]] .= $unquote($m[1]);
            }
        }
    }
    if ($cur !== null) {
        $entries[] = $cur;
    }

    return array_values(array_filter($entries, static fn (array $e): bool => $e['id'] !== ''));
}

const PATTERNS = [
    'balise HTML'          => '/<[a-z\/!][^>]*>/i',
    'URL'                  => '#\b(?:https?|ftp)://[^\s"\'<>]+|\bwww\.[^\s"\'<>]+#i',
    'JavaScript'           => '/javascript\s*:|\bon[a-z]+\s*=/i',
    'entité HTML'          => '/&(?:#\d+|#x[0-9a-f]+|[a-z]+);/i',
    'balise de notification' => '/##[a-z0-9._]+##/i',
];

$issues = 0;
// Fragments de traduction (locales/unreleased/*.po, une PR par fichier) contrôlés aussi : sans cela,
// une traduction fautive passe la CI de sa PR et ne bloque qu'à la publication, une fois le fragment
// intégré au catalogue (cas réel : « ATT&CK; » lu comme l'entité &CK;, GRC 2.88.0).
$files = array_merge(
    glob(rtrim($dir, '/') . '/*.po') ?: [],
    glob(rtrim($dir, '/') . '/unreleased/*.po') ?: []
);
foreach ($files as $file) {
    foreach (readPo($file) as $entry) {
        $source = $entry['id'] . "\n" . $entry['plural'];
        foreach ($entry['str'] as $translation) {
            foreach (PATTERNS as $label => $pattern) {
                preg_match_all($pattern, $source, $allowed);
                preg_match_all($pattern, $translation, $found);
                $extra = array_values(array_diff($found[0], $allowed[0]));
                if ($extra !== []) {
                    $issues++;
                    printf(
                        "::error file=%s,line=%d::%s absente du texte source dans une traduction : %s\n",
                        $file,
                        $entry['line'],
                        $label,
                        implode(', ', array_unique($extra))
                    );
                }
            }
        }
    }
}

printf("%d catalogue(s) contrôlé(s), %d écart(s).\n", count($files), $issues);
exit($issues > 0 ? 1 : 0);
