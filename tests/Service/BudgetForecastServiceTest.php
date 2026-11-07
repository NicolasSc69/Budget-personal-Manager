<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Service\BudgetForecastService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BudgetForecastServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private BudgetForecastService $forecastService;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->forecastService = self::getContainer()->get(BudgetForecastService::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->rollBack();
        $this->entityManager->close();

        parent::tearDown();
    }

    public function testMonthlyReportNetsRefundsPerTagAndCarriesTheBalance(): void
    {
        $account = $this->createAccount(initialBalance: '1000.00');
        $groceries = $this->createCategory('Groceries');
        $salary = $this->createCategory('Salary');

        // Before the window: only counts in the starting balance.
        $this->createTransaction($account, $groceries, '2023-12-15', '-100.00');
        // January: a refund offsets its tag's expense instead of counting as income.
        $this->createTransaction($account, $groceries, '2024-01-05', '-80.00');
        $this->createTransaction($account, $groceries, '2024-01-20', '30.00');
        $this->createTransaction($account, $salary, '2024-01-28', '2000.00');
        // Deferred payment made in January but debited in February: counted in February.
        $this->createTransaction($account, $groceries, '2024-01-25', '-40.00', debitDate: '2024-02-05');
        // After the window: ignored.
        $this->createTransaction($account, $groceries, '2024-04-01', '-500.00');
        $this->entityManager->flush();

        $report = $this->forecastService->getMonthlyReport(new \DateTimeImmutable('2024-01-01'), new \DateTimeImmutable('2024-03-31'), $account);

        self::assertSame(['2024-01', '2024-02', '2024-03'], array_column($report, 'key'));

        self::assertEqualsWithDelta(2000.0, $report[0]['income'], 0.001);
        self::assertEqualsWithDelta(50.0, $report[0]['expense'], 0.001);
        self::assertEqualsWithDelta(1950.0, $report[0]['net'], 0.001);
        self::assertEqualsWithDelta(1000 - 100 - 80 + 30 + 2000, $report[0]['balance'], 0.001);

        self::assertEqualsWithDelta(0.0, $report[1]['income'], 0.001);
        self::assertEqualsWithDelta(40.0, $report[1]['expense'], 0.001);
        self::assertEqualsWithDelta(2810.0, $report[1]['balance'], 0.001);

        // No movement in March: the balance is carried over.
        self::assertEqualsWithDelta(0.0, $report[2]['expense'], 0.001);
        self::assertEqualsWithDelta(2810.0, $report[2]['balance'], 0.001);
    }

    public function testDailyBalancesStartFromThePreviousBalanceAndCarryOver(): void
    {
        $account = $this->createAccount(initialBalance: '100.00');
        $category = $this->createCategory('Misc');

        $this->createTransaction($account, $category, '2024-01-01', '-10.00');
        $this->createTransaction($account, $category, '2024-01-03', '-5.00');
        $this->createTransaction($account, $category, '2024-01-03', '20.00');
        $this->createTransaction($account, $category, '2024-01-10', '-1.00');
        $this->entityManager->flush();

        self::assertSame([
            ['date' => '2024-01-02', 'balance' => 90.0],
            ['date' => '2024-01-03', 'balance' => 105.0],
            ['date' => '2024-01-04', 'balance' => 105.0],
        ], $this->forecastService->getDailyBalances(new \DateTimeImmutable('2024-01-02'), new \DateTimeImmutable('2024-01-04'), $account));
    }

    public function testReportsAreCachedUntilAnEntityChanges(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory('Misc');
        $this->createTransaction($account, $category, '2024-01-05', '-10.00');
        $this->entityManager->flush();

        $since = new \DateTimeImmutable('2024-01-01');
        $until = new \DateTimeImmutable('2024-01-31');

        self::assertEqualsWithDelta(10.0, $this->forecastService->getMonthlyReport($since, $until, $account)[0]['expense'], 0.001);
        self::assertEqualsWithDelta(-10.0, $this->forecastService->getDailyBalances($since, $until, $account)[9]['balance'], 0.001);

        // Written without Doctrine (no flush event): the cached figures are still served.
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE transactions SET amount = -99 WHERE account_id = ?',
            [$account->getId()],
        );
        self::assertEqualsWithDelta(10.0, $this->forecastService->getMonthlyReport($since, $until, $account)[0]['expense'], 0.001);

        // Any flush that changes an entity drops them: the new transaction and the raw update show up.
        $this->createTransaction($account, $category, '2024-01-06', '-5.00');
        $this->entityManager->flush();

        self::assertEqualsWithDelta(104.0, $this->forecastService->getMonthlyReport($since, $until, $account)[0]['expense'], 0.001);
        self::assertEqualsWithDelta(-104.0, $this->forecastService->getDailyBalances($since, $until, $account)[9]['balance'], 0.001);
    }

    public function testEachAccountHasItsOwnHistory(): void
    {
        $first = $this->createAccount();
        $second = $this->createAccount();
        $category = $this->createCategory('Misc');
        $this->createTransaction($first, $category, '2024-01-05', '-10.00');
        $this->createTransaction($second, $category, '2024-01-05', '-70.00');
        $this->entityManager->flush();

        $since = new \DateTimeImmutable('2024-01-01');
        $until = new \DateTimeImmutable('2024-01-31');

        self::assertEqualsWithDelta(10.0, $this->forecastService->getMonthlyReport($since, $until, $first)[0]['expense'], 0.001);
        self::assertEqualsWithDelta(70.0, $this->forecastService->getMonthlyReport($since, $until, $second)[0]['expense'], 0.001);
    }

    private function createAccount(?string $initialBalance = null): Account
    {
        $suffix = uniqid('', true);

        $type = new AccountType();
        $type->setName('Test type '.$suffix);
        $this->entityManager->persist($type);

        $account = new Account();
        $account->setLabel('Test account '.$suffix);
        $account->setType($type);
        $account->setInitialBalance($initialBalance);
        $this->entityManager->persist($account);

        return $account;
    }

    private function createCategory(string $name): Category
    {
        $category = new Category();
        $category->setName($name.' '.uniqid('', true));
        $this->entityManager->persist($category);

        return $category;
    }

    private function createTransaction(Account $account, Category $category, string $date, string $amount, ?string $debitDate = null): Transaction
    {
        $transaction = new Transaction();
        $transaction->setAccount($account);
        $transaction->setCategory($category);
        $transaction->setTitle('Test');
        $transaction->setAmount($amount);
        $transaction->setDate(new \DateTimeImmutable($date));
        if (null !== $debitDate) {
            $transaction->setIsDeferredDebit(true);
            $transaction->setDebitDate(new \DateTimeImmutable($debitDate));
        }
        $this->entityManager->persist($transaction);

        return $transaction;
    }
}
