<?php

class PluginSecbadThing extends CommonDBTM
{
    // Pas de propriété $rightname : typée en GLPI 12, pas en GLPI 11 (voir tests/fixtures/upgrade).
    public static function canView(): bool
    {
        return (bool) Session::haveRight('plugin_secbad', READ);
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_secbad_things';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        return Session::haveRight('plugin_secbad', READ) ? 'secbad' : '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        echo '<p>Contenu réservé au plugin secbad</p>';

        return true;
    }
}
