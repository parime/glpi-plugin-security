<?php

// SPDX-License-Identifier: GPL-3.0-or-later

// Version correcte (issue #34) : module activable, son onglet n'existe que s'il est activé.
class PluginSecgoodModule extends CommonGLPI
{
    public static function isEnabled(): bool
    {
        return (bool) (Config::getConfigurationValues('plugin:secgood')['module'] ?? false);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        return self::isEnabled() ? 'Module' : '';
    }
}
