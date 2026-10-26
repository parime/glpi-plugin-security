<?php

function plugin_secgood_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_SECGOOD_VERSION);
    if (!$DB->tableExists('glpi_plugin_secgood_things')) {
        $DB->doQuery('CREATE TABLE glpi_plugin_secgood_things (id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, name varchar(255))');
    }
    if (countElementsInTable('glpi_profilerights', ['name' => 'plugin_secgood']) === 0) {
        ProfileRight::addProfileRights(['plugin_secgood']);
    }
    // Donnée du plugin dans une table du cœur (comme un suivi ou une note sur ses objets).
    $DB->insert('glpi_notepads', ['itemtype' => 'PluginSecgoodThing', 'items_id' => 1, 'content' => 'note']);

    return true;
}

function plugin_secgood_uninstall(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_SECGOOD_VERSION);
    ProfileRight::deleteProfileRights(["plugin_secgood"]);
    $DB->delete("glpi_notepads", ["itemtype" => ["LIKE", "PluginSecgood%"]]);
    $migration->dropTable("glpi_plugin_secgood_things");
    $migration->executeMigration();

    return true;
}
