<?php

namespace App\Tests\Repository;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Category;
use App\Entity\Person;
use App\Entity\Transaction;
use App\Enum\Recurrence;
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

    public function testPersonFilterKeepsOnlyTheTransactionsOfThePersonsAccounts(): void
    {
        $suffix = uniqid();
        $alice = $this->createPerson('Alice '.$suffix);
        $bob = $this->createPerson('Bob '.$suffix);

        $aliceChecking = $this->createAccount('alice-1-'.$suffix, $alice);
        $aliceSavings = $this->createAccount('alice-2-'.$suffix, $alice);
        $bobAccount = $this->createAccount('bob-'.$suffix, $bob);
        $category = $this->createCategory('Tag '.$suffix);

        $first = $this->createTransaction($aliceChecking, $category, 'A1 '.$suffix, '2024-01-10', '-10.00');
        $second = $this->createTransaction($aliceSavings, $category, 'A2 '.$suffix, '2024-01-11', '-20.00');
        $this->createTransaction($bobAccount, $category, 'B1 '.$suffix, '2024-01-12', '-40.00');

        $this->entityManager->flush();

        $ids = array_map(static fn (Transaction $t) => $t->getId(), $this->transactionRepository->findFiltered(category: $category, sortField: 'id', sortDirection: 'asc', person: $alice));
        self::assertSame([$first->getId(), $second->getId()], $ids);
        self::assertSame(2, $this->transactionRepository->countFiltered(category: $category, person: $alice));
        self::assertSame(-30.0, $this->transactionRepository->sumFiltered(category: $category, person: $alice));
        self::assertCount(2, $this->transactionRepository->findFilteredForExport(category: $category, person: $alice));

        // Combined with an account filter: only that account of the person.
        self::assertSame(1, $this->transactionRepository->countFiltered(account: $aliceSavings, category: $category, person: $alice));
        // An account that doesn't belong to the person: nothing matches.
        self::assertSame(0, $this->transactionRepository->countFiltered(account: $bobAccount, category: $category, person: $alice));
        // Without a person filter, every account is included.
        self::assertSame(3, $this->transactionRepository->countFiltered(category: $category));
    }

    public function testFindBalanceMovementsUsesTheEffectiveDateAndOrder(): void
    {
        $suffix = uniqid();
        $account = $this->createAccount($suffix);
        $otherAccount = $this->createAccount('other-'.$suffix);
        $groceries = $this->createCategory('Groceries '.$suffix);
        $salary = $this->createCategory('Salary '.$suffix);

        $deferred = $this->createTransaction($account, $groceries, 'Deferred card', '2024-01-02', '-30.00');
        $deferred->setIsDeferredDebit(true)->setDebitDate(new \DateTimeImmutable('2024-02-05'));
        $this->createTransaction($account, $salary, 'Salary', '2024-01-25', '2000.50');
        $this->createTransaction($account, $groceries, 'Market', '2024-01-10', '-12.34');
        $this->createTransaction($otherAccount, $groceries, 'Not this account', '2024-01-15', '-99.00');

        $this->entityManager->flush();

        self::assertSame([
            ['date' => '2024-01-10', 'amount' => -12.34, 'categoryId' => $groceries->getId()],
            ['date' => '2024-01-25', 'amount' => 2000.5, 'categoryId' => $salary->getId()],
            // Deferred payment: placed on its actual debit date, not on its transaction date.
            ['date' => '2024-02-05', 'amount' => -30.0, 'categoryId' => $groceries->getId()],
        ], $this->transactionRepository->findBalanceMovements($account));
    }

    public function testBalancesCountADeferredPaymentOnItsDebitDate(): void
    {
        $suffix = uniqid();
        $today = new \DateTimeImmutable('today');
        $account = $this->createAccount($suffix);
        $category = $this->createCategory('Tag '.$suffix);

        // Paid yesterday by deferred card, debited next month: not in the current balance yet.
        $this->createTransaction($account, $category, 'Deferred', $today->modify('-1 day')->format('Y-m-d'), '-100.00')
            ->setIsDeferredDebit(true)->setDebitDate($today->modify('+1 month'));
        $this->createTransaction($account, $category, 'Cash', $today->modify('-1 day')->format('Y-m-d'), '-10.00');
        $this->entityManager->flush();

        self::assertSame(-10.0, $this->transactionRepository->sumUntil($today, $account));
        self::assertSame(-110.0, $this->transactionRepository->sumAll($account));
        self::assertSame(-110.0, $this->transactionRepository->sumUntil($today->modify('+1 month'), $account));
    }

    public function testDeferredDebitFilter(): void
    {
        $suffix = uniqid();
        $account = $this->createAccount($suffix);
        $category = $this->createCategory('Tag '.$suffix);
        $this->createTransaction($account, $category, 'Card', '2024-01-10', '-40.00')
            ->setIsDeferredDebit(true)->setDebitDate(new \DateTimeImmutable('2024-02-05'));
        $this->createTransaction($account, $category, 'Cash', '2024-01-10', '-5.00');
        $this->entityManager->flush();

        self::assertSame(1, $this->transactionRepository->countFiltered(account: $account, deferredDebitOnly: true));
        self::assertSame(-40.0, $this->transactionRepository->sumFiltered(account: $account, deferredDebitOnly: true));
        self::assertSame(2, $this->transactionRepository->countFiltered(account: $account));
    }

    public function testRecurringOnSundayOnlyListsRecurringTransactionsDatedOnASunday(): void
    {
        $suffix = uniqid();
        $account = $this->createAccount($suffix);
        $otherAccount = $this->createAccount('other-'.$suffix);
        $category = $this->createCategory('Tag '.$suffix);

        // 2024-03-03 and 2024-04-07 are Sundays, 2024-02-05 is a Monday.
        $template = $this->createTransaction($account, $category, 'Rent', '2024-02-05');
        $template->setRecurrence(Recurrence::MONTHLY);
        $sundayOccurrence = $this->createTransaction($account, $category, 'Rent', '2024-03-03');
        $sundayOccurrence->setRecurrence(Recurrence::MONTHLY)->setRecurrenceParent($template);
        $sundayTemplate = $this->createTransaction($account, $category, 'Gym', '2024-04-07');
        $sundayTemplate->setRecurrence(Recurrence::MONTHLY);
        // Dated on a Sunday but not recurring: ignored.
        $this->createTransaction($account, $category, 'One-off', '2024-03-10');
        // Recurring on a Sunday, but on another account.
        $this->createTransaction($otherAccount, $category, 'Other', '2024-03-03')->setRecurrence(Recurrence::MONTHLY);

        $this->entityManager->flush();

        $found = $this->transactionRepository->findRecurringOnSunday($account);
        self::assertSame(
            [$sundayOccurrence->getId(), $sundayTemplate->getId()],
            array_map(static fn (Transaction $t) => $t->getId(), $found),
            'Oldest first, templates and occurrences alike.',
        );
        self::assertSame(2, $this->transactionRepository->countRecurringOnSunday($account));
        self::assertSame(1, $this->transactionRepository->countRecurringOnSunday($otherAccount));
        self::assertGreaterThanOrEqual(3, $this->transactionRepository->countRecurringOnSunday());
        self::assertSame(\count($this->transactionRepository->findRecurringOnSunday()), $this->transactionRepository->countRecurringOnSunday());
    }

    public function testCheckingWhetherAnAccountOrTagHasTransactionsDoesNotLoadThemAll(): void
    {
        $suffix = uniqid();
        $account = $this->createAccount($suffix);
        $category = $this->createCategory('Tag '.$suffix);
        $emptyAccount = $this->createAccount('empty-'.$suffix);
        $this->createTransaction($account, $category, 'One', '2024-01-01');
        $this->createTransaction($account, $category, 'Two', '2024-01-02');
        $this->entityManager->flush();
        $this->entityManager->clear();

        $account = $this->entityManager->find(Account::class, $account->getId());
        $category = $this->entityManager->find(Category::class, $category->getId());
        $emptyAccount = $this->entityManager->find(Account::class, $emptyAccount->getId());

        self::assertCount(2, $account->getTransactions());
        self::assertFalse($account->getTransactions()->isEmpty());
        self::assertTrue($emptyAccount->getTransactions()->isEmpty());
        self::assertCount(2, $category->getTransactions());

        // Extra lazy collections: counted in SQL, the transactions themselves are never hydrated.
        self::assertFalse($account->getTransactions()->isInitialized());
        self::assertFalse($emptyAccount->getTransactions()->isInitialized());
        self::assertFalse($category->getTransactions()->isInitialized());
    }

    private function createPerson(string $name): Person
    {
        $person = new Person();
        $person->setName($name);
        $this->entityManager->persist($person);

        return $person;
    }

    private function createAccount(string $suffix, ?Person $person = null): Account
    {
        $type = new AccountType();
        $type->setName('Test type '.$suffix);
        $this->entityManager->persist($type);

        $account = new Account();
        $account->setLabel('Test account '.$suffix);
        $account->setType($type);
        $account->setPerson($person);
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
