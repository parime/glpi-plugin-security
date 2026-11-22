<?php

// SPDX-License-Identifier: GPL-3.0-or-later

// Défaut volontaire du parcours des pages (issue #23) : page en erreur 500 (fonction inexistante).

Session::checkRight('config', READ);

plugin_secbad_function_that_does_not_exist();
