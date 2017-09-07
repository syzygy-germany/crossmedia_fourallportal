<?php
namespace Crossmedia\Fourallportal\Mapping;

use Crossmedia\Fourallportal\Domain\Model\Event;
use Crossmedia\Fourallportal\Domain\Model\Module;
use Crossmedia\Fourallportal\Domain\Model\Server;
use Crossmedia\Fourallportal\Service\ApiClient;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Domain\Model\FileReference;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

class FalMapping extends AbstractMapping
{
    /**
     * @var string
     */
    protected $repositoryClassName = FileRepository::class;

    /**
     * @return string
     */
    protected function getEntityClassName()
    {
        return FileReference::class;
    }

    /**
     * @param array $data
     * @param Event $event
     */
    public function import(array $data, Event $event)
    {
        $repository = $this->getObjectRepository();
        $objectId = $event->getObjectId();
        $object = null;

        // We have to do things the hard way, unfortunately. Because someone didn't implement a real Repository but declared the class a Repository anyway. Sigh.
        $queryBuilder = (new ConnectionPool())->getConnectionForTable('sys_file')->createQueryBuilder();
        $query = $queryBuilder->select('uid')->from('sys_file')->where(sprintf('remote_id = \'%s\'', $objectId))->setMaxResults(1);
        $record = $query->execute()->fetch();
        if ($record) {
            $object = $repository->findByUid($record['uid']);
        }

        switch ($event->getEventType()) {
            case 'delete':
                if (!$object) {
                    // push back event.
                    return;
                }
                $repository->remove($object);
                break;
            case 'update':
                if (!$object) {
                    // push back event.

                    return;
                }
            case 'create':
                if (!$object) {
                    // Download the file's binary data to local storage, then load the file as File object, then manually update the database to set the one column we can't access through the model.
                    $object = $this->downloadFileAndGetCreatedFileObject($objectId, $data, $event);
                }
                $this->mapPropertiesFromDataToObject($data, $object);
                /*
                if ($object->getUid()) {
                    $repository->update($object);
                } else {
                    $repository->add($object);
                }
                */
                break;
            default:
                throw new \RuntimeException('Unknown event type: ' . $event->getEventType());
        }

        GeneralUtility::makeInstance(ObjectManager::class)->get(PersistenceManager::class)->persistAll();

        if ($object) {
            $this->processRelationships($object, $data, $event);
        }
    }

    /**
     * @param string $objectId
     * @param array $data
     * @param Event $event
     * @return File
     */
    protected function downloadFileAndGetCreatedFileObject($objectId, array $data, Event $event)
    {
        $client = $this->getClientByServer($event->getModule()->getServer());

        $targetFilename = $data['result'][0]['properties']['data_name'];
        $tempPathAndFilename = GeneralUtility::tempnam('mamfal', $targetFilename);

        $trimShellPath = $event->getModule()->getShellPath();
        $targetFolder = substr($data['result'][0]['properties']['data_shellpath'], strlen($trimShellPath));

        $storage = (new StorageRepository())->findByUid($event->getModule()->getFalStorage());
        try {
            $folder = $storage->getFolder($targetFolder);
        } catch (FolderDoesNotExistException $error) {
            $folder = $storage->createFolder($targetFolder);
        }

        try {
            $client->saveDerivate($tempPathAndFilename, $objectId);
            $contents = file_get_contents($tempPathAndFilename);
            $file = $folder->createFile($targetFilename)->setContents($contents);
        } catch (ExistingTargetFileNameException $error) {
            $file = reset($this->getObjectRepository()->searchByName($folder, $targetFilename));
        }

        if (!$file) {
            throw new \RuntimeException('Unable to either create or re-use existing file: ' . $targetFolder . $targetFilename);
        }


        $queryBuilder = (new ConnectionPool())->getConnectionForTable('sys_file')->createQueryBuilder();
        $query = $queryBuilder->update('sys_file', 'f')
            ->set('f.remote_id', $objectId)
            ->where('f.uid = ' . $file->getUid())
            ->setMaxResults(1);

        if (!is_int($query->execute())) {
            throw new \RuntimeException('Failed to update remote_id column of sys_file table for file with UID ' . $file->getUid());
        }

        return $file;
    }

    /**
     * @param Server $server
     * @return ApiClient
     */
    protected function getClientByServer(Server $server)
    {
        static $clients = [];
        $serverId = $server->getUid();
        if (isset($clients[$serverId])) {
            return $clients[$serverId];
        }
        $client = GeneralUtility::makeInstance(ObjectManager::class)->get(ApiClient::class, $server);
        $client->login();
        $clients[$serverId] = $client;
        return $client;
    }

    /**
     * @param ApiClient $client
     * @param Module $module
     * @param array $status
     * @return array
     */
    public function check(ApiClient $client, Module $module, array $status)
    {
        if (empty($module->getShellPath())) {
            $status['class'] = 'danger';
            $status['description'] .= '
                <h3>FalMapping</h3>
            ';
            $events = $client->getEvents($module->getConnectorName(), 0);
            $ids = [];
            foreach($events as $event) {
                $ids[] = $event['object_id'];
                if (count($ids) == 3) {
                    break;
                }
            }
            $messages = [];
            $beans = $client->getBeans($ids, $module->getConnectorName());
            $paths = [];
            foreach($beans['result'] as $result) {
                if (!isset($result['properties']['data_name'])) {
                    $messages['data_name'] = '<p><strong class="text-danger">Connector does not provide required "data_name" property</strong></p>';
                }
                if (!isset($result['properties']['data_shellpath'])) {
                    $messages['data_shellpath'] = '<p><strong class="text-danger">Connector does not provide required "data_shellpath" property</strong></p>';
                }
                $paths[] = $result['properties']['data_shellpath'] . $result['properties']['data_name'];
            }
            $messages['shellpath_missing'] = '
                <p>
                    <strong class="text-danger">Missing ShellPath in ModuleConfig</strong><br />
                </p>
                <p>
                    <strong>Paths of the 3 first Files:</strong><br />
                    ' . implode('<br />', $paths) . '
                </p>
            ';

            $status['description'] .= implode(chr(10), $messages);
        }
        return $status;
    }

}
