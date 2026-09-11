<?php

namespace App\Controller;

use App\Entity\Person;
use App\Form\PersonType;
use App\Repository\PersonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/person')]
final class PersonController extends AbstractController
{
    #[Route(name: 'app_person_index', methods: ['GET'])]
    public function index(PersonRepository $personRepository): Response
    {
        return $this->render('person/index.html.twig', [
            'people' => $personRepository->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'app_person_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        $person = new Person();
        $form = $this->createForm(PersonType::class, $person);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($person);
            $entityManager->flush();

            $this->addFlash('success', $translator->trans('The person has been created successfully.'));

            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'redirect' => $this->generateUrl('app_person_index')]);
            }

            return $this->redirectToRoute('app_person_index', [], Response::HTTP_SEE_OTHER);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->render('person/_form.html.twig', [
                'person' => $person,
                'form' => $form,
                'formAction' => $this->generateUrl('app_person_new'),
            ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
        }

        return $this->render('person/new.html.twig', [
            'person' => $person,
            'form' => $form,
        ]);
    }

    #[Route('/quick-create', name: 'app_person_quick_create', methods: ['POST'])]
    public function quickCreate(Request $request, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        $person = new Person();
        $form = $this->createForm(PersonType::class, $person);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($person);
            $entityManager->flush();

            return $this->json([
                'id' => $person->getId(),
                'name' => $person->getName(),
            ]);
        }

        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            $errors[] = $error->getMessage();
        }

        return $this->json(['errors' => $errors ?: [$translator->trans('Invalid form.')]], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/{id}/edit', name: 'app_person_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Person $person, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        $form = $this->createForm(PersonType::class, $person);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', $translator->trans('The person has been updated successfully.'));

            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'redirect' => $this->generateUrl('app_person_index')]);
            }

            return $this->redirectToRoute('app_person_index', [], Response::HTTP_SEE_OTHER);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->render('person/_form.html.twig', [
                'person' => $person,
                'form' => $form,
                'button_label' => $translator->trans('Update'),
                'formAction' => $this->generateUrl('app_person_edit', ['id' => $person->getId()]),
            ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
        }

        return $this->render('person/edit.html.twig', [
            'person' => $person,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_person_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Person $person, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        if ($this->isCsrfTokenValid('delete'.$person->getId(), $request->getPayload()->getString('_token'))) {
            if (!$person->getAccounts()->isEmpty()) {
                $this->addFlash('danger', $translator->trans('Cannot delete a person associated with an account.'));
            } else {
                $entityManager->remove($person);
                $entityManager->flush();

                $this->addFlash('success', $translator->trans('The person has been deleted successfully.'));
            }
        } else {
            $this->addFlash('danger', $translator->trans('Invalid security token, please try again.'));
        }

        return $this->redirectToRoute('app_person_index', [], Response::HTTP_SEE_OTHER);
    }
}
