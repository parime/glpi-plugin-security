<?php

// SPDX-License-Identifier: GPL-3.0-or-later

// Version correcte (issue #34) : une rubrique de deux écrans (au plus 7).
class PluginSecgoodMenu extends CommonGLPI
{
    public static function sections(): array
    {
        return [
            PluginSecgoodThing::class => ['PluginSecgoodOther'],
        ];
    }
}
