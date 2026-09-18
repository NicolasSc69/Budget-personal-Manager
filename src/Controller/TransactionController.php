<?php

namespace App\Controller;

use App\Entity\Category;
use App\Entity\Transaction;
use App\Form\CategoryType;
use App\Form\TransactionType;
use App\Repository\AccountRepository;
use App\Repository\AppSettingRepository;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use App\Service\BudgetForecastService;
use App\Service\RecurringTransactionGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options as DompdfOptions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/transaction')]
final class TransactionController extends AbstractController
{
    #[Route(name: 'app_transaction_index', methods: ['GET'])]
    public function index(
        Request $request,
        TransactionRepository $transactionRepository,
        RecurringTransactionGenerator $recurringTransactionGenerator,
        AccountRepository $accountRepository,
        CategoryRepository $categoryRepository,
        AppSettingRepository $appSettingRepository,
        BudgetForecastService $forecastService,
    ): Response {
        $recurringTransactionGenerator->generateDueOccurrences();

        $filters = $this->parseFilters($request, $accountRepository, $categoryRepository);
        $account = $filters['account'];
        $category = $filters['category'];
        $dateFromRaw = $filters['dateFromRaw'];
        $dateToRaw = $filters['dateToRaw'];
        $amountMinRaw = $filters['amountMinRaw'];
        $amountMaxRaw = $filters['amountMaxRaw'];
        $dateFrom = $filters['dateFrom'];
        $dateTo = $filters['dateTo'];
        $amountMin = $filters['amountMin'];
        $amountMax = $filters['amountMax'];
        $search = $filters['search'];
        $upcomingOnly = $filters['upcomingOnly'];
        $currentMonthOnly = $filters['currentMonthOnly'];

        $sort = $request->query->get('sort', 'id');
        $direction = 'asc' === strtolower((string) $request->query->get('direction', 'desc')) ? 'asc' : 'desc';

        $itemsPerPage = $appSettingRepository->getSettings()->getItemsPerPage();
        $totalTransactions = $transactionRepository->countFiltered($account, $category, $dateFrom, $dateTo, $amountMin, $amountMax, $search, $upcomingOnly, $currentMonthOnly);
        $totalPages = max(1, (int) ceil($totalTransactions / $itemsPerPage));
        $page = min(max(1, (int) $request->query->get('page', 1)), $totalPages);

        $transactions = $transactionRepository->findFiltered($account, $category, $dateFrom, $dateTo, $amountMin, $amountMax, $search, $sort, $direction, $itemsPerPage, ($page - 1) * $itemsPerPage, $upcomingOnly, $currentMonthOnly);
        $filteredAmountSum = $transactionRepository->sumFiltered($account, $category, $dateFrom, $dateTo, $amountMin, $amountMax, $search, $upcomingOnly, $currentMonthOnly, excludeForecastExcluded: $currentMonthOnly);

        $allAccounts = $accountRepository->findAllOrderedByLabel();

        $today = new \DateTimeImmutable('today');
        $currentBalance = $transactionRepository->sumUntil($today, $account);
        $upcomingBalance = $transactionRepository->sumAll($account);
        $averageSalaryTotal = null !== $account
            ? (float) ($account->getAverageSalary() ?? 0)
            : array_sum(array_map(static fn ($a) => (float) ($a->getAverageSalary() ?? 0), $allAccounts));
        $currentMonthBalance = $transactionRepository->sumFiltered(account: $account, currentMonthOnly: true, excludeForecastExcluded: true);
        $forecastBalance = $averageSalaryTotal + $currentMonthBalance;
        $upcomingCurrentMonthBalance = $transactionRepository->sumFiltered(account: $account, currentMonthOnly: true, upcomingOnly: true, excludeForecastExcluded: true);
        $forecastCheck = $currentBalance + $upcomingCurrentMonthBalance;

        $queryParams = array_filter([
            'account' => $account?->getId(),
            'category' => $category?->getId(),
            'date_from' => $dateFromRaw,
            'date_to' => $dateToRaw,
            'amount_min' => $amountMinRaw,
            'amount_max' => $amountMaxRaw,
            'search' => $search,
            'upcoming_only' => $upcomingOnly ? '1' : null,
            'current_month' => $currentMonthOnly ? '1' : null,
            'sort' => $sort,
            'direction' => $direction,
        ], static fn ($value) => null !== $value && '' !== $value);

        return $this->render('transaction/index.html.twig', [
            'transactions' => $transactions,
            'filterAccount' => $account,
            'filterCategory' => $category,
            'filterDateFrom' => $dateFromRaw,
            'filterDateTo' => $dateToRaw,
            'filterAmountMin' => $amountMinRaw,
            'filterAmountMax' => $amountMaxRaw,
            'filterSearch' => $search,
            'filterUpcomingOnly' => $upcomingOnly,
            'filterCurrentMonthOnly' => $currentMonthOnly,
            'accounts' => $allAccounts,
            'categories' => $categoryRepository->findAllOrderedByHierarchy(),
            'sort' => $sort,
            'direction' => $direction,
            'queryParams' => $queryParams,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'currentBalance' => $currentBalance,
            'upcomingBalance' => $upcomingBalance,
            'forecastBalance' => $forecastBalance,
            'forecastCheck' => $forecastCheck,
            'filteredAmountSum' => $filteredAmountSum,
            'balanceDate' => $forecastService->formatDate($today),
            'pendingRecurringCount' => count($recurringTransactionGenerator->findMonthlyTemplatesPendingNextMonth($account)),
        ]);
    }

    #[Route('/export/pdf', name: 'app_transaction_export_pdf', methods: ['GET'])]
    public function exportPdf(
        Request $request,
        TransactionRepository $transactionRepository,
        AccountRepository $accountRepository,
        CategoryRepository $categoryRepository,
        BudgetForecastService $forecastService,
    ): Response {
        $filters = $this->parseFilters($request, $accountRepository, $categoryRepository);
        $account = $filters['account'];

        $today = new \DateTimeImmutable('today');
        $currentBalance = $transactionRepository->sumUntil($today, $account);
        $upcomingBalance = $transactionRepository->sumAll($account);
        $averageSalaryTotal = null !== $account
            ? (float) ($account->getAverageSalary() ?? 0)
            : array_sum(array_map(static fn ($a) => (float) ($a->getAverageSalary() ?? 0), $accountRepository->findAllOrderedByLabel()));
        $currentMonthBalance = $transactionRepository->sumFiltered(account: $account, currentMonthOnly: true, excludeForecastExcluded: true);
        $forecastBalance = $averageSalaryTotal + $currentMonthBalance;
        $upcomingCurrentMonthBalance = $transactionRepository->sumFiltered(account: $account, currentMonthOnly: true, upcomingOnly: true, excludeForecastExcluded: true);
        $forecastCheck = $currentBalance + $upcomingCurrentMonthBalance;

        $transactions = $transactionRepository->findFilteredForExport(
            $filters['account'],
            $filters['category'],
            $filters['dateFrom'],
            $filters['dateTo'],
            $filters['amountMin'],
            $filters['amountMax'],
            $filters['search'],
            $filters['upcomingOnly'],
            $filters['currentMonthOnly'],
        );

        $totalAmount = $transactionRepository->sumFiltered(
            $filters['account'],
            $filters['category'],
            $filters['dateFrom'],
            $filters['dateTo'],
            $filters['amountMin'],
            $filters['amountMax'],
            $filters['search'],
            $filters['upcomingOnly'],
            $filters['currentMonthOnly'],
            excludeForecastExcluded: $filters['currentMonthOnly'],
        );

        $html = $this->renderView('transaction/_export_pdf.html.twig', [
            'transactions' => $transactions,
            'totalAmount' => $totalAmount,
            'filterAccount' => $filters['account'],
            'filterCategory' => $filters['category'],
            'filterDateFrom' => $filters['dateFromRaw'],
            'filterDateTo' => $filters['dateToRaw'],
            'filterAmountMin' => $filters['amountMinRaw'],
            'filterAmountMax' => $filters['amountMaxRaw'],
            'filterSearch' => $filters['search'],
            'filterUpcomingOnly' => $filters['upcomingOnly'],
            'filterCurrentMonthOnly' => $filters['currentMonthOnly'],
            'generatedAt' => new \DateTimeImmutable(),
            'currentBalance' => $currentBalance,
            'upcomingBalance' => $upcomingBalance,
            'forecastBalance' => $forecastBalance,
            'forecastCheck' => $forecastCheck,
            'balanceDate' => $forecastService->formatDate($today),
        ]);

        $options = new DompdfOptions();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return new Response($dompdf->output(), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="transactions-'.(new \DateTimeImmutable())->format('Y-m-d-His').'.pdf"',
        ]);
    }

    /**
     * @return array{account: ?\App\Entity\Account, category: ?\App\Entity\Category, dateFrom: ?\DateTimeImmutable, dateFromRaw: ?string, dateTo: ?\DateTimeImmutable, dateToRaw: ?string, amountMin: ?float, amountMinRaw: ?string, amountMax: ?float, amountMaxRaw: ?string, search: ?string, upcomingOnly: bool, currentMonthOnly: bool}
     */
    private function parseFilters(Request $request, AccountRepository $accountRepository, CategoryRepository $categoryRepository): array
    {
        $account = $request->query->get('account') ? $accountRepository->find($request->query->get('account')) : null;
        $category = $request->query->get('category') ? $categoryRepository->find($request->query->get('category')) : null;

        $dateFromRaw = $request->query->get('date_from');
        $dateToRaw = $request->query->get('date_to');
        $amountMinRaw = $request->query->get('amount_min');
        $amountMaxRaw = $request->query->get('amount_max');
        $searchRaw = $request->query->get('search');

        $dateFrom = $this->parseDate($dateFromRaw);
        $dateTo = $this->parseDate($dateToRaw);
        $amountMin = is_numeric($amountMinRaw) ? (float) $amountMinRaw : null;
        $amountMax = is_numeric($amountMaxRaw) ? (float) $amountMaxRaw : null;
        $search = $searchRaw ? trim($searchRaw) : null;

        return [
            'account' => $account,
            'category' => $category,
            'dateFrom' => $dateFrom,
            'dateFromRaw' => $dateFrom ? $dateFromRaw : null,
            'dateTo' => $dateTo,
            'dateToRaw' => $dateTo ? $dateToRaw : null,
            'amountMin' => $amountMin,
            'amountMinRaw' => null !== $amountMin ? $amountMinRaw : null,
            'amountMax' => $amountMax,
            'amountMaxRaw' => null !== $amountMax ? $amountMaxRaw : null,
            'search' => $search,
            'upcomingOnly' => null !== $request->query->get('upcoming_only'),
            'currentMonthOnly' => null !== $request->query->get('current_month'),
        ];
    }

    #[Route('/recurring', name: 'app_transaction_recurring', methods: ['GET'])]
    public function recurring(Request $request, AccountRepository $accountRepository, CategoryRepository $categoryRepository, RecurringTransactionGenerator $recurringTransactionGenerator): Response
    {
        $account = $request->query->get('account') ? $accountRepository->find($request->query->get('account')) : null;

        $pending = $recurringTransactionGenerator->findMonthlyTemplatesPendingNextMonth($account);

        $groups = [];
        foreach ($pending as $entry) {
            $groups[$entry['template']->getCategory()->getFullName()][] = $entry;
        }
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        return $this->render('transaction/recurring.html.twig', [
            'groups' => $groups,
            'filterAccount' => $account,
            'totalCount' => count($pending),
            'categories' => $categoryRepository->findAllOrderedByHierarchy(),
        ]);
    }

    #[Route('/recurring/duplicate', name: 'app_transaction_recurring_duplicate', methods: ['POST'])]
    public function duplicateRecurring(Request $request, RecurringTransactionGenerator $recurringTransactionGenerator, AccountRepository $accountRepository, TranslatorInterface $translator): Response
    {
        $accountId = $request->query->get('account');

        if (!$this->isCsrfTokenValid('duplicate_recurring', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', $translator->trans('Invalid security token, please try again.'));

            return $this->redirectToRoute('app_transaction_recurring', array_filter(['account' => $accountId]), Response::HTTP_SEE_OTHER);
        }

        $templateIds = array_map('intval', $request->request->all('templates'));
        $titles = $request->request->all('titles');
        $categories = $request->request->all('categories');
        $duplicated = $recurringTransactionGenerator->duplicateMonthlyTemplatesToNextMonth($templateIds, $titles, $categories);

        $this->addFlash(
            $duplicated > 0 ? 'success' : 'warning',
            $duplicated > 0
                ? $translator->trans('%count% recurring transaction(s) duplicated to next month.', ['%count%' => $duplicated])
                : $translator->trans('No transaction selected.')
        );

        return $this->redirectToRoute('app_transaction_recurring', array_filter(['account' => $accountId]), Response::HTTP_SEE_OTHER);
    }

    #[Route('/bulk-delete', name: 'app_transaction_bulk_delete', methods: ['POST'])]
    public function bulkDelete(Request $request, EntityManagerInterface $entityManager, TransactionRepository $transactionRepository, TranslatorInterface $translator): Response
    {
        $redirectUrl = $this->resolveLocalRedirectUrl($request->getPayload()->getString('redirectTo'), $this->generateUrl('app_transaction_index'));

        if (!$this->isCsrfTokenValid('bulk_delete_transactions', $request->getPayload()->getString('_token'))) {
            $this->addFlash('danger', $translator->trans('Invalid security token, please try again.'));

            return $this->redirect($redirectUrl, Response::HTTP_SEE_OTHER);
        }

        $ids = array_map('intval', $request->request->all('ids'));
        $deleted = 0;

        foreach ($ids as $id) {
            $transaction = $transactionRepository->find($id);
            if (null !== $transaction) {
                $entityManager->remove($transaction);
                ++$deleted;
            }
        }

        if ($deleted > 0) {
            $entityManager->flush();
        }

        $this->addFlash(
            $deleted > 0 ? 'success' : 'warning',
            $deleted > 0
                ? $translator->trans('%count% transaction(s) deleted.', ['%count%' => $deleted])
                : $translator->trans('No transaction selected.')
        );

        return $this->redirect($redirectUrl, Response::HTTP_SEE_OTHER);
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if (!$value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    #[Route('/new', name: 'app_transaction_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, AccountRepository $accountRepository, TransactionRepository $transactionRepository, TranslatorInterface $translator): Response
    {
        $transaction = new Transaction();

        $duplicateSource = $request->query->get('duplicate')
            ? $transactionRepository->find($request->query->get('duplicate'))
            : null;

        if (null !== $duplicateSource) {
            $transaction->setTitle($duplicateSource->getTitle());
            $transaction->setAccount($duplicateSource->getAccount());
            $transaction->setCategory($duplicateSource->getCategory());
            $transaction->setDate($duplicateSource->getDate());
            $transaction->setRecurrence($duplicateSource->getRecurrence());
        }

        if ($request->query->get('account')) {
            $transaction->setAccount($accountRepository->find($request->query->get('account')));
        }

        $form = $this->createForm(TransactionType::class, $transaction, [
            'redirect_to' => $request->query->get('redirect_to'),
        ]);

        if (null !== $duplicateSource) {
            $rawAmount = (float) $duplicateSource->getAmount();
            $form->get('type')->setData($rawAmount < 0 ? 'expense' : 'income');
            $form->get('amount')->setData(abs($rawAmount));
        } else {
            $form->get('type')->setData('expense');
        }

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($transaction);

            if (null !== $transaction->getDestinationAccount()) {
                $entityManager->persist($this->createTransferMirror($transaction));
            }

            $entityManager->flush();

            $this->addFlash('success', $translator->trans('The transaction has been created.'));

            $redirectUrl = $this->resolveRedirectUrl($form, $this->generateUrl('app_dashboard_account', ['id' => $transaction->getAccount()->getId()]));

            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'redirect' => $redirectUrl]);
            }

            return $this->redirect($redirectUrl, Response::HTTP_SEE_OTHER);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->render('transaction/_form.html.twig', [
                'transaction' => $transaction,
                'form' => $form,
                'categoryForm' => $this->createForm(CategoryType::class, new Category()),
                'existingTitlesJson' => $this->buildExistingTitlesJson($transactionRepository),
                'formAction' => $this->generateUrl('app_transaction_new'),
            ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
        }

        return $this->render('transaction/new.html.twig', [
            'transaction' => $transaction,
            'form' => $form,
            'categoryForm' => $this->createForm(CategoryType::class, new Category()),
            'existingTitlesJson' => $this->buildExistingTitlesJson($transactionRepository),
            'formAction' => $this->generateUrl('app_transaction_new'),
        ]);
    }

    #[Route('/{id}', name: 'app_transaction_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Request $request, Transaction $transaction): Response
    {
        if ($request->isXmlHttpRequest()) {
            return $this->render('transaction/_show.html.twig', [
                'transaction' => $transaction,
            ]);
        }

        return $this->render('transaction/show.html.twig', [
            'transaction' => $transaction,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_transaction_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Transaction $transaction, EntityManagerInterface $entityManager, TransactionRepository $transactionRepository, TranslatorInterface $translator): Response
    {
        $form = $this->createForm(TransactionType::class, $transaction, [
            'redirect_to' => $request->query->get('redirect_to'),
        ]);
        $rawAmount = (float) $transaction->getAmount();
        $form->get('type')->setData($rawAmount < 0 ? 'expense' : 'income');
        $form->get('amount')->setData(abs($rawAmount));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', $translator->trans('The transaction has been updated.'));

            $redirectUrl = $this->resolveRedirectUrl($form, $this->generateUrl('app_dashboard_account', ['id' => $transaction->getAccount()->getId()]));

            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'redirect' => $redirectUrl]);
            }

            return $this->redirect($redirectUrl, Response::HTTP_SEE_OTHER);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->render('transaction/_form.html.twig', [
                'transaction' => $transaction,
                'form' => $form,
                'categoryForm' => $this->createForm(CategoryType::class, new Category()),
                'existingTitlesJson' => $this->buildExistingTitlesJson($transactionRepository),
                'formAction' => $this->generateUrl('app_transaction_edit', ['id' => $transaction->getId()]),
            ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
        }

        return $this->render('transaction/edit.html.twig', [
            'transaction' => $transaction,
            'form' => $form,
            'categoryForm' => $this->createForm(CategoryType::class, new Category()),
            'existingTitlesJson' => $this->buildExistingTitlesJson($transactionRepository),
            'formAction' => $this->generateUrl('app_transaction_edit', ['id' => $transaction->getId()]),
        ]);
    }

    private function buildExistingTitlesJson(TransactionRepository $transactionRepository): string
    {
        return json_encode($transactionRepository->findDistinctTitles(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    }

    private function createTransferMirror(Transaction $origin): Transaction
    {
        $mirror = new Transaction();
        $mirror->setTitle($origin->getTitle());
        $mirror->setAmount((string) (-(float) $origin->getAmount()));
        $mirror->setDate($origin->getDate());
        $mirror->setCategory($origin->getCategory());
        $mirror->setAccount($origin->getDestinationAccount());

        return $mirror;
    }

    private function resolveRedirectUrl(FormInterface $form, string $fallbackUrl): string
    {
        $candidate = $form->has('redirectTo') ? $form->get('redirectTo')->getData() : null;

        return $this->resolveLocalRedirectUrl($candidate, $fallbackUrl);
    }

    private function resolveLocalRedirectUrl(mixed $candidate, string $fallbackUrl): string
    {
        if (is_string($candidate) && '' !== $candidate && str_starts_with($candidate, '/') && !str_starts_with($candidate, '//')) {
            return $candidate;
        }

        return $fallbackUrl;
    }

    #[Route('/{id}', name: 'app_transaction_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Transaction $transaction, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        $redirectUrl = $this->resolveLocalRedirectUrl($request->getPayload()->getString('redirectTo'), $this->generateUrl('app_transaction_index'));

        if ($this->isCsrfTokenValid('delete'.$transaction->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($transaction);
            $entityManager->flush();

            $this->addFlash('success', $translator->trans('The transaction has been deleted.'));
        } else {
            $this->addFlash('danger', $translator->trans('Invalid security token, please try again.'));
        }

        return $this->redirect($redirectUrl, Response::HTTP_SEE_OTHER);
    }
}
