<?php

// SPDX-License-Identifier: GPL-3.0-or-later

// Défauts volontaires d'ergonomie (issue #22) : la page de configuration est rangée dans
// Assistance alors que l'entrée de menu de PluginSecbadThing est dans Outils, et son template
// contient de l'exploitation (bouton « Récupérer maintenant », tableau de résultats, lien vers
// la page d'usage front/report.php).

Session::checkRight('config', UPDATE);

Html::header('secbad', '', 'helpdesk', PluginSecbadThing::class);
Glpi\Application\View\TemplateRenderer::getInstance()->display('@secbad/config.html.twig', [
    'results' => [],
]);
Html::footer();
