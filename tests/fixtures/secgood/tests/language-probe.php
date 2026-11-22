<?php

// Sonde de la passe en langue (issue #26) : après le démarrage de GLPI comme dans un bootstrap
// d'intégration, la langue de la session, de l'instance et de l'utilisateur glpi doit être celle
// demandée (GLPI_TEST_LANGUAGE), et __() doit traduire dans cette langue.
require getenv('GLPI_ROOT_DIR') . '/vendor/autoload.php';
$kernel = new \Glpi\Kernel\Kernel('production');
$GLOBALS['kernel'] = $kernel;
$kernel->boot();

global $CFG_GLPI, $DB;
$expected = (string) getenv('GLPI_TEST_LANGUAGE');
$user = $DB->request(['SELECT' => 'language', 'FROM' => 'glpi_users', 'WHERE' => ['name' => 'glpi']])->current();
$seen = [
    'session' => $_SESSION['glpilanguage'] ?? '',
    'instance' => $CFG_GLPI['language'] ?? '',
    'utilisateur glpi' => $user['language'] ?? '',
];
$failed = false;
foreach ($seen as $what => $language) {
    echo "$what : $language\n";
    $failed = $failed || $language !== $expected;
}
$save = __('Save');
echo "__('Save') : $save\n";
if (str_starts_with($expected, 'fr_') && $save === 'Save') {
    echo "::error::__() ne traduit pas en $expected\n";
    $failed = true;
}
exit($failed ? 1 : 0);
