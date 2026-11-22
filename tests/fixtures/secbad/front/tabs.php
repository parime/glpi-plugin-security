<?php

// SPDX-License-Identifier: GPL-3.0-or-later

// Défauts volontaires des barres d'onglets (issue #34) : 9 onglets aux libellés longs (plus de 7,
// sur plusieurs lignes à 1280 px), sans role=tablist ni role=tab, premier onglet sans adresse
// (inatteignable au clavier), onglet vers la configuration (refusée au technicien) et onglet vers
// un module désactivé.

Session::checkRight('computer', READ);

Html::header('Tabs', '', 'tools', PluginSecbadThing::class);
echo '<ul class="nav nav-tabs">';
echo '<li class="nav-item"><a class="nav-link active">Premier onglet de la rubrique, sans adresse</a></li>';
for ($i = 2; $i <= 7; $i++) {
    echo '<li class="nav-item"><a class="nav-link" href="tabs.php?tab=' . $i . '">Onglet numéro ' . $i
        . ' avec un libellé beaucoup trop long</a></li>';
}
echo '<li class="nav-item"><a class="nav-link" href="config.php">Configuration du plugin secbad</a></li>';
echo '<li class="nav-item"><a class="nav-link" href="module.php">Module des rapports de secbad</a></li>';
echo '</ul>';
Html::footer();
