<?php

// SPDX-License-Identifier: GPL-3.0-or-later

// Page d'un module désactivé, proposée par un onglet de front/tabs.php (défaut volontaire, issue #34).

Session::checkRight('computer', READ);

Html::header('Module', '', 'tools', PluginSecbadThing::class);
echo '<div class="alert alert-info">Le module des rapports est désactivé.</div>';
Html::footer();
