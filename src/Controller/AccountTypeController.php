<?php

namespace App\Controller;

use App\Entity\AccountType;
use App\Form\AccountTypeType;
use App\Repository\AccountTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/account-type')]
final class AccountTypeController extends AbstractController
{
    #[Route(name: 'app_account_type_index', methods: ['GET'])]
    public function index(AccountTypeRepository $accountTypeRepository): Response
    {
        return $this->render('account_type/index.html.twig', [
            'account_types' => $accountTypeRepository->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'app_account_type_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        $accountType = new AccountType();
        $form = $this->createForm(AccountTypeType::class, $accountType);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($accountType);
            $entityManager->flush();

            $this->addFlash('success', $translator->trans('The account type has been created.'));

            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'redirect' => $this->generateUrl('app_account_type_index')]);
            }

            return $this->redirectToRoute('app_account_type_index', [], Response::HTTP_SEE_OTHER);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->render('account_type/_form.html.twig', [
                'account_type' => $accountType,
                'form' => $form,
                'formAction' => $this->generateUrl('app_account_type_new'),
            ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
        }

        return $this->render('account_type/new.html.twig', [
            'account_type' => $accountType,
            'form' => $form,
        ]);
    }

    #[Route('/quick-create', name: 'app_account_type_quick_create', methods: ['POST'])]
    public function quickCreate(Request $request, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        $accountType = new AccountType();
        $form = $this->createForm(AccountTypeType::class, $accountType);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($accountType);
            $entityManager->flush();

            return $this->json([
                'id' => $accountType->getId(),
                'name' => $accountType->getName(),
            ]);
        }

        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            $errors[] = $error->getMessage();
        }

        return $this->json(['errors' => $errors ?: [$translator->trans('Invalid form.')]], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/{id}/edit', name: 'app_account_type_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, AccountType $accountType, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        $form = $this->createForm(AccountTypeType::class, $accountType);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', $translator->trans('The account type has been updated.'));

            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'redirect' => $this->generateUrl('app_account_type_index')]);
            }

            return $this->redirectToRoute('app_account_type_index', [], Response::HTTP_SEE_OTHER);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->render('account_type/_form.html.twig', [
                'account_type' => $accountType,
                'form' => $form,
                'button_label' => 'Update',
                'formAction' => $this->generateUrl('app_account_type_edit', ['id' => $accountType->getId()]),
            ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
        }

        return $this->render('account_type/edit.html.twig', [
            'account_type' => $accountType,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_account_type_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, AccountType $accountType, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        if ($this->isCsrfTokenValid('delete'.$accountType->getId(), $request->getPayload()->getString('_token'))) {
            if (!$accountType->getAccounts()->isEmpty()) {
                $this->addFlash('danger', $translator->trans('Cannot delete an account type used by an account.'));
            } else {
                $entityManager->remove($accountType);
                $entityManager->flush();

                $this->addFlash('success', $translator->trans('The account type has been deleted.'));
            }
        } else {
            $this->addFlash('danger', $translator->trans('Invalid security token, please try again.'));
        }

        return $this->redirectToRoute('app_account_type_index', [], Response::HTTP_SEE_OTHER);
    }
}
