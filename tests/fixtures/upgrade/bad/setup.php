<?php

// SPDX-License-Identifier: GPL-3.0-or-later

// Plugin de test du contrôle de mise à jour (check-upgrade.sh), version 1.1.0.
define('PLUGIN_SECUP_VERSION', '1.1.0');

function plugin_version_secup(): array
{
    return [
        'name'         => 'secup',
        'version'      => PLUGIN_SECUP_VERSION,
        'author'       => 'glpi-plugin-security',
        'license'      => 'GPL-3.0-or-later',
        'requirements' => ['glpi' => ['min' => '11.0.0', 'max' => '12.99.99']],
    ];
}

function plugin_secup_check_prerequisites(): bool
{
    return true;
}

function plugin_secup_check_config($verbose = false): bool
{
    return true;
}

function plugin_init_secup(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['secup'] = true;
    $PLUGIN_HOOKS['menu_toadd']['secup'] = ['tools' => [PluginSecupThing::class]];
}
