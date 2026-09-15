<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Enum\Recurrence;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;

class RecurringTransactionGenerator
{
    private const MAX_OCCURRENCES_PER_TEMPLATE = 240;

    public function __construct(
        private readonly TransactionRepository $transactionRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function generateDueOccurrences(): int
    {
        $today = new \DateTimeImmutable('today');
        $generated = 0;

        foreach ($this->transactionRepository->findRecurringTemplates() as $template) {
            $nextDate = $this->addInterval($this->transactionRepository->findLastOccurrenceDate($template), $template->getRecurrence());

            $iterations = 0;
            while ($nextDate <= $today && $iterations < self::MAX_OCCURRENCES_PER_TEMPLATE) {
                $this->entityManager->persist($this->createOccurrence($template, $nextDate));
                ++$generated;
                ++$iterations;

                $nextDate = $this->addInterval($nextDate, $template->getRecurrence());
            }
        }

        if ($generated > 0) {
            $this->entityManager->flush();
        }

        return $generated;
    }

    /**
     * Recurring templates that don't yet have any occurrence falling within next
     * calendar month (relative to today). Only applies to monthly recurrences: the
     * "next month" check has no meaningful equivalent for quarterly/semiannual/yearly cadences.
     *
     * @return list<array{template: Transaction, lastOccurrenceDate: \DateTimeImmutable, nextDate: \DateTimeImmutable}>
     */
    public function findMonthlyTemplatesPendingNextMonth(?Account $account = null): array
    {
        $nextMonthStart = (new \DateTimeImmutable('today'))->modify('first day of next month');
        $monthAfterStart = $nextMonthStart->modify('+1 month');

        $pending = [];

        foreach ($this->transactionRepository->findRecurringTemplates($account, Recurrence::MONTHLY) as $template) {
            if ($this->transactionRepository->existsOccurrenceBetween($template, $nextMonthStart, $monthAfterStart)) {
                continue;
            }

            $lastOccurrenceDate = $this->transactionRepository->findLastOccurrenceDate($template);
            $nextDate = $lastOccurrenceDate->modify('+1 month');
            while ($nextDate < $nextMonthStart) {
                $nextDate = $nextDate->modify('+1 month');
            }

            if ($nextDate >= $monthAfterStart) {
                continue;
            }

            if (null !== $template->getRecurrenceEndDate() && $nextDate > $template->getRecurrenceEndDate()) {
                continue;
            }

            $pending[] = [
                'template' => $template,
                'lastOccurrenceDate' => $lastOccurrenceDate,
                'nextDate' => $nextDate,
            ];
        }

        return $pending;
    }

    /**
     * @param list<int>                  $templateIds
     * @param array<int|string, string>  $titles      custom title per template id, keyed like the id in $templateIds
     * @param array<int|string, string>  $categories  custom category id per template id, keyed like the id in $templateIds
     */
    public function duplicateMonthlyTemplatesToNextMonth(array $templateIds, array $titles = [], array $categories = []): int
    {
        $duplicated = 0;

        foreach ($this->findMonthlyTemplatesPendingNextMonth() as $pending) {
            $templateId = $pending['template']->getId();

            if (!in_array($templateId, $templateIds, true)) {
                continue;
            }

            $customTitle = trim((string) ($titles[$templateId] ?? ''));

            $categoryOverride = null;
            $customCategoryId = $categories[$templateId] ?? null;
            if (null !== $customCategoryId && '' !== $customCategoryId) {
                $categoryOverride = $this->categoryRepository->find((int) $customCategoryId);
            }

            $this->entityManager->persist($this->createOccurrence($pending['template'], $pending['nextDate'], '' !== $customTitle ? $customTitle : null, $categoryOverride));
            ++$duplicated;
        }

        if ($duplicated > 0) {
            $this->entityManager->flush();
        }

        return $duplicated;
    }

    private function createOccurrence(Transaction $template, \DateTimeImmutable $date, ?string $titleOverride = null, ?Category $categoryOverride = null): Transaction
    {
        $occurrence = new Transaction();
        $occurrence->setTitle($titleOverride ?? $template->getTitle());
        $occurrence->setAmount($template->getAmount());
        $occurrence->setDate($date);
        $occurrence->setCategory($categoryOverride ?? $template->getCategory());
        $occurrence->setAccount($template->getAccount());
        $occurrence->setRecurrence($template->getRecurrence());
        $occurrence->setRecurrenceEndDate($template->getRecurrenceEndDate());
        $occurrence->setIsCheque($template->isCheque());
        $occurrence->setChequeNumber($template->getChequeNumber());
        $occurrence->setDestinationAccount($template->getDestinationAccount());
        $occurrence->setRecurrenceParent($template);

        if (null !== $occurrence->getDestinationAccount()) {
            $this->entityManager->persist($this->createTransferMirror($occurrence, $occurrence->getDestinationAccount()));
        }

        return $occurrence;
    }

    private function createTransferMirror(Transaction $origin, Account $destinationAccount): Transaction
    {
        $mirror = new Transaction();
        $mirror->setTitle($origin->getTitle());
        $mirror->setAmount((string) (-(float) $origin->getAmount()));
        $mirror->setDate($origin->getDate());
        $mirror->setCategory($origin->getCategory());
        $mirror->setAccount($destinationAccount);

        return $mirror;
    }

    private function addInterval(\DateTimeImmutable $date, Recurrence $recurrence): \DateTimeImmutable
    {
        return match ($recurrence) {
            Recurrence::MONTHLY => $date->modify('+1 month'),
            Recurrence::QUARTERLY => $date->modify('+3 months'),
            Recurrence::SEMIANNUAL => $date->modify('+6 months'),
            Recurrence::YEARLY => $date->modify('+1 year'),
            Recurrence::NONE => $date,
        };
    }
}
