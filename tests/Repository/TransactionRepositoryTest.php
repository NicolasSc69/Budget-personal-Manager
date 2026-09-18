<?php

namespace App\Tests\Repository;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TransactionRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private TransactionRepository $transactionRepository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->transactionRepository = $this->entityManager->getRepository(Transaction::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->rollBack();
        $this->entityManager->close();

        parent::tearDown();
    }

    public function testFindFilteredForExportSortsByTagThenTitleThenDate(): void
    {
        $suffix = uniqid();

        $account = $this->createAccount($suffix);
        $tagA = $this->createCategory('AAA Tag '.$suffix);
        $tagB = $this->createCategory('BBB Tag '.$suffix);

        // Same tag (AAA), same title ("Alp"): the later date must sort after the earlier one.
        $alpLate = $this->createTransaction($account, $tagA, 'Alp', '2024-06-01');
        $alpEarly = $this->createTransaction($account, $tagA, 'Alp', '2024-01-01');
        // Same tag (AAA), different title: "Zed" sorts after "Alp" regardless of date.
        $zed = $this->createTransaction($account, $tagA, 'Zed', '2024-01-01');
        // Different tag (BBB): sorts after every AAA-tagged transaction.
        $other = $this->createTransaction($account, $tagB, 'Same', '2024-01-01');

        $this->entityManager->flush();

        $results = $this->transactionRepository->findFilteredForExport(account: $account);

        self::assertSame(
            [$alpEarly->getId(), $alpLate->getId(), $zed->getId(), $other->getId()],
            array_map(static fn (Transaction $t) => $t->getId(), $results),
        );
    }

    public function testSumFilteredExcludesForecastExcludedCategory(): void
    {
        $suffix = uniqid();

        $account = $this->createAccount($suffix);
        $normalCategory = $this->createCategory('Normal '.$suffix);
        $excludedCategory = $this->createCategory('Salary '.$suffix, excludedFromForecast: true);

        $this->createTransaction($account, $normalCategory, 'Groceries', '2024-01-10', '-50.00');
        $this->createTransaction($account, $excludedCategory, 'Salary', '2024-01-05', '-30.00');

        $this->entityManager->flush();

        self::assertSame(-80.0, $this->transactionRepository->sumFiltered(account: $account));
        self::assertSame(-50.0, $this->transactionRepository->sumFiltered(account: $account, excludeForecastExcluded: true));
    }

    public function testSumFilteredCombinesCurrentMonthOnlyAndUpcomingOnly(): void
    {
        $suffix = uniqid();
        $today = new \DateTimeImmutable('today');

        $account = $this->createAccount($suffix);
        $category = $this->createCategory('Tag '.$suffix);

        // Dated today (or earlier this month): counted by "current month" alone, but not "upcoming".
        $this->createTransaction($account, $category, 'Already due', $today->format('Y-m-d'), '-10.00');
        // Dated tomorrow: counted by both "current month" and "upcoming", *unless* the test happens to
        // run on the last calendar day of the month, in which case "tomorrow" rolls into next month and
        // this transaction is excluded from both sums instead — a known, accepted edge case given the
        // repository has no injectable clock to pin "today" to a fixed, mid-month date in tests.
        $this->createTransaction($account, $category, 'Still to come', $today->modify('+1 day')->format('Y-m-d'), '-20.00');
        // Two months out: never counted by "current month", regardless of the flags above.
        $this->createTransaction($account, $category, 'Far away', $today->modify('+2 months')->format('Y-m-d'), '-1000.00');

        $this->entityManager->flush();

        self::assertSame(-30.0, $this->transactionRepository->sumFiltered(account: $account, currentMonthOnly: true));
        self::assertSame(-20.0, $this->transactionRepository->sumFiltered(account: $account, currentMonthOnly: true, upcomingOnly: true));
    }

    private function createAccount(string $suffix): Account
    {
        $type = new AccountType();
        $type->setName('Test type '.$suffix);
        $this->entityManager->persist($type);

        $account = new Account();
        $account->setLabel('Test account '.$suffix);
        $account->setType($type);
        $this->entityManager->persist($account);

        return $account;
    }

    private function createCategory(string $name, bool $excludedFromForecast = false): Category
    {
        $category = new Category();
        $category->setName($name);
        $category->setExcludedFromForecast($excludedFromForecast);
        $this->entityManager->persist($category);

        return $category;
    }

    private function createTransaction(Account $account, Category $category, string $title, string $date, string $amount = '-10.00'): Transaction
    {
        $transaction = new Transaction();
        $transaction->setAccount($account);
        $transaction->setCategory($category);
        $transaction->setTitle($title);
        $transaction->setAmount($amount);
        $transaction->setDate(new \DateTimeImmutable($date));
        $this->entityManager->persist($transaction);

        return $transaction;
    }
}
