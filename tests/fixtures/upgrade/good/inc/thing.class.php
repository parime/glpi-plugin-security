<?php

// SPDX-License-Identifier: GPL-3.0-or-later

class PluginSecupThing extends CommonDBTM
{
    public static $rightname = 'plugin_secup';

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
