<?php

namespace App\EventListener;

use App\Service\CalculationCache;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Invalidates the cache of each entity (transaction, account, tag, person) that a flush creates,
 * updates or deletes: only the calculations depending on these entities are dropped.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
class CalculationCacheInvalidator
{
    /**
     * @var array<string, true>
     */
    private array $changedEntities = [];

    public function __construct(private readonly CalculationCache $calculationCache)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
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
            if (null !== $entity && null !== $cache = $this->calculationCache->entityCacheOf($entity::class)) {
                $this->changedEntities[$cache] = true;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->changedEntities) {
            return;
        }

        $changed = array_keys($this->changedEntities);
        $this->changedEntities = [];
        $this->calculationCache->invalidate($changed);
    }
}
