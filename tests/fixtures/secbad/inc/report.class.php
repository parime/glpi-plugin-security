<?php

// Entrée de menu d'usage de secbad (Outils), qui mène à front/report.php : la classe existe, sinon
// chaque page de GLPI échoue à la construction du menu (parcours des pages, issue #23).
class PluginSecbadReport extends CommonGLPI
{
    public static $rightname = 'plugin_secbad';

    public static function getTypeName($nb = 0)
    {
        return 'Report';
    }
}
