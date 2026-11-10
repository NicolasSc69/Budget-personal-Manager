<?php

namespace App\Repository;

use App\Entity\Account;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Account>
 */
class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    /**
     * @return Account[]
     */
    public function findAllOrderedByLabel(): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.label', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Upper-cased colors of every account, optionally leaving one account out (the one being edited).
     *
     * @return list<string>
     */
    public function findUsedColors(?Account $exclude = null): array
    {
        $queryBuilder = $this->createQueryBuilder('a')->select('a.color');

        if (null !== $exclude?->getId()) {
            $queryBuilder->andWhere('a.id <> :excludedId')->setParameter('excludedId', $exclude->getId());
        }

        return array_values(array_map('strtoupper', array_filter($queryBuilder->getQuery()->getSingleColumnResult())));
    }
}
