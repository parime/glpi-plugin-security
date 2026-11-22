<?php

// SPDX-License-Identifier: GPL-3.0-or-later

// Défauts volontaires du parcours des pages (issue #23) : écran qu'aucun menu ni aucun lien ne
// permet d'atteindre (page orpheline), et image sans texte de remplacement (violation axe-core
// image-alt, impact critical).

Session::checkRight('config', READ);

Html::header('Hidden', '', 'tools', PluginSecbadThing::class);
echo '<div class="card"><img src="../pics/logo.png"><input type="text" name="q"></div>';
Html::footer();
