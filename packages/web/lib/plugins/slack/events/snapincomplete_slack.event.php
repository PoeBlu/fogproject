<?php
/****************************************************
 *  Called when snapin tasking is complete
 *	Author:		Tom Elliott
 ***/
class SnapinComplete_Slack extends PushbulletExtends
{
    // Class variables
    protected $name = 'SnapinComplete_Slack';
    protected $description = 'Triggers when a host completes snapin taskings';
    protected $author = 'Tom Elliott';
    public $active = true;
    public function onEvent($event, $data)
    {
        self::$message = sprintf('Host %s has completed snapin tasking.', $data['Host']->get('name'));
        self::$shortdesc = 'Snapin(s) Complete';
        parent::onEvent($event, $data);
    }
}
$EventManager->register('HOST_SNAPIN_COMPLETE', new SnapinComplete_Slack());
