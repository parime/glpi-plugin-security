<?php

// Entrée de menu d'usage de secbad (Outils), qui mène à front/report.php : la classe existe, sinon
// chaque page de GLPI échoue à la construction du menu (parcours des pages, issue #23).
class PluginSecbadReport extends CommonGLPI
{
    // Pas de propriété $rightname : typée en GLPI 12, pas en GLPI 11 (voir tests/fixtures/upgrade).
    public static function canView(): bool
    {
        return (bool) Session::haveRight('plugin_secbad', READ);
    }

    public static function getTypeName($nb = 0)
    {
        return 'Report';
    }

    // Défaut volontaire d'ergonomie (issue #34) : module activable dont l'onglet ne consulte pas
    // l'activation (affiché même désactivé).
    public static function isEnabled(): bool
    {
        return false;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        return 'Report';
    }
}
