<?php

// SPDX-License-Identifier: GPL-3.0-or-later

// Page toujours refusée (403), ouverte depuis lookalike.php (issue #50).

Session::checkRight('config', READ);

throw new \Glpi\Exception\Http\AccessDeniedHttpException();
