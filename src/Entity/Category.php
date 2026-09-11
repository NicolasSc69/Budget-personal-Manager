<?php

namespace App\Entity;

use App\Repository\CategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[UniqueEntity(
    fields: ['name', 'color', 'parent'],
    message: 'A tag with this name, color and parent tag already exists.',
    errorPath: 'name',
    ignoreNull: false,
)]
class Category
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private ?string $name = null;

    #[ORM\Column(length: 7)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/', message: 'The color must be in hexadecimal format, e.g.: #FF5733.')]
    private string $color = '#6c757d';

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true, onDelete: 'SET NULL')]
    private ?self $parent = null;

    #[ORM\Column]
    private bool $excludedFromForecast = false;

    /**
     * @var Collection<int, self>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    private Collection $children;

    /**
     * @var Collection<int, Transaction>
     */
    #[ORM\OneToMany(targetEntity: Transaction::class, mappedBy: 'category', orphanRemoval: false)]
    private Collection $transactions;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->transactions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name ?? '';
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    public function isExcludedFromForecast(): bool
    {
        return $this->excludedFromForecast;
    }

    public function setExcludedFromForecast(bool $excludedFromForecast): static
    {
        $this->excludedFromForecast = $excludedFromForecast;

        return $this;
    }

    /**
     * @return Collection<int, self>
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function isRoot(): bool
    {
        return null === $this->parent;
    }

    /**
     * @return list<self>
     */
    public function getAncestors(): array
    {
        $ancestors = [];
        $current = $this->parent;

        while (null !== $current) {
            $ancestors[] = $current;
            $current = $current->getParent();
        }

        return array_reverse($ancestors);
    }

    public function getFullName(): string
    {
        $segments = array_map(static fn (self $category) => $category->getName(), $this->getAncestors());
        $segments[] = $this->name ?? '';

        return implode(' › ', $segments);
    }

    /**
     * This category's id plus every descendant's id (children, grandchildren, ...), used so that
     * filtering by a parent category also includes its sub-categories.
     *
     * @return list<int>
     */
    public function getSelfAndDescendantIds(): array
    {
        $ids = [$this->id];

        foreach ($this->children as $child) {
            $ids = [...$ids, ...$child->getSelfAndDescendantIds()];
        }

        return $ids;
    }

    /**
     * @return Collection<int, Transaction>
     */
    public function getTransactions(): Collection
    {
        return $this->transactions;
    }
}
