<?php

// SPDX-License-Identifier: GPL-3.0-or-later

// Défauts volontaires du plugin, voisins des composants du cœur exemptés par le parcours (issue #50) :
// lien visible vers une page refusée (denied.php, signalé) à côté d'un lien masqué bs-invisible comme
// ceux de l'en-tête de navigation des fiches du cœur (hiddendenied.php, ignoré), et liste sans nom
// accessible du plugin (signalée) à côté d'un sélecteur du nombre de lignes du cœur (ignoré).

Session::checkRight('config', READ);

Html::header('Lookalike', '', 'tools', PluginSecbadThing::class);
echo '<div class="card">';
echo '<a href="denied.php">Page refusée</a>';
echo '<a class="bs-invisible" href="hiddendenied.php">Page refusée, lien masqué</a>';
echo '<select id="plugin_rows" name="rows"><option>10</option></select>';
echo '<select id="dropdown_glpilist_limit1234567" name="glpilist_limit"><option>20</option></select>';
echo '</div>';
Html::footer();
