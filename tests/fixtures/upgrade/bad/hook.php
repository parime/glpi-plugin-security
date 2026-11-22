<?php

// SPDX-License-Identifier: GPL-3.0-or-later

function plugin_secup_install(): bool
{
    global $DB;

    if (!$DB->tableExists('glpi_plugin_secup_things')) {
        $DB->doQuery('CREATE TABLE glpi_plugin_secup_things (id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, name varchar(255) DEFAULT NULL, comment text, KEY name (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
    if ($DB->tableExists('glpi_plugin_secup_logs')) {
        // Défaut : migration qui reconstruit la table au lieu de la modifier.
        $DB->doQuery('DROP TABLE glpi_plugin_secup_logs');
        // Défaut : tâche, notification et droits supprimés pour être « recréés ».
        CronTask::unregister('secup');
        (new Notification())->deleteByCriteria(['itemtype' => PluginSecupThing::class]);
        ProfileRight::deleteProfileRights(['plugin_secup']);
        // Défaut : avertissement PHP pendant la mise à jour.
        trigger_error('secup : avertissement pendant la mise à jour', E_USER_WARNING);
        $DB->doQuery('CREATE TABLE glpi_plugin_secup_logs (id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, message text) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        ProfileRight::addProfileRights(['plugin_secup']);
        return true;
    }
    if (!$DB->tableExists('glpi_plugin_secup_logs')) {
        $DB->doQuery('CREATE TABLE glpi_plugin_secup_logs (id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, message text) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
    CronTask::register(PluginSecupThing::class, 'cleanup', DAY_TIMESTAMP, ['state' => CronTask::STATE_WAITING]);
    if (countElementsInTable('glpi_notifications', ['itemtype' => PluginSecupThing::class]) === 0) {
        (new Notification())->add(['name' => 'Secup new thing', 'itemtype' => PluginSecupThing::class, 'event' => 'new', 'is_active' => 1, 'entities_id' => 0]);
    }
    if (countElementsInTable('glpi_profilerights', ['name' => 'plugin_secup']) === 0) {
        ProfileRight::addProfileRights(['plugin_secup']);
        ProfileRight::updateProfileRights(4, ['plugin_secup' => ALLSTANDARDRIGHT]);
    }

    return true;
}

function plugin_secup_uninstall(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_SECUP_VERSION);
    ProfileRight::deleteProfileRights(['plugin_secup']);
    CronTask::unregister('secup');
    (new Notification())->deleteByCriteria(['itemtype' => PluginSecupThing::class]);
    $migration->dropTable('glpi_plugin_secup_things');
    $migration->dropTable('glpi_plugin_secup_logs');
    $migration->executeMigration();

    return true;
}
