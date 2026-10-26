<?php

class PluginSecgoodThing extends CommonDBTM
{
    public static $rightname = 'plugin_secgood';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_secgood_things';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        return Session::haveRight(self::$rightname, READ) ? 'secgood' : '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!Session::haveRight(self::$rightname, READ)) {
            return false;
        }
        echo '<p>Contenu réservé au plugin secgood</p>';

        return true;
    }
}
