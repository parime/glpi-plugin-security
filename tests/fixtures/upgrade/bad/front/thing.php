<?php

// SPDX-License-Identifier: GPL-3.0-or-later

include '../../../inc/includes.php';

Session::checkRight('plugin_secup', READ);
Html::header(PluginSecupThing::getTypeName(), $_SERVER['PHP_SELF'], 'tools', PluginSecupThing::class);
echo 'secup';
undefined_secup_function();
Html::footer();
