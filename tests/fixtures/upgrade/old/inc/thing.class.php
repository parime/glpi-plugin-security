<?php

// SPDX-License-Identifier: GPL-3.0-or-later

class PluginSecupThing extends CommonDBTM
{
    // Pas de propriété $rightname : typée en GLPI 12, pas en GLPI 11, elle ne se redéclare pas
    // de la même façon dans les deux.
    public static function canView(): bool
    {
        return (bool) Session::haveRight('plugin_secup', READ);
    }

    public static function getTypeName($nb = 0)
    {
        return 'Secup thing';
    }

    public static function cronInfo($name)
    {
        return ['description' => 'Secup cleanup'];
    }

    public static function cronCleanup($task)
    {
        return 0;
    }
}
