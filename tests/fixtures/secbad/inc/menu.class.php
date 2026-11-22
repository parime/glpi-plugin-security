<?php

// SPDX-License-Identifier: GPL-3.0-or-later

// Défaut volontaire d'ergonomie (issue #34) : une rubrique de 9 sous-pages (au plus 7).
class PluginSecbadMenu extends CommonGLPI
{
    public static function sections(): array
    {
        return [
            PluginSecbadThing::class => [
                'PluginSecbadOne', 'PluginSecbadTwo', 'PluginSecbadThree', 'PluginSecbadFour',
                'PluginSecbadFive', 'PluginSecbadSix', 'PluginSecbadSeven', 'PluginSecbadEight',
            ],
        ];
    }
}
