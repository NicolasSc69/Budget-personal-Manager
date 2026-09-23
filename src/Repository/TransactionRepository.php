<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Enum\Recurrence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transaction>
 */
class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    /**
     * @return list<string>
     */
    public function findDistinctTitles(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('DISTINCT t.title')
            ->andWhere('t.title IS NOT NULL')
            ->andWhere("t.title != ''")
            ->orderBy('t.title', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'title');
    }

    /**
     * @return array<int, array{category: Category, expenseTotal: float, incomeTotal: float}>
     */
    public function sumByCategorySince(\DateTimeImmutable $since, ?Account $account = null, ?\DateTimeImmutable $until = null): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select(
                'c AS category',
                'SUM(CASE WHEN t.amount < 0 THEN t.amount ELSE 0 END) AS expenseTotal',
                'SUM(CASE WHEN t.amount > 0 THEN t.amount ELSE 0 END) AS incomeTotal'
            )
            ->from(Category::class, 'c')
            ->join('c.transactions', 't')
            ->andWhere('t.date >= :since')
            ->andWhere('t.date <= :until')
            ->setParameter('since', $since, Types::DATE_IMMUTABLE)
            ->setParameter('until', $until ?? new \DateTimeImmutable('today'), Types::DATE_IMMUTABLE)
            ->groupBy('c.id')
            ->orderBy('c.name', 'ASC');

        if (null !== $account) {
            $qb->andWhere('t.account = :account')->setParameter('account', $account);
        }

        return $qb->getQuery()->getResult();
    }

    private const SORTABLE_FIELDS = [
        'title' => 't.title',
        'date' => 't.date',
        'amount' => 't.amount',
        'account' => 'a.label',
        'category' => 'c.name',
        'id' => 't.id',
    ];

    private function buildFilteredQueryBuilder(
        ?Account $account,
        ?Category $category,
        ?\DateTimeImmutable $dateFrom,
        ?\DateTimeImmutable $dateTo,
        ?float $amountMin,
        ?float $amountMax,
        ?string $search = null,
        bool $upcomingOnly = false,
        bool $currentMonthOnly = false,
        bool $excludeForecastExcluded = false,
    ): \Doctrine\ORM\QueryBuilder {
        $qb = $this->createQueryBuilder('t')
            ->join('t.account', 'a')
            ->join('t.category', 'c');

        if (null !== $account) {
            $qb->andWhere('t.account = :account')->setParameter('account', $account);
        }

        if (null !== $category) {
            $qb->andWhere('t.category IN (:categoryIds)')->setParameter('categoryIds', $category->getSelfAndDescendantIds());
        }

        if (null !== $dateFrom) {
            $qb->andWhere('t.date >= :dateFrom')->setParameter('dateFrom', $dateFrom, Types::DATE_IMMUTABLE);
        }

        if (null !== $dateTo) {
            $qb->andWhere('t.date <= :dateTo')->setParameter('dateTo', $dateTo, Types::DATE_IMMUTABLE);
        }

        if (null !== $amountMin) {
            $qb->andWhere('t.amount >= :amountMin')->setParameter('amountMin', $amountMin);
        }

        if (null !== $amountMax) {
            $qb->andWhere('t.amount <= :amountMax')->setParameter('amountMax', $amountMax);
        }

        if (null !== $search && '' !== $search) {
            $qb->andWhere('t.title LIKE :search')->setParameter('search', '%'.$search.'%');
        }

        if ($upcomingOnly) {
            $qb->andWhere('t.date > :today')->setParameter('today', new \DateTimeImmutable('today'), Types::DATE_IMMUTABLE);
        }

        if ($currentMonthOnly) {
            $qb->andWhere('t.date >= :monthStart')->setParameter('monthStart', new \DateTimeImmutable('first day of this month'), Types::DATE_IMMUTABLE);
            $qb->andWhere('t.date <= :monthEnd')->setParameter('monthEnd', new \DateTimeImmutable('last day of this month'), Types::DATE_IMMUTABLE);
        }

        if ($excludeForecastExcluded) {
            $qb->andWhere('c.excludedFromForecast = false');
        }

        return $qb;
    }

    /**
     * @return Transaction[]
     */
    public function findFiltered(
        ?Account $account = null,
        ?Category $category = null,
        ?\DateTimeImmutable $dateFrom = null,
        ?\DateTimeImmutable $dateTo = null,
        ?float $amountMin = null,
        ?float $amountMax = null,
        ?string $search = null,
        string $sortField = 'date',
        string $sortDirection = 'desc',
        ?int $limit = null,
        ?int $offset = null,
        bool $upcomingOnly = false,
        bool $currentMonthOnly = false,
    ): array {
        $qb = $this->buildFilteredQueryBuilder($account, $category, $dateFrom, $dateTo, $amountMin, $amountMax, $search, $upcomingOnly, $currentMonthOnly);

        $sortExpr = self::SORTABLE_FIELDS[$sortField] ?? self::SORTABLE_FIELDS['date'];
        $direction = 'asc' === strtolower($sortDirection) ? 'ASC' : 'DESC';

        $qb->orderBy($sortExpr, $direction);
        if ('t.date' !== $sortExpr) {
            $qb->addOrderBy('t.date', 'DESC');
        }
        $qb->addOrderBy('t.id', 'DESC');

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        if (null !== $offset) {
            $qb->setFirstResult($offset);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Sorted by tag, then alphabetically by title, then by date — used by the PDF export, which always
     * groups by tag as a report structure regardless of whatever column sort is active on screen.
     *
     * @return Transaction[]
     */
    public function findFilteredForExport(
        ?Account $account = null,
        ?Category $category = null,
        ?\DateTimeImmutable $dateFrom = null,
        ?\DateTimeImmutable $dateTo = null,
        ?float $amountMin = null,
        ?float $amountMax = null,
        ?string $search = null,
        bool $upcomingOnly = false,
        bool $currentMonthOnly = false,
    ): array {
        $qb = $this->buildFilteredQueryBuilder($account, $category, $dateFrom, $dateTo, $amountMin, $amountMax, $search, $upcomingOnly, $currentMonthOnly);

        $qb->orderBy('c.name', 'ASC')
            ->addOrderBy('t.title', 'ASC')
            ->addOrderBy('t.date', 'ASC')
            ->addOrderBy('t.id', 'ASC');

        return $qb->getQuery()->getResult();
    }

    public function countFiltered(
        ?Account $account = null,
        ?Category $category = null,
        ?\DateTimeImmutable $dateFrom = null,
        ?\DateTimeImmutable $dateTo = null,
        ?float $amountMin = null,
        ?float $amountMax = null,
        ?string $search = null,
        bool $upcomingOnly = false,
        bool $currentMonthOnly = false,
    ): int {
        $qb = $this->buildFilteredQueryBuilder($account, $category, $dateFrom, $dateTo, $amountMin, $amountMax, $search, $upcomingOnly, $currentMonthOnly);

        return (int) $qb->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();
    }

    public function sumFiltered(
        ?Account $account = null,
        ?Category $category = null,
        ?\DateTimeImmutable $dateFrom = null,
        ?\DateTimeImmutable $dateTo = null,
        ?float $amountMin = null,
        ?float $amountMax = null,
        ?string $search = null,
        bool $upcomingOnly = false,
        bool $currentMonthOnly = false,
        bool $excludeForecastExcluded = false,
    ): float {
        $qb = $this->buildFilteredQueryBuilder($account, $category, $dateFrom, $dateTo, $amountMin, $amountMax, $search, $upcomingOnly, $currentMonthOnly, $excludeForecastExcluded);

        $total = $qb->select('SUM(t.amount)')->getQuery()->getSingleScalarResult();

        return null !== $total ? (float) $total : 0.0;
    }

    /**
     * @return Transaction[]
     */
    public function findRecent(?Account $account = null, int $limit = 10): array
    {
        // Transaction has no createdAt column, so the auto-increment id (which only ever
        // grows) is the reliable proxy for insertion order; t.date is a business date that
        // can be set in the past or future and doesn't reflect when a row was created.
        $qb = $this->createQueryBuilder('t')
            ->orderBy('t.id', 'DESC')
            ->setMaxResults($limit);

        if (null !== $account) {
            $qb->andWhere('t.account = :account')->setParameter('account', $account);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Transaction[]
     */
    public function findAllOrderedByDate(?Account $account = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->orderBy('t.date', 'ASC')
            ->addOrderBy('t.id', 'ASC');

        if (null !== $account) {
            $qb->andWhere('t.account = :account')->setParameter('account', $account);
        }

        return $qb->getQuery()->getResult();
    }

    public function findEarliestDate(?Account $account = null): ?\DateTimeImmutable
    {
        return $this->findAggregateDate('MIN(t.date)', $account);
    }

    public function findLatestDate(?Account $account = null): ?\DateTimeImmutable
    {
        return $this->findAggregateDate('MAX(t.date)', $account);
    }

    private function findAggregateDate(string $select, ?Account $account): ?\DateTimeImmutable
    {
        $qb = $this->createQueryBuilder('t')
            ->select($select);

        if (null !== $account) {
            $qb->andWhere('t.account = :account')->setParameter('account', $account);
        }

        $result = $qb->getQuery()->getSingleScalarResult();

        return null !== $result ? new \DateTimeImmutable($result) : null;
    }

    public function sumAll(?Account $account = null): float
    {
        $qb = $this->createQueryBuilder('t')
            ->select('SUM(t.amount)');

        if (null !== $account) {
            $qb->andWhere('t.account = :account')->setParameter('account', $account);
        }

        $total = $qb->getQuery()->getSingleScalarResult();

        return ($total !== null ? (float) $total : 0.0) + $this->getInitialBalanceOffset($account);
    }

    public function sumUntil(\DateTimeImmutable $until, ?Account $account = null): float
    {
        $qb = $this->createQueryBuilder('t')
            ->select('SUM(t.amount)')
            ->andWhere('t.date <= :until')
            ->setParameter('until', $until, Types::DATE_IMMUTABLE);

        if (null !== $account) {
            $qb->andWhere('t.account = :account')->setParameter('account', $account);
        }

        $total = $qb->getQuery()->getSingleScalarResult();

        return ($total !== null ? (float) $total : 0.0) + $this->getInitialBalanceOffset($account);
    }

    /**
     * Solde de départ à ajouter aux sommes de transactions : celui du compte donné,
     * ou la somme des soldes initiaux de tous les comptes si aucun compte n'est précisé.
     */
    public function getInitialBalanceOffset(?Account $account = null): float
    {
        if (null !== $account) {
            return null !== $account->getInitialBalance() ? (float) $account->getInitialBalance() : 0.0;
        }

        $total = $this->getEntityManager()->createQueryBuilder()
            ->select('SUM(a.initialBalance)')
            ->from(Account::class, 'a')
            ->getQuery()
            ->getSingleScalarResult();

        return $total !== null ? (float) $total : 0.0;
    }

    /**
     * @return Transaction[]
     */
    public function findRecurringTemplates(?Account $account = null, ?Recurrence $recurrence = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.recurrence != :none')
            ->andWhere('t.recurrenceParent IS NULL')
            ->setParameter('none', Recurrence::NONE);

        if (null !== $account) {
            $qb->andWhere('t.account = :account')->setParameter('account', $account);
        }

        if (null !== $recurrence) {
            $qb->andWhere('t.recurrence = :recurrence')->setParameter('recurrence', $recurrence);
        }

        return $qb->getQuery()->getResult();
    }

    public function findLastOccurrenceDate(Transaction $template): \DateTimeImmutable
    {
        $lastChildDate = $this->createQueryBuilder('t')
            ->select('MAX(t.date)')
            ->andWhere('t.recurrenceParent = :template')
            ->setParameter('template', $template)
            ->getQuery()
            ->getSingleScalarResult();

        if (null === $lastChildDate) {
            return $template->getDate();
        }

        $lastChildDate = new \DateTimeImmutable($lastChildDate);

        return $lastChildDate > $template->getDate() ? $lastChildDate : $template->getDate();
    }

    public function existsOccurrenceBetween(Transaction $template, \DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        $count = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.date >= :start')
            ->andWhere('t.date < :end')
            ->andWhere('t.id = :templateId OR t.recurrenceParent = :template')
            ->setParameter('start', $start, Types::DATE_IMMUTABLE)
            ->setParameter('end', $end, Types::DATE_IMMUTABLE)
            ->setParameter('templateId', $template->getId())
            ->setParameter('template', $template)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
