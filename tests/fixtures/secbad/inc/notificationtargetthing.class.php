<?php

class PluginSecbadNotificationTargetThing extends NotificationTarget
{
    public const TARGET_OWNER = 1;

    public function getEvents()
    {
        return ['reminder' => 'Rappel'];
    }

    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::TARGET_OWNER, 'Responsable');
    }
}
