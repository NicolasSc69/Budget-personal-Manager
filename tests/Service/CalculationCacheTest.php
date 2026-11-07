<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AppSetting;
use App\Entity\Category;
use App\Entity\Person;
use App\Entity\Transaction;
use App\Enum\Recurrence;
use App\Service\CalculationCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

final class CalculationCacheTest extends TestCase
{
    private CalculationCache $cache;
    private int $calls = 0;

    protected function setUp(): void
    {
        $this->cache = new CalculationCache(new TagAwareAdapter(new ArrayAdapter()));
    }

    public function testComputesOnceThenServesTheCachedResult(): void
    {
        self::assertSame(1, $this->cache->get('sum', [CalculationCache::TRANSACTION], ['a' => 1], $this->counter(...)));
        self::assertSame(1, $this->cache->get('sum', [CalculationCache::TRANSACTION], ['a' => 1], $this->counter(...)));
        self::assertSame(1, $this->calls);
    }

    public function testDifferentNamesOrArgumentsGetTheirOwnEntry(): void
    {
        $this->cache->get('sum', [CalculationCache::TRANSACTION], ['a' => 1], $this->counter(...));
        $this->cache->get('sum', [CalculationCache::TRANSACTION], ['a' => 2], $this->counter(...));
        $this->cache->get('count', [CalculationCache::TRANSACTION], ['a' => 1], $this->counter(...));

        self::assertSame(3, $this->calls);
    }

    public function testInvalidatingAnEntityOnlyDropsTheResultsDependingOnIt(): void
    {
        $this->cache->get('balance', [CalculationCache::TRANSACTION, CalculationCache::ACCOUNT], [], $this->counter(...));
        $this->cache->get('byTag', [CalculationCache::TRANSACTION, CalculationCache::CATEGORY], [], $this->counter(...));

        $this->cache->invalidate([CalculationCache::CATEGORY]);

        self::assertSame(1, $this->cache->get('balance', [CalculationCache::TRANSACTION, CalculationCache::ACCOUNT], [], $this->counter(...)), 'kept');
        self::assertSame(3, $this->cache->get('byTag', [CalculationCache::TRANSACTION, CalculationCache::CATEGORY], [], $this->counter(...)), 'recomputed');

        $this->cache->invalidate([CalculationCache::TRANSACTION]);

        self::assertSame(4, $this->cache->get('balance', [CalculationCache::TRANSACTION, CalculationCache::ACCOUNT], [], $this->counter(...)));
        self::assertSame(5, $this->cache->get('byTag', [CalculationCache::TRANSACTION, CalculationCache::CATEGORY], [], $this->counter(...)));
    }

    public function testInvalidateAllDropsEveryEntry(): void
    {
        $this->cache->get('balance', [CalculationCache::ACCOUNT], [], $this->counter(...));
        $this->cache->get('people', [CalculationCache::PERSON], [], $this->counter(...));

        $this->cache->invalidateAll();

        self::assertSame(3, $this->cache->get('balance', [CalculationCache::ACCOUNT], [], $this->counter(...)));
        self::assertSame(4, $this->cache->get('people', [CalculationCache::PERSON], [], $this->counter(...)));
    }

    public function testInMemoryCopyIsServedWithoutHittingThePersistentCache(): void
    {
        $persistent = new TagAwareAdapter(new ArrayAdapter());
        $cache = new CalculationCache($persistent);
        $cache->get('sum', [CalculationCache::TRANSACTION], [], static fn () => 'value');

        // Emptied behind the service's back: the in-memory copy still answers…
        $persistent->clear();
        self::assertSame('value', $cache->get('sum', [CalculationCache::TRANSACTION], [], static fn () => 'recomputed'));

        // …until reset() (next request), which keeps the persistent cache only.
        $cache->reset();
        self::assertSame('recomputed', $cache->get('sum', [CalculationCache::TRANSACTION], [], static fn () => 'recomputed'));
    }

    public function testUnknownEntityCacheIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->cache->get('sum', ['unknown'], [], $this->counter(...));
    }

    public function testEntityClassesAreMappedToTheirCache(): void
    {
        self::assertSame(CalculationCache::TRANSACTION, $this->cache->entityCacheOf(Transaction::class));
        self::assertSame(CalculationCache::ACCOUNT, $this->cache->entityCacheOf(Account::class));
        self::assertSame(CalculationCache::CATEGORY, $this->cache->entityCacheOf(Category::class));
        self::assertSame(CalculationCache::PERSON, $this->cache->entityCacheOf(Person::class));
        // Doctrine proxies extend the entity class.
        self::assertSame(CalculationCache::ACCOUNT, $this->cache->entityCacheOf((new class extends Account {})::class));
        // No calculation depends on them.
        self::assertNull($this->cache->entityCacheOf(AccountType::class));
        self::assertNull($this->cache->entityCacheOf(AppSetting::class));
    }

    public function testDescribeDetailsEachEntityCache(): void
    {
        $this->cache->get('App\Repository\TransactionRepository::sumAll', [CalculationCache::TRANSACTION, CalculationCache::ACCOUNT], [1], static fn () => 1.5);
        $this->cache->get('App\Repository\TransactionRepository::sumAll', [CalculationCache::TRANSACTION, CalculationCache::ACCOUNT], [2], static fn () => 2.5);
        $this->cache->get('App\Service\BudgetForecastService::getCategoryBreakdown', [CalculationCache::TRANSACTION, CalculationCache::CATEGORY], [], static fn () => ['x']);

        $caches = array_column($this->cache->describe(), null, 'entity');

        self::assertSame([CalculationCache::TRANSACTION, CalculationCache::ACCOUNT, CalculationCache::CATEGORY, CalculationCache::PERSON], array_keys($caches));
        self::assertSame('Transactions', $caches[CalculationCache::TRANSACTION]['label']);
        self::assertSame(3, $caches[CalculationCache::TRANSACTION]['entries']);
        self::assertSame(['BudgetForecastService::getCategoryBreakdown' => 1, 'TransactionRepository::sumAll' => 2], $caches[CalculationCache::TRANSACTION]['calculations']);
        self::assertSame(['TransactionRepository::sumAll' => 2], $caches[CalculationCache::ACCOUNT]['calculations']);
        self::assertGreaterThan(0, $caches[CalculationCache::ACCOUNT]['size']);
        self::assertInstanceOf(\DateTimeImmutable::class, $caches[CalculationCache::ACCOUNT]['lastComputedAt']);
        self::assertSame(0, $caches[CalculationCache::PERSON]['entries']);
        self::assertNull($caches[CalculationCache::PERSON]['lastComputedAt']);

        // Clearing the tag cache also removes its entries from the transaction cache description.
        $this->cache->invalidate([CalculationCache::CATEGORY]);
        $caches = array_column($this->cache->describe(), null, 'entity');

        self::assertSame(0, $caches[CalculationCache::CATEGORY]['entries']);
        self::assertSame(['TransactionRepository::sumAll' => 2], $caches[CalculationCache::TRANSACTION]['calculations']);
        self::assertSame(2, $caches[CalculationCache::ACCOUNT]['entries']);
    }

    public function testEntitiesAreIdentifiedByTheirId(): void
    {
        $first = $this->account(1);
        $sameId = $this->account(1);
        $other = $this->account(2);

        self::assertSame($this->cache->key('sum', ['account' => $first]), $this->cache->key('sum', ['account' => $sameId]));
        self::assertNotSame($this->cache->key('sum', ['account' => $first]), $this->cache->key('sum', ['account' => $other]));
        self::assertNotSame($this->cache->key('sum', ['account' => $first]), $this->cache->key('sum', ['account' => null]));
    }

    public function testDatesAreIdentifiedByTheirDay(): void
    {
        self::assertSame(
            $this->cache->key('sum', [new \DateTimeImmutable('2024-05-01 08:00')]),
            $this->cache->key('sum', [new \DateTimeImmutable('2024-05-01 18:30')]),
        );
        self::assertNotSame(
            $this->cache->key('sum', [new \DateTimeImmutable('2024-05-01')]),
            $this->cache->key('sum', [new \DateTimeImmutable('2024-05-02')]),
        );
    }

    public function testEnumsAndNestedArraysAreSupported(): void
    {
        self::assertNotSame(
            $this->cache->key('sum', [[Recurrence::NONE]]),
            $this->cache->key('sum', [[Recurrence::MONTHLY]]),
        );
    }

    public function testKeysAreValidCacheKeys(): void
    {
        $key = $this->cache->key('App\Repository\TransactionRepository::sumAll', []);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_.]+$/', $key);
    }

    public function testUnidentifiableObjectsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->cache->key('sum', [new \stdClass()]);
    }

    private function counter(): int
    {
        return ++$this->calls;
    }

    private function account(int $id): Account
    {
        $account = new Account();
        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, $id);

        return $account;
    }
}
