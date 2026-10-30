<?php

namespace App\Entity;

use App\Enum\Recurrence;
use App\Repository\TransactionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\Table(name: 'transactions')]
class Transaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\NotNull]
    #[Assert\NotEqualTo(0, message: 'The amount cannot be equal to 0.')]
    private ?string $amount = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $date = null;

    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'transactions')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Category $category = null;

    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: 'transactions')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Account $account = null;

    #[ORM\Column(length: 20, enumType: Recurrence::class)]
    private Recurrence $recurrence = Recurrence::NONE;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $recurrenceEndDate = null;

    /**
     * On a recurring template: date up to which occurrences have already been generated, so that
     * an occurrence deleted by the user is not generated again.
     */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $recurrenceGeneratedUntil = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isCheque = false;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $chequeNumber = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isDeferredDebit = false;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $debitDate = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'recurrenceChildren')]
    #[ORM\JoinColumn(name: 'recurrence_parent_id', nullable: true, onDelete: 'SET NULL')]
    private ?self $recurrenceParent = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'destination_account_id', nullable: true, onDelete: 'SET NULL')]
    private ?Account $destinationAccount = null;

    /**
     * @var Collection<int, self>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'recurrenceParent')]
    private Collection $recurrenceChildren;

    public function __construct()
    {
        $this->recurrenceChildren = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(?Account $account): static
    {
        $this->account = $account;

        return $this;
    }

    public function getRecurrence(): Recurrence
    {
        return $this->recurrence;
    }

    public function setRecurrence(?Recurrence $recurrence): static
    {
        $this->recurrence = $recurrence ?? Recurrence::NONE;

        return $this;
    }

    public function isCheque(): bool
    {
        return $this->isCheque;
    }

    public function setIsCheque(bool $isCheque): static
    {
        $this->isCheque = $isCheque;

        return $this;
    }

    public function getChequeNumber(): ?string
    {
        return $this->chequeNumber;
    }

    public function setChequeNumber(?string $chequeNumber): static
    {
        $this->chequeNumber = $chequeNumber;

        return $this;
    }

    public function isDeferredDebit(): bool
    {
        return $this->isDeferredDebit;
    }

    public function setIsDeferredDebit(bool $isDeferredDebit): static
    {
        $this->isDeferredDebit = $isDeferredDebit;

        return $this;
    }

    public function getDebitDate(): ?\DateTimeImmutable
    {
        return $this->debitDate;
    }

    public function setDebitDate(?\DateTimeImmutable $debitDate): static
    {
        $this->debitDate = $debitDate;

        return $this;
    }

    /**
     * Date the amount actually hits the account balance: the actual debit date for a deferred
     * payment, the transaction date otherwise.
     */
    public function getEffectiveDate(): ?\DateTimeImmutable
    {
        return $this->debitDate ?? $this->date;
    }

    /**
     * Deferred debit card: transactions made from the 21st of the previous month up to the
     * 20th of the current month are debited on the 5th of the next month; transactions made
     * after the 20th are debited on the 5th of the month after next.
     *
     * Keep in sync with computeDebitDate() in templates/transaction/_form.html.twig.
     */
    public static function computeDeferredDebitDate(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $monthsAhead = (int) $date->format('j') <= 20 ? 1 : 2;

        return $date->modify('first day of this month')->modify(sprintf('+%d months +4 days', $monthsAhead));
    }

    public function isRecurring(): bool
    {
        return Recurrence::NONE !== $this->recurrence;
    }

    public function getRecurrenceEndDate(): ?\DateTimeImmutable
    {
        return $this->recurrenceEndDate;
    }

    public function setRecurrenceEndDate(?\DateTimeImmutable $recurrenceEndDate): static
    {
        $this->recurrenceEndDate = $recurrenceEndDate;

        return $this;
    }

    public function getRecurrenceGeneratedUntil(): ?\DateTimeImmutable
    {
        return $this->recurrenceGeneratedUntil;
    }

    public function setRecurrenceGeneratedUntil(?\DateTimeImmutable $recurrenceGeneratedUntil): static
    {
        $this->recurrenceGeneratedUntil = $recurrenceGeneratedUntil;

        return $this;
    }

    public function getRecurrenceParent(): ?self
    {
        return $this->recurrenceParent;
    }

    public function setRecurrenceParent(?self $recurrenceParent): static
    {
        $this->recurrenceParent = $recurrenceParent;

        return $this;
    }

    public function isGeneratedFromRecurrence(): bool
    {
        return null !== $this->recurrenceParent;
    }

    public function getDestinationAccount(): ?Account
    {
        return $this->destinationAccount;
    }

    public function setDestinationAccount(?Account $destinationAccount): static
    {
        $this->destinationAccount = $destinationAccount;

        return $this;
    }

    public function isUpcoming(): bool
    {
        return null !== $this->date && $this->date > new \DateTimeImmutable('today');
    }

    /**
     * @return Collection<int, self>
     */
    public function getRecurrenceChildren(): Collection
    {
        return $this->recurrenceChildren;
    }
}
