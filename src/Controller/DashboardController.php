<?php

namespace App\Controller;

use App\Entity\Account;
use App\Repository\AccountRepository;
use App\Repository\TransactionRepository;
use App\Service\BudgetForecastService;
use App\Service\RecurringTransactionGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function index(BudgetForecastService $forecastService, TransactionRepository $transactionRepository, RecurringTransactionGenerator $recurringTransactionGenerator, AccountRepository $accountRepository, TranslatorInterface $translator): Response
    {
        return $this->renderDashboard($forecastService, $transactionRepository, $recurringTransactionGenerator, $accountRepository, $translator, null);
    }

    #[Route('/compte/{id}', name: 'app_dashboard_account', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function accountDashboard(Account $account, BudgetForecastService $forecastService, TransactionRepository $transactionRepository, RecurringTransactionGenerator $recurringTransactionGenerator, AccountRepository $accountRepository, TranslatorInterface $translator): Response
    {
        return $this->renderDashboard($forecastService, $transactionRepository, $recurringTransactionGenerator, $accountRepository, $translator, $account);
    }

    private function renderDashboard(BudgetForecastService $forecastService, TransactionRepository $transactionRepository, RecurringTransactionGenerator $recurringTransactionGenerator, AccountRepository $accountRepository, TranslatorInterface $translator, ?Account $account): Response
    {
        $recurringTransactionGenerator->generateDueOccurrences();

        $today = new \DateTimeImmutable('today');
        $currentYear = (int) $today->format('Y');
        [$dailyBalanceStart, $dailyBalanceEnd] = $this->resolveDailyBalancePeriod($transactionRepository, $account, $today);

        $yearlyReports = [];
        foreach (['previous' => $currentYear - 1, 'current' => $currentYear, 'next' => $currentYear + 1] as $key => $year) {
            $report = $forecastService->getMonthlyReport(
                new \DateTimeImmutable($year.'-01-01'),
                new \DateTimeImmutable($year.'-12-31'),
                $account
            );

            $yearlyReports[$key] = [
                'year' => $year,
                'report' => $report,
                'income' => array_sum(array_column($report, 'income')),
                'expense' => array_sum(array_column($report, 'expense')),
            ];
        }

        $categoryPeriodStart = $today->modify('-3 months');
        $categoryPeriodEnd = $today;
        $categoryBreakdownExpense = array_slice($forecastService->getCategoryBreakdown($categoryPeriodStart, $categoryPeriodEnd, $account, 'expense'), 0, 10);
        $categoryBreakdownIncome = array_slice($forecastService->getCategoryBreakdown($categoryPeriodStart, $categoryPeriodEnd, $account, 'income'), 0, 10);
        $dailyBalances = $forecastService->getDailyBalances($dailyBalanceStart, $dailyBalanceEnd, $account);

        $accountBalances = [];
        $averageSalaryTotal = 0.0;
        if (null === $account) {
            foreach ($accountRepository->findAllOrderedByLabel() as $eachAccount) {
                $accountBalances[] = [
                    'account' => $eachAccount,
                    'balance' => $transactionRepository->sumUntil($today, $eachAccount),
                    'upcomingBalance' => $transactionRepository->sumAll($eachAccount),
                    'url' => $this->generateUrl('app_dashboard_account', ['id' => $eachAccount->getId()]),
                    'pendingRecurringCount' => count($recurringTransactionGenerator->findMonthlyTemplatesPendingNextMonth($eachAccount)),
                ];
                $averageSalaryTotal += (float) ($eachAccount->getAverageSalary() ?? 0);
            }
        } else {
            $averageSalaryTotal = (float) ($account->getAverageSalary() ?? 0);
        }

        $currentBalance = $transactionRepository->sumUntil($today, $account);
        $upcomingBalance = $transactionRepository->sumAll($account);
        $currentMonthBalance = $transactionRepository->sumFiltered(account: $account, currentMonthOnly: true, excludeForecastExcluded: true);
        $forecastBalance = $averageSalaryTotal + $currentMonthBalance;
        $upcomingCurrentMonthBalance = $transactionRepository->sumFiltered(account: $account, currentMonthOnly: true, upcomingOnly: true, excludeForecastExcluded: true);
        $forecastCheck = $currentBalance + $upcomingCurrentMonthBalance;

        $savingsStats = null;
        $progress = null;
        $dailyBalanceForecast = null;
        if (null !== $account && $account->isSavings()) {
            if (null !== $account->getInterestRate()) {
                $rate = (float) $account->getInterestRate();
                $averageMonthlyInterest = $currentBalance * ($rate / 100) / 12;
                $remainingMonths = 12 - (int) $today->format('n') + 1;

                $projectedYearEndInterest = $averageMonthlyInterest * $remainingMonths;

                $savingsStats = [
                    'averageMonthlyInterest' => $averageMonthlyInterest,
                    'projectedYearEndInterest' => $projectedYearEndInterest,
                    'upcomingBalanceWithInterest' => $upcomingBalance + $projectedYearEndInterest,
                ];

                $dailyBalanceForecast = $forecastService->projectDailyBalancesWithInterest($dailyBalances, $today, $currentBalance, $rate);
            }

            if (null !== $account->getMaxAmount() && (float) $account->getMaxAmount() > 0) {
                $max = (float) $account->getMaxAmount();
                $progress = [
                    'balance' => $currentBalance,
                    'max' => $max,
                    'percent' => min(100, max(0, $currentBalance / $max * 100)),
                ];
            }
        }

        $addCategoryUrl = function (array $entry) use ($account) {
            $entry['url'] = $this->generateUrl('app_transaction_index', array_filter([
                'account' => $account?->getId(),
                'category' => $entry['categoryId'],
            ]));

            return $entry;
        };

        $categoryBreakdownExpense = array_map($addCategoryUrl, $categoryBreakdownExpense);
        $categoryBreakdownIncome = array_map($addCategoryUrl, $categoryBreakdownIncome);

        return $this->render('dashboard/index.html.twig', [
            'categoryBreakdownExpense' => $categoryBreakdownExpense,
            'categoryBreakdownIncome' => $categoryBreakdownIncome,
            'currentBalance' => $currentBalance,
            'upcomingBalance' => $upcomingBalance,
            'forecastBalance' => $forecastBalance,
            'forecastCheck' => $forecastCheck,
            'balanceDate' => $forecastService->formatDate($today),
            'categoryBreakdownPeriodLabel' => $translator->trans('from %start% to %end%', [
                '%start%' => $forecastService->formatDate($categoryPeriodStart),
                '%end%' => $forecastService->formatDate($categoryPeriodEnd),
            ]),
            'dailyBalanceChartPeriodLabel' => $translator->trans('Balance trend from %start% to %end%', [
                '%start%' => $forecastService->formatDate($dailyBalanceStart),
                '%end%' => $forecastService->formatDate($dailyBalanceEnd),
            ]),
            'totalBalance' => array_sum(array_column($accountBalances, 'balance')),
            'totalUpcomingBalance' => array_sum(array_column($accountBalances, 'upcomingBalance')),
            'chartDataJson' => $this->buildChartDataJson($yearlyReports, $categoryBreakdownExpense, $categoryBreakdownIncome, $accountBalances, $dailyBalances, $dailyBalanceForecast, $today),
            'currentAccount' => $account,
            'accountBalances' => $accountBalances,
            'savingsStats' => $savingsStats,
            'progress' => $progress,
            'recentTransactions' => $transactionRepository->findRecent($account, 10),
            'pendingRecurringCount' => count($recurringTransactionGenerator->findMonthlyTemplatesPendingNextMonth($account)),
        ]);
    }

    /**
     * Resolves the [start, end] period shown on the daily balance chart:
     * - if the account's whole history spans less than 365 days, show it in full (first to last operation),
     *   without padding into an empty future;
     * - otherwise, show a 365-day window centered on the midpoint between the first and last operation.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function resolveDailyBalancePeriod(TransactionRepository $transactionRepository, ?Account $account, \DateTimeImmutable $today): array
    {
        $firstDate = $transactionRepository->findEarliestDate($account);
        $lastDate = $transactionRepository->findLatestDate($account);

        if (null === $firstDate || null === $lastDate) {
            return [$today, $today];
        }

        $historySpanDays = $firstDate->diff($lastDate)->days;

        if ($historySpanDays < 365) {
            return [$firstDate, $lastDate];
        }

        $center = $firstDate->modify('+'.intdiv($historySpanDays, 2).' days');

        return [$center->modify('-182 days'), $center->modify('+183 days')];
    }

    private function buildChartDataJson(array $yearlyReports, array $categoryBreakdownExpense, array $categoryBreakdownIncome, array $accountBalances, array $dailyBalances, ?array $dailyBalanceForecast, \DateTimeImmutable $today): string
    {
        $mapCategory = static fn (array $breakdown) => [
            'labels' => array_column($breakdown, 'name'),
            'totals' => array_map(static fn (array $c) => round($c['total'], 2), $breakdown),
            'colors' => array_column($breakdown, 'color'),
            'urls' => array_column($breakdown, 'url'),
        ];

        $mapYear = static fn (array $y) => [
            'year' => $y['year'],
            'monthLabels' => array_column($y['report'], 'label'),
            'income' => array_map(static fn (array $m) => round($m['income'], 2), $y['report']),
            'expense' => array_map(static fn (array $m) => round($m['expense'], 2), $y['report']),
            'totals' => [round($y['income'], 2), round($y['expense'], 2)],
        ];

        $todayKey = $today->format('Y-m-d');
        $hasForecast = null !== $dailyBalanceForecast;

        $forecastByDate = [];
        foreach ($dailyBalanceForecast ?? [] as $day) {
            $forecastByDate[$day['date']] = round($day['balance'], 2);
        }

        $data = [
            'years' => array_map($mapYear, $yearlyReports),
            'categoryExpense' => $mapCategory($categoryBreakdownExpense),
            'categoryIncome' => $mapCategory($categoryBreakdownIncome),
            'accountLabels' => array_map(static fn (array $a) => $a['account']->getLabel().($a['account']->getPerson() ? ' ('.$a['account']->getPerson()->getName().')' : ''), $accountBalances),
            'accountBalances' => array_map(static fn (array $a) => round($a['balance'], 2), $accountBalances),
            'accountUrls' => array_column($accountBalances, 'url'),
            'dailyBalance' => [
                'labels' => array_map(static fn (array $d) => \DateTimeImmutable::createFromFormat('Y-m-d', $d['date'])->format('d/m'), $dailyBalances),
                'values' => array_map(static fn (array $d) => (!$hasForecast || $d['date'] <= $todayKey) ? round($d['balance'], 2) : null, $dailyBalances),
                'forecastValues' => array_map(static fn (array $d) => $forecastByDate[$d['date']] ?? null, $dailyBalances),
            ],
        ];

        return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    }
}
