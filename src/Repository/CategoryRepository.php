<?php

namespace App\Repository;

use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * Returns all categories ordered so that each parent is followed by its children (depth-first).
     * When $search is given, categories whose name doesn't match are dropped from the result, but
     * the tree is still built from the full set first so matching descendants keep their indentation
     * even if an ancestor's name doesn't match.
     *
     * @return list<Category>
     */
    public function findAllOrderedByHierarchy(?string $search = null): array
    {
        $all = $this->findBy([], ['name' => 'ASC']);

        $byParent = [];
        foreach ($all as $category) {
            $byParent[$category->getParent()?->getId() ?? 0][] = $category;
        }

        $ordered = [];
        $visit = function (int $parentId) use (&$visit, &$byParent, &$ordered): void {
            foreach ($byParent[$parentId] ?? [] as $category) {
                $ordered[] = $category;
                $visit($category->getId());
            }
        };
        $visit(0);

        if (null !== $search && '' !== $search) {
            $needle = mb_strtolower($search);
            $ordered = array_values(array_filter(
                $ordered,
                static fn (Category $category) => str_contains(mb_strtolower($category->getName()), $needle)
            ));
        }

        return $ordered;
    }

    /**
     * Categories that can be assigned as parent of $exclude (itself and its descendants are removed to avoid cycles).
     *
     * @return list<Category>
     */
    public function findAssignableParents(?Category $exclude): array
    {
        $all = $this->findAllOrderedByHierarchy();

        if (null === $exclude) {
            return $all;
        }

        $excludedIds = [$exclude->getId()];
        $collect = function (Category $category) use (&$collect, &$excludedIds): void {
            foreach ($category->getChildren() as $child) {
                $excludedIds[] = $child->getId();
                $collect($child);
            }
        };
        $collect($exclude);

        return array_values(array_filter($all, static fn (Category $category) => !in_array($category->getId(), $excludedIds, true)));
    }
}
