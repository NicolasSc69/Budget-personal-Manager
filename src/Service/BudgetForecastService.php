<?php

namespace App\Service;

use App\Entity\Account;
use App\Repository\TransactionRepository;
use Symfony\Component\HttpFoundation\RequestStack;

class BudgetForecastService
{
    public function __construct(
        private readonly TransactionRepository $transactionRepository,
        private readonly RequestStack $requestStack,
        private readonly CalculationCache $calculationCache,
    ) {
    }

    /**
     * @return list<array{date: string, amount: float, categoryId: ?int}>
     */
    private function getBalanceMovements(?Account $account): array
    {
        // Cached (and kept in memory for the request) by the repository: the dashboard builds several
        // reports (3 yearly reports + the daily balances) from the same whole history.
        return $this->transactionRepository->findBalanceMovements($account);
    }

    /**
     * @return array<int, array{key: string, label: string, income: float, expense: float, net: float, balance: float}>
     */
    public function getMonthlyReport(\DateTimeImmutable $since, \DateTimeImmutable $until, ?Account $account = null): array
    {
        // Month labels are localized: the locale is part of the key.
        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'en';

        return $this->calculationCache->get(__METHOD__, [CalculationCache::TRANSACTION, CalculationCache::ACCOUNT], ['since' => $since, 'until' => $until, 'account' => $account, 'locale' => $locale], function () use ($since, $until, $account) {
            $sinceKey = $since->format('Y-m');
            $untilKey = $until->format('Y-m');

            $monthlyBalances = [];
            // Positive amounts count as income and negative ones as expense, whatever their tag
            // (same rule as getCategoryBreakdown()).
            $monthlyIncome = [];
            $monthlyExpense = [];
            $balance = $this->transactionRepository->getInitialBalanceOffset($account);
            $lastBalanceBeforeWindow = $balance;

            foreach ($this->getBalanceMovements($account) as $movement) {
                $amount = $movement['amount'];
                $balance += $amount;
                $key = substr($movement['date'], 0, 7);
                $monthlyBalances[$key] = $balance;

                if ($key < $sinceKey) {
                    $lastBalanceBeforeWindow = $balance;
                    continue;
                }

                if ($key > $untilKey) {
                    continue;
                }

                if ($amount > 0) {
                    $monthlyIncome[$key] = ($monthlyIncome[$key] ?? 0.0) + $amount;
                } elseif ($amount < 0) {
                    $monthlyExpense[$key] = ($monthlyExpense[$key] ?? 0.0) - $amount;
                }
            }

            $monthlyIncome = array_map(static fn (float $total) => round($total, 2), $monthlyIncome);
            $monthlyExpense = array_map(static fn (float $total) => round($total, 2), $monthlyExpense);

            $months = [];
            $cursor = $since;
            $carryBalance = $lastBalanceBeforeWindow;

            while ($cursor->format('Y-m') <= $untilKey) {
                $key = $cursor->format('Y-m');
                if (isset($monthlyBalances[$key])) {
                    $carryBalance = $monthlyBalances[$key];
                }

                $income = $monthlyIncome[$key] ?? 0.0;
                $expense = $monthlyExpense[$key] ?? 0.0;

                $months[] = [
                    'key' => $key,
                    'label' => $this->formatMonthLabel($cursor),
                    'income' => $income,
                    'expense' => $expense,
                    'net' => $income - $expense,
                    'balance' => $carryBalance,
                ];

                $cursor = $cursor->modify('+1 month');
            }

            return $months;
        });
    }

    /**
     * @return array<int, array{date: string, balance: float}>
     */
    public function getDailyBalances(\DateTimeImmutable $since, \DateTimeImmutable $until, ?Account $account = null): array
    {
        return $this->calculationCache->get(__METHOD__, [CalculationCache::TRANSACTION, CalculationCache::ACCOUNT], ['since' => $since, 'until' => $until, 'account' => $account], function () use ($since, $until, $account) {
            $sinceKey = $since->format('Y-m-d');
            $untilKey = $until->format('Y-m-d');

            $dailyBalances = [];
            $balance = $this->transactionRepository->getInitialBalanceOffset($account);
            $lastBalanceBeforeWindow = $balance;

            foreach ($this->getBalanceMovements($account) as $movement) {
                $balance += $movement['amount'];
                $key = $movement['date'];
                $dailyBalances[$key] = $balance;

                if ($key < $sinceKey) {
                    $lastBalanceBeforeWindow = $balance;
                }
            }

            $days = [];
            $cursor = $since;
            $carryBalance = $lastBalanceBeforeWindow;

            while ($cursor->format('Y-m-d') <= $untilKey) {
                $key = $cursor->format('Y-m-d');
                if (isset($dailyBalances[$key])) {
                    $carryBalance = $dailyBalances[$key];
                }

                $days[] = [
                    'date' => $key,
                    'balance' => $carryBalance,
                ];

                $cursor = $cursor->modify('+1 day');
            }

            return $days;
        });
    }

    /**
     * Interest of a savings account for each of the 24 fortnights of the given year, following the
     * fortnight rule applied to every savings account: interest = capital × annual rate / 24 per fortnight
     * (1st–15th, 16th–end of month).
     * - a deposit earns interest from the next fortnight (made on the 1st–15th: from the 16th;
     *   made on the 16th–end of month: from the 1st of the next month);
     * - a withdrawal stops earning interest from the start of its fortnight.
     * Interest is not compounded within the year: it is credited (capitalised) on December 31.
     * Scheduled (future) transactions are taken into account, so the year total is a forecast.
     *
     * @return list<array{start: string, end: string, capital: float, interest: float}>
     */
    public function getSavingsInterestSchedule(Account $account, int $year, float $annualRatePercent): array
    {
        return $this->computeFortnightInterests(
            $this->getBalanceMovements($account),
            $this->transactionRepository->getInitialBalanceOffset($account),
            $year,
            $annualRatePercent
        );
    }

    /**
     * @param list<array{date: string, amount: float}> $movements ordered by date
     *
     * @return list<array{start: string, end: string, capital: float, interest: float}>
     */
    public function computeFortnightInterests(array $movements, float $openingBalance, int $year, float $annualRatePercent): array
    {
        $fortnights = [];
        $balance = $openingBalance;
        $index = 0;
        $count = count($movements);

        for ($month = 1; $month <= 12; ++$month) {
            $firstDay = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
            $periods = [
                [$firstDay->format('Y-m-d'), $firstDay->format('Y-m-15')],
                [$firstDay->format('Y-m-16'), $firstDay->format('Y-m-t')],
            ];

            foreach ($periods as [$start, $end]) {
                // Balance at the start of the fortnight: everything booked before it.
                while ($index < $count && $movements[$index]['date'] < $start) {
                    $balance += $movements[$index]['amount'];
                    ++$index;
                }

                // Deposits of the fortnight only earn from the next one; its withdrawals stop earning from its start.
                $withdrawals = 0.0;
                for ($i = $index; $i < $count && $movements[$i]['date'] <= $end; ++$i) {
                    if ($movements[$i]['amount'] < 0) {
                        $withdrawals += $movements[$i]['amount'];
                    }
                }

                $capital = max(0.0, $balance + $withdrawals);
                $fortnights[] = [
                    'start' => $start,
                    'end' => $end,
                    'capital' => $capital,
                    'interest' => $capital * $annualRatePercent / 100 / 24,
                ];
            }
        }

        return $fortnights;
    }

    /**
     * Projects the daily balances of a savings account from today onward by adding the interest
     * acquired at each day (sum of the fortnights already ended), as computed by getSavingsInterestSchedule().
     * On December 31 the projection therefore includes the whole year's interest, credited on that day.
     *
     * @param array<int, array{date: string, balance: float}>                       $dailyBalances
     * @param list<array{start: string, end: string, capital: float, interest: float}> $interestSchedule
     *
     * @return array<int, array{date: string, balance: float}>
     */
    public function projectDailyBalancesWithInterest(array $dailyBalances, \DateTimeImmutable $today, array $interestSchedule): array
    {
        $todayKey = $today->format('Y-m-d');

        $projected = [];
        foreach ($dailyBalances as $day) {
            if ($day['date'] < $todayKey) {
                continue;
            }

            $acquired = 0.0;
            foreach ($interestSchedule as $fortnight) {
                if ($fortnight['end'] <= $day['date']) {
                    $acquired += $fortnight['interest'];
                }
            }

            $projected[] = [
                'date' => $day['date'],
                'balance' => $day['balance'] + $acquired,
            ];
        }

        return $projected;
    }

    /**
     * @param 'expense'|'income' $type
     *
     * @return array<int, array{categoryId: int, name: string, color: string, total: float}>
     */
    public function getCategoryBreakdown(\DateTimeImmutable $since, \DateTimeImmutable $until, ?Account $account = null, string $type = 'expense'): array
    {
        return $this->calculationCache->get(__METHOD__, [CalculationCache::TRANSACTION, CalculationCache::ACCOUNT, CalculationCache::CATEGORY], ['since' => $since, 'until' => $until, 'account' => $account, 'type' => $type], function () use ($since, $until, $account, $type) {
            $entries = [];
            foreach ($this->transactionRepository->sumByCategorySince($since, $account, $until) as $row) {
                // A tag holding both positive and negative transactions shows up in both charts:
                // its positive amounts as income, its negative ones (in absolute value) as expense.
                $total = 'income' === $type
                    ? round((float) $row['incomeTotal'], 2)
                    : round(-(float) $row['expenseTotal'], 2);

                if ($total <= 0) {
                    continue;
                }

                $category = $row['category'];

                $entries[] = [
                    'categoryId' => $category->getId(),
                    'name' => $category->getFullName(),
                    'color' => $category->getColor(),
                    'total' => $total,
                ];
            }

            usort($entries, static fn (array $a, array $b) => $b['total'] <=> $a['total']);

            return $entries;
        });
    }

    /**
     * Formats a date as a long localized string (e.g. "15 January 2026"), following the
     * current request locale so it stays consistent with the rest of the translated UI.
     */
    public function formatDate(\DateTimeImmutable $date): string
    {
        return $this->getDateFormatter('d MMMM y')->format($date);
    }

    private function formatMonthLabel(\DateTimeImmutable $date): string
    {
        return $this->getDateFormatter('MMMM y')->format($date);
    }

    private function getDateFormatter(string $pattern): \IntlDateFormatter
    {
        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'en';

        return new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, $pattern);
    }
}
