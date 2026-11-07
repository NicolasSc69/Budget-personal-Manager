<?php

namespace App\EventListener;

use App\Entity\AppSetting;
use App\Service\DatabaseExportTracker;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Flags the database as "to download" when a user action (a non-GET request) adds, modifies or
 * deletes an entity. Writes made while browsing (automatic generation of the recurring
 * transactions, creation of the settings row...) and the application settings are ignored.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
class DatabaseExportTrackerListener
{
    private bool $entityChanged = false;

    public function __construct(
        private readonly DatabaseExportTracker $databaseExportTracker,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request || $request->isMethodSafe()) {
            return;
        }

        $unitOfWork = $args->getObjectManager()->getUnitOfWork();

        $entities = [
            ...$unitOfWork->getScheduledEntityInsertions(),
            ...$unitOfWork->getScheduledEntityUpdates(),
            ...$unitOfWork->getScheduledEntityDeletions(),
        ];
        foreach ([...$unitOfWork->getScheduledCollectionUpdates(), ...$unitOfWork->getScheduledCollectionDeletions()] as $collection) {
            $entities[] = $collection->getOwner();
        }

        foreach ($entities as $entity) {
            if (null !== $entity && !$entity instanceof AppSetting) {
                $this->entityChanged = true;

                return;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->entityChanged) {
            return;
        }

        $this->entityChanged = false;
        $this->databaseExportTracker->markModified();
    }
}
