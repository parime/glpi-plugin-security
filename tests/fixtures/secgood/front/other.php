<?php

// SPDX-License-Identifier: GPL-3.0-or-later

// Version correcte (glpi-plugin-security) : contrôle d'autorisation, page couverte par un fragment
// de la matrice d'accès (tests/security/access-matrix.d/other.sh), aucun SQL brut ni HTML écrit en
// PHP (affichage par un gabarit Twig).

Session::checkRight('config', READ);

// En-tête rangé dans l'entrée de menu du plugin (Outils), pour le fil d'Ariane (issue #22).
Html::header('Other', '', 'tools', PluginSecgoodThing::class);
Glpi\Application\View\TemplateRenderer::getInstance()->display('@secgood/thing.html.twig');
Html::footer();
