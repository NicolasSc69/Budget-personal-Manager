<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Person;
use App\Form\AccountFormType;
use App\Form\AccountTypeType;
use App\Form\PersonType;
use App\Repository\AccountRepository;
use App\Repository\AppSettingRepository;
use App\Repository\TransactionRepository;
use App\Service\RecurringTransactionGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/account')]
final class AccountController extends AbstractController
{
    #[Route(name: 'app_account_index', methods: ['GET'])]
    public function index(Request $request, AccountRepository $accountRepository, TransactionRepository $transactionRepository, AppSettingRepository $appSettingRepository, RecurringTransactionGenerator $recurringTransactionGenerator): Response
    {
        $itemsPerPage = $appSettingRepository->getSettings()->getItemsPerPage();
        $page = max(1, (int) $request->query->get('page', 1));
        $total = $accountRepository->count([]);
        $totalPages = max(1, (int) ceil($total / $itemsPerPage));
        $page = min($page, $totalPages);

        $accounts = $accountRepository->findBy([], ['label' => 'ASC'], $itemsPerPage, ($page - 1) * $itemsPerPage);
        $today = new \DateTimeImmutable('today');

        $balances = [];
        $pendingRecurringCounts = [];
        foreach ($accounts as $account) {
            $balances[$account->getId()] = $transactionRepository->sumUntil($today, $account);
            $pendingRecurringCounts[$account->getId()] = count($recurringTransactionGenerator->findMonthlyTemplatesPendingNextMonth($account));
        }

        return $this->render('account/index.html.twig', [
            'accounts' => $accounts,
            'balances' => $balances,
            'pendingRecurringCounts' => $pendingRecurringCounts,
            'currentPage' => $page,
            'totalPages' => $totalPages,
        ]);
    }

    #[Route('/new', name: 'app_account_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        $account = new Account();
        $form = $this->createForm(AccountFormType::class, $account);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($account);
            $entityManager->flush();

            $this->addFlash('success', $translator->trans('The account has been created.'));

            return $this->redirectToRoute('app_account_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('account/new.html.twig', [
            'account' => $account,
            'form' => $form,
            'accountTypeForm' => $this->createForm(AccountTypeType::class, new AccountType()),
            'personForm' => $this->createForm(PersonType::class, new Person()),
        ]);
    }

    #[Route('/{id}', name: 'app_account_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Request $request, Account $account): Response
    {
        if ($request->isXmlHttpRequest()) {
            return $this->render('account/_show.html.twig', [
                'account' => $account,
            ]);
        }

        return $this->render('account/show.html.twig', [
            'account' => $account,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_account_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Account $account, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        $form = $this->createForm(AccountFormType::class, $account);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', $translator->trans('The account has been updated.'));

            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'redirect' => $this->generateUrl('app_account_index')]);
            }

            return $this->redirectToRoute('app_account_index', [], Response::HTTP_SEE_OTHER);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->render('account/_form.html.twig', [
                'account' => $account,
                'form' => $form,
                'accountTypeForm' => $this->createForm(AccountTypeType::class, new AccountType()),
                'personForm' => $this->createForm(PersonType::class, new Person()),
                'button_label' => 'Update',
                'formAction' => $this->generateUrl('app_account_edit', ['id' => $account->getId()]),
            ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
        }

        return $this->render('account/edit.html.twig', [
            'account' => $account,
            'form' => $form,
            'accountTypeForm' => $this->createForm(AccountTypeType::class, new AccountType()),
            'personForm' => $this->createForm(PersonType::class, new Person()),
        ]);
    }

    #[Route('/{id}', name: 'app_account_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Account $account, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        if ($this->isCsrfTokenValid('delete'.$account->getId(), $request->getPayload()->getString('_token'))) {
            if (!$account->getTransactions()->isEmpty()) {
                $this->addFlash('danger', $translator->trans('Cannot delete an account that contains transactions.'));
            } else {
                $entityManager->remove($account);
                $entityManager->flush();

                $this->addFlash('success', $translator->trans('The account has been deleted.'));
            }
        } else {
            $this->addFlash('danger', $translator->trans('Invalid security token, please try again.'));
        }

        return $this->redirectToRoute('app_account_index', [], Response::HTTP_SEE_OTHER);
    }
}
