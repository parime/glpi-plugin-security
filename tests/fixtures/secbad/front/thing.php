<?php

// SPDX-License-Identifier: GPL-3.0-or-later

// Défauts volontaires (glpi-plugin-security) : page sans contrôle d'autorisation, absente de la
// matrice d'accès, SQL brut hors installation et HTML écrit en PHP sans référence Twig.

function plugin_secbad_list_things(): void
{
    global $DB;

    $DB->doQuery('SELECT id FROM glpi_plugin_secbad_things');
}

echo '<div class="secbad"><span>' . htmlescape('liste') . '</span></div>';
