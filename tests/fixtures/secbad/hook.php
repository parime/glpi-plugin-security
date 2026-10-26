<?php

function plugin_secbad_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_SECBAD_VERSION);
    if (!$DB->tableExists('glpi_plugin_secbad_things')) {
        $DB->doQuery('CREATE TABLE glpi_plugin_secbad_things (id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, name varchar(255))');
    }
    if (countElementsInTable('glpi_profilerights', ['name' => 'plugin_secbad']) === 0) {
        ProfileRight::addProfileRights(['plugin_secbad']);
    }
    // Donnée du plugin dans une table du cœur (comme un suivi ou une note sur ses objets).
    $DB->insert('glpi_notepads', ['itemtype' => 'PluginSecbadThing', 'items_id' => 1, 'content' => 'note']);

    return true;
}

function plugin_secbad_uninstall(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_SECBAD_VERSION);
    $migration->dropTable("glpi_plugin_secbad_things");
    $migration->executeMigration();

    return true;
}
