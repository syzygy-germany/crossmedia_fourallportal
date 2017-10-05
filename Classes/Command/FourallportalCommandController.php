<?php
namespace Crossmedia\Fourallportal\Command;

use Crossmedia\Fourallportal\Domain\Model\Event;
use Crossmedia\Fourallportal\Domain\Model\Module;
use Crossmedia\Fourallportal\Domain\Model\Server;
use Crossmedia\Fourallportal\DynamicModel\DynamicModelGenerator;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\CommandController;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

class FourallportalCommandController extends CommandController
{
    /**
     * @var \Crossmedia\Fourallportal\Domain\Repository\ServerRepository
     * */
    protected $serverRepository = null;

    /**
     * @var \Crossmedia\Fourallportal\Domain\Repository\EventRepository
     * */
    protected $eventRepository = null;

    /**
     * @var \Crossmedia\Fourallportal\Domain\Repository\ModuleRepository
     * */
    protected $moduleRepository = null;


    public function injectEventRepository(\Crossmedia\Fourallportal\Domain\Repository\EventRepository $eventRepository)
    {
        $this->eventRepository = $eventRepository;
    }

    public function injectModuleRepository(\Crossmedia\Fourallportal\Domain\Repository\ModuleRepository $moduleRepository)
    {
        $this->moduleRepository = $moduleRepository;
    }

    public function injectServerRepository(\Crossmedia\Fourallportal\Domain\Repository\ServerRepository $serverRepository)
    {
        $this->serverRepository = $serverRepository;
    }

    /**
     * Update models
     *
     * Updates local model classes with properties as specified by
     * the mapping information and model information from the API.
     * Uses the Server and Module configurations in the system and
     * consults the Mapping class to identify each model that must
     * be updated, then uses the DynamicModelHandler to generate
     * an abstract model class to use with each specific model.
     *
     * A special class loading function must be used in the model
     * before it can use the dynamically generated base class. See
     * the provided README.md file for more information about this.
     */
    public function updateModelsCommand()
    {
        GeneralUtility::makeInstance(ObjectManager::class)->get(DynamicModelGenerator::class)->generateAbstractModelsForAllModules();
    }

    /**
     * Sync data
     *
     * Execute this to synchronise events from the PIM API.
     *
     * @param boolean $sync Set to "1" to trigger a full sync
     */
    public function syncCommand($sync = false)
    {
        /** @var Server[] $servers */
        $servers = $this->serverRepository->findByActive(true);
        foreach ($servers as $server) {
            $client = $server->getClient();
            foreach ($server->getModules() as $module) {
                /** @var Module $module */
                if (!$sync && $module->getLastEventId() > 0) {
                    $results = $client->getEvents($module->getConnectorName(), $module->getLastEventId());
                } else {
                    $this->eventRepository->removeAll();
                    $results = $client->synchronize($module->getConnectorName());
                }
                foreach ($results as $result) {
                    $this->queueEvent($module, $result);
                }
                $this->moduleRepository->update($module);
            }
        }

        $this->objectManager->get(PersistenceManagerInterface::class)->persistAll();

        // Handle new, pending events first, which may cause some to be deferred:
        foreach ($this->eventRepository->findByStatus('pending') as $event) {
            $this->processEvent($event);
        }

        // Then handle any events that were deferred - which may cause some to be deferred again:
        foreach ($this->eventRepository->findByStatus('deferred') as $event) {
            $this->processEvent($event);
        }
    }

    /**
     * @param Event $event
     */
    public function processEvent($event)
    {
        $client = $event->getModule()->getServer()->getClient();
        try {
            $mapper = $event->getModule()->getMapper();
            $responseData = $client->getBeans(
                [
                    $event->getObjectId()
                ],
                $event->getModule()->getConnectorName()
            );
            $mapper->import($responseData, $event);
            $event->setStatus('claimed');
            $event->setMessage('Successfully executed - no additional output available');
            // Update the Module's last recorded event ID, but only if the event ID was higher. This allows
            // deferred events to execute without lowering the last recorded event ID which would cause
            // duplicate event processing on the next run.
            $event->getModule()->setLastEventId(max($event->getEventId(), $event->getModule()->getLastEventId()));
        } catch (\InvalidArgumentException $error) {
            // The system was unable to map properties, most likely because of an unresolvable relation.
            // Skip the event for now; process it later.
            $event->setStatus('deferred');
            $event->setMessage($error->getMessage() . ' (code: ' . $error->getCode() . ')');
        } catch(\Exception $exception) {
            $event->setStatus('failed');
            $event->setMessage($exception->getMessage() . ' (code: ' . $exception->getCode() . ')');
        }
        $responseMetadata = $client->getLastResponse();
        $event->setHeaders($responseMetadata['headers']);
        $event->setUrl($responseMetadata['url']);
        $event->setResponse($responseMetadata['response']);
        $event->setPayload($responseMetadata['payload']);
        $this->eventRepository->update($event);
        $this->objectManager->get(PersistenceManagerInterface::class)->persistAll();
    }

    /**
     * @param Module $module
     * @param array $result
     * @return Event
     */
    protected function queueEvent($module, $result)
    {
        $event = new Event();
        $event->setModule($module);
        $event->setEventId($result['event_id']);
        $event->setObjectId($result['object_id']);
        $event->setEventType(Event::resolveEventType($result['event_type']));
        $this->eventRepository->add($event);

        return $event;
    }
}
