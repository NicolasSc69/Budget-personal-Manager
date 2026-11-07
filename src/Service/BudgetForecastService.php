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
            // Net amount per month and per category: a refund offsets its category's expense instead of
            // counting as income (same rule as getCategoryBreakdown()).
            $monthlyCategoryNet = [];
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

                $categoryId = $movement['categoryId'] ?? 0;
                $monthlyCategoryNet[$key][$categoryId] = ($monthlyCategoryNet[$key][$categoryId] ?? 0.0) + $amount;
            }

            $monthlyIncome = [];
            $monthlyExpense = [];
            foreach ($monthlyCategoryNet as $key => $netByCategory) {
                $netByCategory = array_map(static fn (float $net) => round($net, 2), $netByCategory);
                $monthlyIncome[$key] = array_sum(array_filter($netByCategory, static fn (float $net) => $net > 0));
                $monthlyExpense[$key] = -array_sum(array_filter($netByCategory, static fn (float $net) => $net < 0));
            }

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
     * Projects the daily balances of a savings account by adding simple (non-compounding) interest
     * accrued day by day from today onward, based on the account's annual interest rate.
     *
     * @param array<int, array{date: string, balance: float}> $dailyBalances
     *
     * @return array<int, array{date: string, balance: float}>
     */
    public function projectDailyBalancesWithInterest(array $dailyBalances, \DateTimeImmutable $today, float $currentBalance, float $annualRatePercent): array
    {
        $todayKey = $today->format('Y-m-d');
        $dailyRate = $annualRatePercent / 100 / 365;

        $projected = [];
        foreach ($dailyBalances as $day) {
            if ($day['date'] < $todayKey) {
                continue;
            }

            $daysElapsed = $today->diff(new \DateTimeImmutable($day['date']))->days;
            $projected[] = [
                'date' => $day['date'],
                'balance' => $day['balance'] + $currentBalance * $dailyRate * $daysElapsed,
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
                // Net per category: a refund reduces its category's expense instead of being counted as income,
                // so the amount matches the sum shown on the filtered transaction list.
                $net = round((float) $row['netTotal'], 2);
                $total = 'income' === $type ? $net : -$net;

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
