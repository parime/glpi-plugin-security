<?php

// SPDX-License-Identifier: GPL-3.0-or-later

// Jeu de données de la version 1.0.0 (lancé depuis la racine de GLPI, en www-data).
require 'vendor/autoload.php';
$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

global $DB;
for ($i = 1; $i <= 3; $i++) {
    $DB->insert('glpi_plugin_secup_things', ['name' => "thing {$i}"]);
    $DB->insert('glpi_plugin_secup_logs', ['message' => "log {$i}"]);
}
echo "{}
";
