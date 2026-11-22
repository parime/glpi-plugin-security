<?php

// SPDX-License-Identifier: GPL-3.0-or-later

// Défaut volontaire d'ergonomie (issue #22) : page d'usage affichée sans Html::header() (ni menu,
// ni fil d'Ariane).

Session::checkRight('config', READ);

Glpi\Application\View\TemplateRenderer::getInstance()->display('@secbad/report.html.twig');
