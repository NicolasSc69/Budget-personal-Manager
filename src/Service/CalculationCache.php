<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Person;
use App\Entity\Transaction;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Caches the results of the balance / sum / report calculations, in one cache per entity.
 *
 * Each calculation declares the entities its result depends on (e.g. a balance depends on the
 * transactions and on the accounts' initial balances): the entry belongs to the cache of each of
 * them and is dropped as soon as one of these entities is created, updated or deleted (see
 * App\EventListener\CalculationCacheInvalidator), or when one of these caches is cleared from the
 * admin page. Entities no calculation depends on (account types, settings…) invalidate nothing.
 *
 * Keys include today's date, since many calculations depend on it ("until today", current month,
 * upcoming…), so a result computed yesterday is never served today.
 *
 * Results are also kept in memory for the current request (the dashboard reads the same history
 * several times), and that copy is dropped together with the persistent one.
 */
class CalculationCache implements ResetInterface
{
    public const TRANSACTION = 'transaction';
    public const ACCOUNT = 'account';
    public const CATEGORY = 'category';
    public const PERSON = 'person';

    /**
     * Cache of each entity, with its label on the admin page.
     */
    public const ENTITY_CACHES = [
        self::TRANSACTION => ['class' => Transaction::class, 'label' => 'Transactions'],
        self::ACCOUNT => ['class' => Account::class, 'label' => 'Accounts'],
        self::CATEGORY => ['class' => Category::class, 'label' => 'Tags'],
        self::PERSON => ['class' => Person::class, 'label' => 'People'],
    ];

    /**
     * Prefix of the per-entity index of the cached entries (only used to describe the caches on
     * the admin page: a tag-aware cache cannot list its items).
     */
    private const INDEX_PREFIX = 'index.';

    /**
     * @var array<string, array{value: mixed, entities: list<string>}>
     */
    private array $memory = [];

    public function __construct(private readonly TagAwareAdapterInterface&TagAwareCacheInterface $cache)
    {
    }

    /**
     * Returns the cached result of $name for $arguments, computing (and storing) it on a miss.
     *
     * @template T
     *
     * @param list<string>            $entities  Caches the result belongs to (self::TRANSACTION…): it is dropped when one of these entities changes
     * @param array<array-key, mixed> $arguments Entities are identified by their class and id, dates by their day
     * @param callable(): T           $compute
     *
     * @return T
     */
    public function get(string $name, array $entities, array $arguments, callable $compute): mixed
    {
        foreach ($entities as $entity) {
            if (!isset(self::ENTITY_CACHES[$entity])) {
                throw new \InvalidArgumentException(\sprintf('Unknown entity cache "%s".', $entity));
            }
        }

        $key = $this->key($name, $arguments);

        if (\array_key_exists($key, $this->memory)) {
            return $this->memory[$key]['value'];
        }

        $value = $this->cache->get($key, function (ItemInterface $item) use ($name, $entities, $compute, $key) {
            $item->tag($entities);
            $value = $compute();
            $this->addToIndexes($entities, $key, $name, \strlen(serialize($value)));

            return $value;
        });

        $this->memory[$key] = ['value' => $value, 'entities' => $entities];

        return $value;
    }

    /**
     * Drops the cached results depending on the given entities.
     *
     * @param list<string> $entities
     */
    public function invalidate(array $entities): void
    {
        $entities = array_values(array_intersect($entities, array_keys(self::ENTITY_CACHES)));
        if ([] === $entities) {
            return;
        }

        $this->memory = array_filter($this->memory, static fn (array $entry): bool => [] === array_intersect($entry['entities'], $entities));
        $this->cache->invalidateTags($entities);
        $this->cache->deleteItems(array_map(static fn (string $entity): string => self::INDEX_PREFIX.$entity, $entities));
    }

    /**
     * Drops every cached result.
     */
    public function invalidateAll(): void
    {
        $this->invalidate(array_keys(self::ENTITY_CACHES));
    }

    /**
     * Cache of the given entity class (or one of its parents / proxies), null if no calculation depends on it.
     */
    public function entityCacheOf(string $class): ?string
    {
        foreach (self::ENTITY_CACHES as $entity => $definition) {
            if (is_a($class, $definition['class'], true)) {
                return $entity;
            }
        }

        return null;
    }

    /**
     * Description of each entity cache, for the admin page.
     *
     * @return list<array{entity: string, label: string, entries: int, size: int, lastComputedAt: ?\DateTimeImmutable, calculations: array<string, int>}>
     */
    public function describe(): array
    {
        $caches = [];

        foreach (self::ENTITY_CACHES as $entity => $definition) {
            $entries = 0;
            $size = 0;
            $lastComputedAt = null;
            $calculations = [];

            foreach ($this->readIndex($entity) as $key => $entry) {
                // The index may still list entries dropped along with another entity's cache.
                if (!$this->cache->hasItem($key)) {
                    continue;
                }

                ++$entries;
                $size += $entry['size'];
                $lastComputedAt = max($lastComputedAt ?? 0, $entry['computedAt']);
                $calculations[$entry['name']] = ($calculations[$entry['name']] ?? 0) + 1;
            }

            ksort($calculations);

            $caches[] = [
                'entity' => $entity,
                'label' => $definition['label'],
                'entries' => $entries,
                'size' => $size,
                'lastComputedAt' => null !== $lastComputedAt ? (new \DateTimeImmutable())->setTimestamp($lastComputedAt) : null,
                'calculations' => $calculations,
            ];
        }

        return $caches;
    }

    /**
     * Forgets the in-memory copy between requests (long-running workers); the persistent cache is kept.
     */
    public function reset(): void
    {
        $this->memory = [];
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    public function key(string $name, array $arguments): string
    {
        $normalized = [(new \DateTimeImmutable('today'))->format('Y-m-d'), self::normalize($arguments)];

        return preg_replace('/[^A-Za-z0-9_.]/', '_', $name).'.'.hash('xxh128', serialize($normalized));
    }

    /**
     * @param list<string> $entities
     */
    private function addToIndexes(array $entities, string $key, string $name, int $size): void
    {
        // "App\Repository\TransactionRepository::sumAll" is shown as "TransactionRepository::sumAll".
        $shortName = substr($name, (int) strrpos('\\'.$name, '\\'));

        foreach ($entities as $entity) {
            $index = $this->readIndex($entity);
            $index[$key] = ['name' => $shortName, 'size' => $size, 'computedAt' => time()];

            $item = $this->cache->getItem(self::INDEX_PREFIX.$entity);
            $item->set($index);
            $this->cache->save($item);
        }
    }

    /**
     * @return array<string, array{name: string, size: int, computedAt: int}>
     */
    private function readIndex(string $entity): array
    {
        $index = $this->cache->getItem(self::INDEX_PREFIX.$entity)->get();

        return \is_array($index) ? $index : [];
    }

    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            \is_array($value) => array_map(self::normalize(...), $value),
            $value instanceof \DateTimeInterface => 'date:'.$value->format('Y-m-d'),
            $value instanceof \BackedEnum => $value::class.':'.$value->value,
            $value instanceof \UnitEnum => $value::class.':'.$value->name,
            \is_object($value) && method_exists($value, 'getId') => $value::class.'#'.$value->getId(),
            \is_object($value) => throw new \InvalidArgumentException(\sprintf('Cannot build a cache key from an instance of "%s".', $value::class)),
            default => $value,
        };
    }
}
