<?php

// Onglet des profils : le droit du plugin s'attribue depuis l'interface, comme ceux du cœur.
class PluginSecgoodProfile extends CommonGLPI
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        return $item instanceof Profile && Profile::canView() && Session::haveRight('plugin_secgood', READ)
            ? 'secgood' : '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Profile || !Profile::canView() || !Session::haveRight('plugin_secgood', READ)) {
            return false;
        }
        $item->displayRightsChoiceMatrix(
            [['itemtype' => PluginSecgoodThing::class, 'label' => 'secgood', 'field' => 'plugin_secgood']],
            ['canedit' => false, 'title' => 'secgood']
        );

        return true;
    }
}
