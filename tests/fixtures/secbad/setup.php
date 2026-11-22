<?php

// Plugin de test de glpi-plugin-security (défauts volontaires).
define('PLUGIN_SECBAD_VERSION', '1.0.0');

function plugin_version_secbad(): array
{
    return [
        'name'         => 'secbad',
        'version'      => PLUGIN_SECBAD_VERSION,
        'author'       => 'glpi-plugin-security',
        'license'      => 'GPL-3.0-or-later',
        'requirements' => ['glpi' => ['min' => '11.0.0', 'max' => '12.99.99']],
    ];
}

function plugin_secbad_check_prerequisites(): bool
{
    return true;
}

function plugin_secbad_check_config($verbose = false): bool
{
    return true;
}

function plugin_init_secbad(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['secbad'] = true;
    Plugin::registerClass(PluginSecbadThing::class, ['addtabon' => ['Computer']]);
    // Trois entrées de menu : la charte commune n'en admet qu'une (contrôle « menus »). Ergonomie
    // (issue #22) : deux entrées dans Outils, une dans Configuration (les réglages passent par
    // config_page), et une page de configuration qui contient de l'exploitation.
    $PLUGIN_HOOKS['menu_toadd']['secbad'] = [
        'tools'  => [PluginSecbadThing::class, 'PluginSecbadReport'],
        'config' => [PluginSecbadThing::class],
    ];
    $PLUGIN_HOOKS['config_page']['secbad'] = 'front/config.php';
}
