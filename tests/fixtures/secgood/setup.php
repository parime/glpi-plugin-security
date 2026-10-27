<?php

// Plugin de test de glpi-plugin-security (version correcte).
define('PLUGIN_SECGOOD_VERSION', '1.0.0');

function plugin_version_secgood(): array
{
    return [
        'name'         => 'secgood',
        'version'      => PLUGIN_SECGOOD_VERSION,
        'author'       => 'glpi-plugin-security',
        'license'      => 'GPL-3.0-or-later',
        'requirements' => ['glpi' => ['min' => '11.0.0', 'max' => '12.99.99']],
    ];
}

function plugin_secgood_check_prerequisites(): bool
{
    return true;
}

function plugin_secgood_check_config($verbose = false): bool
{
    return true;
}

function plugin_init_secgood(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['secgood'] = true;
    Plugin::registerClass(PluginSecgoodThing::class, ['addtabon' => ['Computer']]);
    Plugin::registerClass(PluginSecgoodProfile::class, ['addtabon' => ['Profile']]);
}
