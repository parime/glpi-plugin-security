<?php

// SPDX-License-Identifier: GPL-3.0-or-later

class PluginSecgoodThing extends CommonDBTM
{
    // Pas de propriété $rightname : typée en GLPI 12, pas en GLPI 11 (voir tests/fixtures/upgrade).
    public static function canView(): bool
    {
        return (bool) Session::haveRight('plugin_secgood', READ);
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_secgood_things';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        return Session::haveRight('plugin_secgood', READ) ? 'secgood' : '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!Session::haveRight('plugin_secgood', READ)) {
            return false;
        }
        echo '<p>Contenu réservé au plugin secgood</p>';

        return true;
    }
}
