<?php

namespace App\Tests\EventListener;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Category;
use App\Entity\Person;
use App\Entity\Transaction;
use App\Repository\AppSettingRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The cached calculations depending on an entity are dropped as soon as a flush creates, updates
 * or deletes one of them; the others are kept.
 *
 * Each test primes the cache, then changes the database twice: once with a raw SQL statement
 * (invisible to Doctrine, so it proves the cached value is served) and once through the entity
 * manager (which must invalidate the cache, making the raw change show up too).
 */
final class CalculationCacheInvalidatorTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private TransactionRepository $repository;
    private Account $account;
    private Category $category;
    private Person $person;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(TransactionRepository::class);
        $this->entityManager->getConnection()->beginTransaction();

        $this->person = (new Person())->setName('Person '.uniqid());
        $this->entityManager->persist($this->person);

        $type = (new AccountType())->setName('Type '.uniqid('', true));
        $this->entityManager->persist($type);

        $this->account = new Account();
        $this->account->setLabel('Account '.uniqid('', true));
        $this->account->setType($type);
        $this->account->setPerson($this->person);
        $this->account->setInitialBalance('0.00');
        $this->entityManager->persist($this->account);

        $this->category = (new Category())->setName('Tag '.uniqid('', true));
        $this->entityManager->persist($this->category);

        $this->createTransaction('-10.00');
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->rollBack();
        $this->entityManager->close();

        parent::tearDown();
    }

    public function testCachedSumIsServedUntilAnEntityChanges(): void
    {
        self::assertEqualsWithDelta(-10.0, $this->repository->sumAll($this->account), 0.001);

        $this->rawUpdateAmounts('-30');

        self::assertEqualsWithDelta(-10.0, $this->repository->sumAll($this->account), 0.001, 'served from the cache');
    }

    public function testFlushWithoutChangesKeepsTheCache(): void
    {
        $this->repository->sumAll($this->account);
        $this->rawUpdateAmounts('-30');

        $this->entityManager->flush();

        self::assertEqualsWithDelta(-10.0, $this->repository->sumAll($this->account), 0.001);
    }

    public function testCreatingATransactionInvalidates(): void
    {
        $this->repository->sumAll($this->account);

        $this->createTransaction('-5.00');
        $this->entityManager->flush();

        self::assertEqualsWithDelta(-15.0, $this->repository->sumAll($this->account), 0.001);
    }

    public function testUpdatingATransactionInvalidates(): void
    {
        $transaction = $this->createTransaction('-5.00');
        $this->entityManager->flush();
        self::assertEqualsWithDelta(-15.0, $this->repository->sumAll($this->account), 0.001);

        $transaction->setAmount('-50.00');
        $this->entityManager->flush();

        self::assertEqualsWithDelta(-60.0, $this->repository->sumAll($this->account), 0.001);
    }

    public function testDeletingATransactionInvalidates(): void
    {
        $transaction = $this->createTransaction('-5.00');
        $this->entityManager->flush();
        self::assertEqualsWithDelta(-15.0, $this->repository->sumAll($this->account), 0.001);

        $this->entityManager->remove($transaction);
        $this->entityManager->flush();

        self::assertEqualsWithDelta(-10.0, $this->repository->sumAll($this->account), 0.001);
    }

    public function testUpdatingAnAccountInvalidates(): void
    {
        // Every account: the initial balances come from the accounts table, not from the transactions.
        $before = $this->repository->sumAll();

        $this->account->setInitialBalance('100.00');
        $this->entityManager->flush();

        self::assertEqualsWithDelta($before + 100.0, $this->repository->sumAll(), 0.001);
    }

    public function testUpdatingATagInvalidates(): void
    {
        $sum = fn () => $this->repository->sumFiltered(account: $this->account, excludeForecastExcluded: true);
        self::assertEqualsWithDelta(-10.0, $sum(), 0.001);

        $this->category->setExcludedFromForecast(true);
        $this->entityManager->flush();

        self::assertEqualsWithDelta(0.0, $sum(), 0.001);
    }

    public function testChangingAPersonsAccountsInvalidates(): void
    {
        $sum = fn () => $this->repository->sumFiltered(person: $this->person);
        self::assertEqualsWithDelta(-10.0, $sum(), 0.001);

        $this->account->setPerson(null);
        $this->entityManager->flush();

        self::assertEqualsWithDelta(0.0, $sum(), 0.001);
    }

    /**
     * Each change drops the calculations depending on the changed entity only: the raw update made
     * in between shows up in those, while the others are still served from the cache.
     *
     * @param array{sumAll: bool, byPerson: bool, byTag: bool} $recomputed
     */
    #[DataProvider('entityChanges')]
    public function testOnlyTheCalculationsDependingOnTheChangedEntityAreRecomputed(string $change, array $recomputed): void
    {
        $sumAll = fn () => $this->repository->sumAll($this->account);
        $byPerson = fn () => $this->repository->sumFiltered(person: $this->person);
        $byTag = fn () => $this->repository->sumFiltered(category: $this->category);
        $sumAll();
        $byPerson();
        $byTag();

        $this->rawUpdateAmounts('-30');

        match ($change) {
            'transaction' => $this->createTransaction('0.00'),
            'account' => $this->account->setLabel('Renamed '.uniqid('', true)),
            'person' => $this->person->setName('Renamed '.uniqid()),
            'tag' => $this->category->setColor('#123456'),
            'account type' => $this->account->getType()->setName('Renamed '.uniqid('', true)),
            'settings' => self::getContainer()->get(AppSettingRepository::class)->getSettings()->setItemsPerPage(42),
        };
        $this->entityManager->flush();

        self::assertEqualsWithDelta($recomputed['sumAll'] ? -30.0 : -10.0, $sumAll(), 0.001, 'sumAll');
        self::assertEqualsWithDelta($recomputed['byPerson'] ? -30.0 : -10.0, $byPerson(), 0.001, 'sum by person');
        self::assertEqualsWithDelta($recomputed['byTag'] ? -30.0 : -10.0, $byTag(), 0.001, 'sum by tag');
    }

    /**
     * @return iterable<string, array{string, array{sumAll: bool, byPerson: bool, byTag: bool}}>
     */
    public static function entityChanges(): iterable
    {
        // sumAll depends on transactions and accounts, the filtered sums on transactions, accounts, tags and people.
        yield 'transaction' => ['transaction', ['sumAll' => true, 'byPerson' => true, 'byTag' => true]];
        yield 'account' => ['account', ['sumAll' => true, 'byPerson' => true, 'byTag' => true]];
        yield 'person' => ['person', ['sumAll' => false, 'byPerson' => true, 'byTag' => true]];
        yield 'tag' => ['tag', ['sumAll' => false, 'byPerson' => true, 'byTag' => true]];
        // No calculation depends on them.
        yield 'account type' => ['account type', ['sumAll' => false, 'byPerson' => false, 'byTag' => false]];
        yield 'settings' => ['settings', ['sumAll' => false, 'byPerson' => false, 'byTag' => false]];
    }

    private function createTransaction(string $amount): Transaction
    {
        $transaction = new Transaction();
        $transaction->setAccount($this->account);
        $transaction->setCategory($this->category);
        $transaction->setTitle('Tx '.uniqid('', true));
        $transaction->setAmount($amount);
        $transaction->setDate(new \DateTimeImmutable('2024-05-01'));
        $this->entityManager->persist($transaction);

        return $transaction;
    }

    private function rawUpdateAmounts(string $amount): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE transactions SET amount = ? WHERE account_id = ?',
            [$amount, $this->account->getId()],
        );
    }
}
