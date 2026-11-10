<?php

namespace App\EventListener;

use App\Entity\Account;
use App\Service\ColorGenerator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Gives every new account without a color a random one, unique among the colors already stored
 * and those of the accounts persisted but not flushed yet (account.color is a unique column).
 */
#[AsEntityListener(event: Events::prePersist, entity: Account::class)]
final class AccountColorAssigner
{
    public function __construct(private readonly ColorGenerator $colorGenerator)
    {
    }

    public function prePersist(Account $account, PrePersistEventArgs $args): void
    {
        if (null !== $account->getColor() && '' !== $account->getColor()) {
            return;
        }

        $entityManager = $args->getObjectManager();
        $usedColors = $entityManager->getRepository(Account::class)->findUsedColors();

        foreach ($entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $pending) {
            if ($pending instanceof Account && null !== $pending->getColor()) {
                $usedColors[] = $pending->getColor();
            }
        }

        $account->setColor($this->colorGenerator->generateUnique($usedColors));
    }
}
