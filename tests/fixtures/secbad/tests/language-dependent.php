<?php

// Défaut volontaire (issue #26) : test qui attend le libellé anglais. Il passe en anglais et doit
// échouer dans la passe en français.
require getenv('GLPI_ROOT_DIR') . '/vendor/autoload.php';
$kernel = new \Glpi\Kernel\Kernel('production');
$GLOBALS['kernel'] = $kernel;
$kernel->boot();

$save = __('Save');
echo "__('Save') : $save\n";
exit($save === 'Save' ? 0 : 1);
