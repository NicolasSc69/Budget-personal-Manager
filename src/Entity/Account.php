<?php

namespace App\Entity;

use App\Repository\AccountRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
class Account
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private ?string $label = null;

    #[ORM\ManyToOne(targetEntity: AccountType::class, inversedBy: 'accounts')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?AccountType $type = null;

    #[ORM\ManyToOne(targetEntity: Person::class, inversedBy: 'accounts')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Person $person = null;

    #[ORM\Column]
    private bool $isSavings = false;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $interestRate = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $maxAmount = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $initialBalance = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $averageSalary = null;

    /**
     * @var Collection<int, Transaction>
     */
    #[ORM\OneToMany(targetEntity: Transaction::class, mappedBy: 'account')]
    private Collection $transactions;

    public function __construct()
    {
        $this->transactions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getType(): ?AccountType
    {
        return $this->type;
    }

    public function setType(?AccountType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function __toString(): string
    {
        return $this->label ?? '';
    }

    public function getPerson(): ?Person
    {
        return $this->person;
    }

    public function setPerson(?Person $person): static
    {
        $this->person = $person;

        return $this;
    }

    public function isSavings(): bool
    {
        return $this->isSavings;
    }

    public function setIsSavings(bool $isSavings): static
    {
        $this->isSavings = $isSavings;

        return $this;
    }

    public function getInterestRate(): ?string
    {
        return $this->interestRate;
    }

    public function setInterestRate(?string $interestRate): static
    {
        $this->interestRate = $interestRate;

        return $this;
    }

    public function getMaxAmount(): ?string
    {
        return $this->maxAmount;
    }

    public function setMaxAmount(?string $maxAmount): static
    {
        $this->maxAmount = $maxAmount;

        return $this;
    }

    public function getInitialBalance(): ?string
    {
        return $this->initialBalance;
    }

    public function setInitialBalance(?string $initialBalance): static
    {
        $this->initialBalance = $initialBalance;

        return $this;
    }

    public function getAverageSalary(): ?string
    {
        return $this->averageSalary;
    }

    public function setAverageSalary(?string $averageSalary): static
    {
        $this->averageSalary = $averageSalary;

        return $this;
    }

    /**
     * @return Collection<int, Transaction>
     */
    public function getTransactions(): Collection
    {
        return $this->transactions;
    }
}
