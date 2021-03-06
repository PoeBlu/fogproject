<?php
/**
 * Multicast task generator/finder
 *
 * PHP version 5
 *
 * @category MulticastTask
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * Multicast task generator/finder
 *
 * @category MulticastTask
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class MulticastTask extends FOGService
{
    public function getAllMulticastTasks($root, $myStorageNodeID)
    {
        $StorageNode = self::getClass('StorageNode', $myStorageNodeID);
        self::$HookManager->processEvent(
            'CHECK_NODE_MASTER',
            array(
                'StorageNode' => &$StorageNode,
                'FOGServiceClass' => &$this
            )
        );
        if (!$StorageNode->get('isMaster')) {
            return;
        }
        $Interface = self::getMasterInterface(
            self::$FOGCore->resolveHostname(
                $StorageNode->get('ip')
            )
        );
        unset($StorageNode);
        $Tasks = array();
        $MulticastSessions = self::getClass('MulticastSessionsManager')
            ->find(
                array(
                    'stateID' =>
                    array_merge(
                        $this->getQueuedStates(),
                        (array)$this->getProgressState()
                    )
                )
            );
        foreach ((array)$MulticastSessions as $index => &$MultiSess) {
            if (!$MultiSess->isValid()) {
                return;
            }
            $taskIDs = self::getSubObjectIDs(
                'MulticastSessionsAssociation',
                array(
                    'msID' => $MultiSess->get('id')
                ),
                'taskID'
            );
            $count = self::getClass('MulticastSessionsAssociationManager')
                ->count(
                    array(
                        'msID' => $MultiSess->get('id')
                    )
                );
            if ($count < 1) {
                $count = $MultiSess->get('sessclients');
            }
            if ($count < 1) {
                $MultiSess->set('stateID', $this->getCancelledState())->save();
                self::outall(
                    _('Task not created as there are no associated Tasks')
                );
                self::outall(
                    _('Or there was no number defined for joining session')
                );
                continue;
            }
            $Image = $MultiSess->getImage();
            $fullPath = sprintf('%s/%s', $root, $MultiSess->get('logpath'));
            if (!file_exists($fullPath)) {
                continue;
            }
            $Tasks[] = new self(
                $MultiSess->get('id'),
                $MultiSess->get('name'),
                $MultiSess->get('port'),
                $fullPath,
                $Interface,
                $count,
                $MultiSess->get('isDD'),
                $Image->get('osID'),
                $MultiSess->get('clients') == -2 ? 1 : 0,
                $taskIDs
            );
            unset($MultiSess, $index);
        }
        return array_filter($Tasks);
    }
    private $intID, $strName, $intPort, $strImage, $strEth, $intClients, $taskIDs;
    private $intImageType, $intOSID;
    public $procRef;
    public $procPipes;
    public function __construct($id = '', $name = '', $port = '', $image = '', $eth = '', $clients = '', $imagetype = '', $osid = '', $nameSess = '', $taskIDs = '')
    {
        parent::__construct();
        $this->intID = $id;
        $this->strName = $name;
        $this->intPort = self::getSetting('FOG_MULTICAST_PORT_OVERRIDE')?self::getSetting('FOG_MULTICAST_PORT_OVERRIDE'):$port;
        $this->strImage = $image;
        $this->strEth = $eth;
        $this->intClients = $clients;
        $this->intImageType = $imagetype;
        $this->intOSID = $osid;
        $this->isNameSess = $nameSess;
        $this->taskIDs = $taskIDs;
    }
    public function getSessClients()
    {
        return self::getClass('MulticastSessions', $this->getID())->get('clients') == 0;
    }
    public function isNamedSession()
    {
        return (bool)$this->isNameSess;
    }
    public function getTaskIDs()
    {
        return $this->taskIDs;
    }
    public function getID()
    {
        return $this->intID;
    }
    public function getName()
    {
        return $this->strName;
    }
    public function getImagePath()
    {
        return $this->strImage;
    }
    public function getImageType()
    {
        return $this->intImageType;
    }
    public function getClientCount()
    {
        return $this->intClients;
    }
    public function getPortBase()
    {
        return $this->intPort;
    }
    public function getInterface()
    {
        return $this->strEth;
    }
    public function getOSID()
    {
        return $this->intOSID;
    }
    public function getUDPCastLogFile()
    {
        return $this->altLog = sprintf('/%s/%s.udpcast.%s', trim(self::getSetting('SERVICE_LOG_PATH'), '/'), self::getSetting('MULTICASTLOGFILENAME'), $this->getID());
    }
    public function getBitrate()
    {
        return self::getClass('Image', self::getClass('MulticastSessions', $this->getID())->get('image'))->getStorageGroup()->getMasterStorageNode()->get('bitrate');
    }
    public function getCMD()
    {
        unset($filelist, $buildcmd, $cmd);
        list($address, $duplex, $maxwait) = self::getSubObjectIDs('Service', array('name'=>array('FOG_MULTICAST_ADDRESS', 'FOG_MULTICAST_DUPLEX', 'FOG_UDPCAST_MAXWAIT')), 'value', false, 'AND', 'name', false, '');
        $buildcmd = array(
            UDPSENDERPATH,
            $this->getBitrate() ? sprintf(' --max-bitrate %s', $this->getBitrate()) : null,
            $this->getInterface() ? sprintf(' --interface %s', $this->getInterface()) : null,
            sprintf(' --min-receivers %d', ($this->getClientCount()?$this->getClientCount():self::getClass(HostManager)->count())),
            sprintf(' --max-wait %s', '%d'),
            $address?sprintf(' --mcast-data-address %s', $address):null,
            sprintf(' --portbase %s', $this->getPortBase()),
            sprintf(' %s', $duplex),
            ' --ttl 32',
            ' --nokbd',
            ' --nopointopoint;',
        );
        $buildcmd = array_values(array_filter($buildcmd));
        switch ($this->getImageType()) {
            case 1:
                switch ($this->getOSID()) {
                    case 1:
                    case 2:
                        if (is_file($this->getImagePath())) {
                            $filelist[] = $this->getImagePath();
                            break;
                        }
                    case 5:
                    case 6:
                    case 7:
                        $files = scandir($this->getImagePath());
                        $sys = preg_grep('#(sys\.img\..*$)#i', $files);
                        $rec = preg_grep('#(rec\.img\..*$)#i', $files);
                        if (count($sys) || count($rec)) {
                            if (count($sys)) {
                                $filelist[] = 'sys.img.*';
                            }
                            if (count($rec)) {
                                $filelist[] = 'rec.img.*';
                            }
                        } else {
                            $filename = 'd1p%d.%s';
                            $iterator = self::getClass('DirectoryIterator', $this->getImagePath());
                            foreach ($iterator as $i => $fileInfo) {
                                if ($fileInfo->isDot()) {
                                    continue;
                                }
                                sscanf($fileInfo->getFilename(), $filename, $part, $ext);
                                if ($ext == 'img') {
                                    $filelist[] = $fileInfo->getFilename();
                                }
                                unset($part, $ext);
                            }
                        }
                            unset($files, $sys, $rec);
                        break;
                    default:
                        $filename = 'd1p%d.%s';
                        $iterator = self::getClass('DirectoryIterator', $this->getImagePath());
                        foreach ($iterator as $fileInfo) {
                            if ($fileInfo->isDot()) {
                                continue;
                            }
                            sscanf($fileInfo->getFilename(), $filename, $part, $ext);
                            if ($ext == 'img') {
                                $filelist[] = $fileInfo->getFilename();
                            }
                            unset($part, $ext);
                        }
                        break;
                }
                break;
            case 2:
                $filename = 'd1p%d.%s';
                $iterator = self::getClass('DirectoryIterator', $this->getImagePath());
                foreach ($iterator as $i => $fileInfo) {
                    if ($fileInfo->isDot()) {
                        continue;
                    }
                    sscanf($fileInfo->getFilename(), $filename, $part, $ext);
                    if ($ext == 'img') {
                        $filelist[] = $fileInfo->getFilename();
                    }
                    unset($part, $ext);
                }
                break;
            case 3:
                $filename = 'd%dp%d.%s';
                $iterator = self::getClass('DirectoryIterator', $this->getImagePath());
                foreach ($iterator as $i => $fileInfo) {
                    if ($fileInfo->isDot()) {
                        continue;
                    }
                    sscanf($fileInfo->getFilename(), $filename, $device, $part, $ext);
                    if ($ext == 'img') {
                        $filelist[] = $fileInfo->getFilename();
                    }
                    unset($device, $part, $ext);
                }
                break;
            case 4:
                $iterator = self::getClass('DirectoryIterator', $this->getImagePath());
                foreach ($iterator as $fileInfo) {
                    if ($fileInfo->isDot()) {
                        continue;
                    }
                    $filelist[] = $fileInfo->getFilename();
                }
                unset($iterator);
                break;
        }
        natcasesort($filelist);
        $filelist = array_values((array)$filelist);
        ob_start();
        foreach ($filelist as $i => &$file) {
            printf('cat %s%s%s | %s', rtrim($this->getImagePath(), DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR, $file, sprintf(implode($buildcmd), $i == 0 ? $maxwait * 60 : 10));
            unset($file);
        }
        unset($filelist, $buildcmd);
        return ob_get_clean();
    }
    public function startTask()
    {
        unlink($this->getUDPCastLogFile());
        $this->startTasking($this->getCMD(), $this->getUDPCastLogFile());
        $this->procRef = array_shift($this->procRef);
        self::getClass('MulticastSessions', $this->intID)
            ->set('stateID', $this->getQueuedState())
            ->save();
        return $this->isRunning($this->procRef);
    }
    public function killTask()
    {
        $this->killTasking();
        unlink($this->getUDPCastLogFile());
        return true;
    }
    public function updateStats()
    {
        $Tasks = self::getClass('TaskManager')->find(array('id'=>self::getSubObjectIDs('MulticastSessionsAssociation', array('msID'=>$this->intID), 'taskID')));
        foreach ($Tasks as $i => &$Task) {
            $TaskPercent[] = $Task->get('percent');
            unset($Task);
        }
        unset($Tasks);
        $TaskPercent = array_unique((array)$TaskPercent);
        self::getClass('MulticastSessions', $this->intID)->set('percent', @max($TaskPercent))->save();
    }
}
