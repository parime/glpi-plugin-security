<?php

// SPDX-License-Identifier: GPL-3.0-or-later

// Version correcte : registre d'un référentiel activable qui n'a jamais d'entrée de menu (ouvert
// depuis une autre page) ; getMenuContent() renvoie toujours false, rien à masquer.
class PluginSecgoodFramework extends CommonDBTM
{
    public static function isFrameworkEnabled(): bool
    {
        return (bool) (Config::getConfigurationValues('plugin:secgood')['framework'] ?? false);
    }

    /** Aucune entrée de menu. */
    public static function getMenuContent()
    {
        // Ouvert depuis la page des référentiels.
        return false;
    }
}
