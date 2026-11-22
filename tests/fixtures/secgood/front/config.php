<?php

// SPDX-License-Identifier: GPL-3.0-or-later

// Version correcte (issue #22) : la page de configuration est rangée dans Configuration > Plugins
// et ne contient que des réglages (adresse du connecteur, « Tester la connexion », seuils).

Session::checkRight('config', UPDATE);

Html::header('secgood', '', 'config', 'plugin');
Glpi\Application\View\TemplateRenderer::getInstance()->display('@secgood/config.html.twig', [
    'sources' => [],
]);
Html::footer();
