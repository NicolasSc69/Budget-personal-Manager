<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Enum\Recurrence;
use App\Repository\AppSettingRepository;
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
        private readonly AppSettingRepository $appSettingRepository,
    ) {
    }

    public function generateDueOccurrences(): int
    {
        $today = new \DateTimeImmutable('today');
        $generated = 0;

        $changed = false;

        foreach ($this->transactionRepository->findRecurringTemplates() as $template) {
            $lastOccurrenceDate = $this->transactionRepository->findLastOccurrenceDate($template);
            // Remember the existing occurrences, so deleting the latest one doesn't make it come back.
            $changed = $this->markGeneratedUntil($template, $lastOccurrenceDate) || $changed;

            $nextDate = $this->addInterval($template->getRecurrenceGeneratedUntil(), $template->getRecurrence());

            $iterations = 0;
            while ($nextDate <= $today && $iterations < self::MAX_OCCURRENCES_PER_TEMPLATE) {
                $this->entityManager->persist($this->createOccurrence($template, $nextDate));
                ++$generated;
                ++$iterations;

                $nextDate = $this->addInterval($nextDate, $template->getRecurrence());
            }
        }

        if ($generated > 0 || $changed) {
            $this->entityManager->flush();
        }

        return $generated;
    }

    /**
     * Recurring templates that don't yet have any occurrence falling within next
     * calendar month (relative to today). Only applies to monthly recurrences: the
     * "next month" check has no meaningful equivalent for quarterly/semiannual/yearly cadences.
     * Nothing is pending before the check day of the month configured in the settings.
     *
     * @return list<array{template: Transaction, lastOccurrenceDate: \DateTimeImmutable, nextDate: \DateTimeImmutable}>
     */
    public function findMonthlyTemplatesPendingNextMonth(?Account $account = null): array
    {
        $today = new \DateTimeImmutable('today');

        if (!self::isCheckDayReached($today, $this->appSettingRepository->findExistingSettings()?->getRecurringCheckDay() ?? 1)) {
            return [];
        }

        $pending = [];

        foreach ($this->transactionRepository->findRecurringTemplates($account, Recurrence::MONTHLY) as $template) {
            // The day of month comes from the template, so a Sunday->Monday shift on one
            // occurrence doesn't drift the following ones.
            [
                'nextDate' => $nextDate,
                'windowStart' => $windowStart,
                'windowEnd' => $windowEnd,
            ] = self::computeNextMonthOccurrence($today, (int) $template->getDate()->format('j'));

            if ($this->transactionRepository->existsOccurrenceBetween($template, $windowStart, $windowEnd)) {
                continue;
            }

            $lastOccurrenceDate = $this->transactionRepository->findLastOccurrenceDate($template);
            if ($lastOccurrenceDate >= $windowEnd) {
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

    /**
     * Moves the given recurring transactions from Sunday to the following Monday. Ids that
     * are no longer a recurring transaction dated on a Sunday are ignored.
     *
     * @param list<int> $transactionIds
     */
    public function shiftSundayOccurrencesToMonday(array $transactionIds): int
    {
        $shifted = 0;

        foreach ($this->transactionRepository->findRecurringOnSunday() as $transaction) {
            if (!in_array($transaction->getId(), $transactionIds, true)) {
                continue;
            }

            $monday = self::shiftSundayToMonday($transaction->getDate());
            $transaction->setDate($monday);
            if ($transaction->isDeferredDebit()) {
                $transaction->setDebitDate(Transaction::computeDeferredDebitDate($monday));
            }
            ++$shifted;
        }

        if ($shifted > 0) {
            $this->entityManager->flush();
        }

        return $shifted;
    }

    /**
     * Before removing transactions: removed occurrences must not be generated again, and when a recurring template is removed, promote its oldest remaining
     * occurrence as the new template. Otherwise every orphaned occurrence (still flagged as recurring)
     * would become a template of its own and the series would be generated again from each of them.
     *
     * @param list<Transaction> $removed
     */
    public function reassignRecurrenceBeforeRemoval(array $removed): void
    {
        foreach ($removed as $template) {
            if (null !== $parent = $template->getRecurrenceParent()) {
                $this->markGeneratedUntil($parent, $template->getDate());

                continue;
            }

            if (!$template->isRecurring()) {
                continue;
            }

            $remaining = array_values(array_filter(
                $template->getRecurrenceChildren()->toArray(),
                static fn (Transaction $child) => !in_array($child, $removed, true),
            ));

            if ([] === $remaining) {
                continue;
            }

            usort($remaining, static fn (Transaction $a, Transaction $b) => [$a->getDate(), $a->getId()] <=> [$b->getDate(), $b->getId()]);

            $newTemplate = array_shift($remaining);
            $newTemplate->setRecurrenceParent(null);
            $newTemplate->setRecurrenceGeneratedUntil($template->getRecurrenceGeneratedUntil());
            $this->markGeneratedUntil($newTemplate, $this->transactionRepository->findLastOccurrenceDate($template));

            foreach ($remaining as $child) {
                $child->setRecurrenceParent($newTemplate);
            }
        }
    }

    private function markGeneratedUntil(Transaction $template, \DateTimeImmutable $date): bool
    {
        $generatedUntil = $template->getRecurrenceGeneratedUntil();
        if (null !== $generatedUntil && $generatedUntil >= $date) {
            return false;
        }

        $template->setRecurrenceGeneratedUntil($date);

        return true;
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
        $occurrence->setIsDeferredDebit($template->isDeferredDebit());
        $occurrence->setDebitDate($template->isDeferredDebit() ? Transaction::computeDeferredDebitDate($date) : null);
        $occurrence->setDestinationAccount($template->getDestinationAccount());
        $occurrence->setRecurrenceParent($template);
        $this->markGeneratedUntil($template, $date);

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

    /**
     * Next month's occurrence date for a monthly series anchored on $anchorDay (clamped to the
     * month's last day, moved to Monday when it falls on a Sunday), and the [windowStart, windowEnd)
     * date range in which an existing occurrence means next month is already covered.
     *
     * The window is the next calendar month, adjusted for Sunday shifts crossing a month boundary:
     * this month's occurrence pushed to the 1st of next month doesn't count, and next month's
     * occurrence pushed to the 1st of the month after does.
     *
     * @return array{nextDate: \DateTimeImmutable, windowStart: \DateTimeImmutable, windowEnd: \DateTimeImmutable}
     */
    public static function computeNextMonthOccurrence(\DateTimeImmutable $today, int $anchorDay): array
    {
        $today = $today->setTime(0, 0);
        $currentMonthStart = $today->modify('first day of this month');
        $nextMonthStart = $today->modify('first day of next month');
        $monthAfterStart = $nextMonthStart->modify('+1 month');

        $nextDate = self::shiftSundayToMonday(self::dayInMonth($nextMonthStart, $anchorDay));
        $currentMonthDate = self::shiftSundayToMonday(self::dayInMonth($currentMonthStart, $anchorDay));

        return [
            'nextDate' => $nextDate,
            'windowStart' => $currentMonthDate >= $nextMonthStart ? $nextMonthStart->modify('+1 day') : $nextMonthStart,
            'windowEnd' => max($monthAfterStart, $nextDate->modify('+1 day')),
        ];
    }

    /**
     * Whether $today is on or after the check day of its month (clamped to the month's last day,
     * so a check day of 31 still triggers on the last day of shorter months).
     */
    public static function isCheckDayReached(\DateTimeImmutable $today, int $checkDay): bool
    {
        return (int) $today->format('j') >= min(max($checkDay, 1), (int) $today->format('t'));
    }

    /**
     * Same month as $monthStart, on $day (clamped to the last day of that month).
     */
    private static function dayInMonth(\DateTimeImmutable $monthStart, int $day): \DateTimeImmutable
    {
        return $monthStart->setDate(
            (int) $monthStart->format('Y'),
            (int) $monthStart->format('n'),
            min($day, (int) $monthStart->format('t')),
        );
    }

    private static function shiftSundayToMonday(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return '7' === $date->format('N') ? $date->modify('+1 day') : $date;
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
