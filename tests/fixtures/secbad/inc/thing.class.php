<?php

class PluginSecbadThing extends CommonDBTM
{
    public static $rightname = 'plugin_secbad';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_secbad_things';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        return Session::haveRight(self::$rightname, READ) ? 'secbad' : '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        echo '<p>Contenu réservé au plugin secbad</p>';

        return true;
    }
}
