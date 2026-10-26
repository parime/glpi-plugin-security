<?php

class PluginSecgoodNotificationTargetThing extends NotificationTarget
{
    public const TARGET_OWNER = 1001;

    public function getEvents()
    {
        return ['reminder' => 'Rappel'];
    }

    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::TARGET_OWNER, 'Responsable');
    }

    public function addSpecificTargets($data, $options)
    {
    }
}
