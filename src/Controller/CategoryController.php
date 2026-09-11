<?php

namespace App\Controller;

use App\Entity\Category;
use App\Form\CategoryType;
use App\Repository\AppSettingRepository;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/category')]
final class CategoryController extends AbstractController
{
    #[Route(name: 'app_category_index', methods: ['GET'])]
    public function index(Request $request, CategoryRepository $categoryRepository, AppSettingRepository $appSettingRepository, TransactionRepository $transactionRepository): Response
    {
        $search = trim((string) $request->query->get('search', ''));

        $itemsPerPage = $appSettingRepository->getSettings()->getItemsPerPage();
        $page = max(1, (int) $request->query->get('page', 1));
        $orderedCategories = $categoryRepository->findAllOrderedByHierarchy('' !== $search ? $search : null);
        $total = count($orderedCategories);
        $totalPages = max(1, (int) ceil($total / $itemsPerPage));
        $page = min($page, $totalPages);

        $pagedCategories = array_slice($orderedCategories, ($page - 1) * $itemsPerPage, $itemsPerPage);

        $transactionCounts = [];
        foreach ($pagedCategories as $category) {
            $transactionCounts[$category->getId()] = $transactionRepository->countFiltered(category: $category);
        }

        return $this->render('category/index.html.twig', [
            'categories' => $pagedCategories,
            'transactionCounts' => $transactionCounts,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'filterSearch' => $search,
            'queryParams' => array_filter(['search' => $search], static fn ($value) => '' !== $value),
        ]);
    }

    #[Route('/new', name: 'app_category_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, CategoryRepository $categoryRepository, TranslatorInterface $translator): Response
    {
        $category = new Category();
        $form = $this->createForm(CategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->ensureUniqueColor($category, $categoryRepository);

            $entityManager->persist($category);
            $entityManager->flush();

            $this->addFlash('success', $translator->trans('The tag has been created successfully.'));

            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'redirect' => $this->generateUrl('app_category_index')]);
            }

            return $this->redirectToRoute('app_category_index', [], Response::HTTP_SEE_OTHER);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->render('category/_form.html.twig', [
                'category' => $category,
                'form' => $form,
                'existingCategoriesJson' => $this->buildExistingCategoriesJson($categoryRepository, null),
                'formAction' => $this->generateUrl('app_category_new'),
            ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
        }

        return $this->render('category/new.html.twig', [
            'category' => $category,
            'form' => $form,
            'existingCategoriesJson' => $this->buildExistingCategoriesJson($categoryRepository, null),
        ]);
    }

    #[Route('/quick-create', name: 'app_category_quick_create', methods: ['POST'])]
    public function quickCreate(Request $request, EntityManagerInterface $entityManager, CategoryRepository $categoryRepository, TranslatorInterface $translator): Response
    {
        $category = new Category();
        $form = $this->createForm(CategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->ensureUniqueColor($category, $categoryRepository);

            $entityManager->persist($category);
            $entityManager->flush();

            return $this->json([
                'id' => $category->getId(),
                'name' => $category->getName(),
                'color' => $category->getColor(),
            ]);
        }

        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            $errors[] = $error->getMessage();
        }

        return $this->json(['errors' => $errors ?: [$translator->trans('Invalid form.')]], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/randomize-colors', name: 'app_category_randomize_colors', methods: ['POST'])]
    public function randomizeColors(Request $request, CategoryRepository $categoryRepository, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        if (!$this->isCsrfTokenValid('randomize_category_colors', $request->getPayload()->getString('_token'))) {
            $this->addFlash('danger', $translator->trans('Invalid security token, please try again.'));

            return $this->redirectToRoute('app_category_index', [], Response::HTTP_SEE_OTHER);
        }

        $categories = $categoryRepository->findBy([], ['id' => 'ASC']);
        $count = count($categories);

        if ($count > 0) {
            $hueStep = 360 / $count;
            $hueOffset = random_int(0, 359);
            $slots = range(0, $count - 1);
            shuffle($slots);

            $usedColors = [];

            foreach ($categories as $index => $category) {
                $hue = ((int) round($slots[$index] * $hueStep) + $hueOffset) % 360;
                $saturation = random_int(35, 55);
                $lightness = random_int(35, 48);
                $color = $this->hslToHex($hue, $saturation, $lightness);

                while (\in_array($color, $usedColors, true)) {
                    $hue = ($hue + 1) % 360;
                    $color = $this->hslToHex($hue, $saturation, $lightness);
                }

                $usedColors[] = $color;
                $category->setColor($color);
            }

            $entityManager->flush();

            $this->addFlash('success', $translator->trans('Tag colors have been shuffled randomly.'));
        }

        return $this->redirectToRoute('app_category_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Maximum number of columns accepted in the import CSV: the tag name and, optionally, its
     * pipe-separated list of parent names.
     */
    private const int IMPORT_MAX_COLUMNS = 2;

    #[Route('/import', name: 'app_category_import', methods: ['GET', 'POST'])]
    public function import(Request $request, EntityManagerInterface $entityManager, CategoryRepository $categoryRepository, TranslatorInterface $translator): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('category_import', $request->request->getString('_token'))) {
                $this->addFlash('danger', $translator->trans('Invalid security token, please try again.'));

                return $this->redirectToRoute('app_category_import');
            }

            $uploadedFile = $request->files->get('csv_file');

            if (!$uploadedFile instanceof UploadedFile || !$uploadedFile->isValid()) {
                $this->addFlash('danger', $translator->trans('Please select a valid CSV file.'));

                return $this->redirectToRoute('app_category_import');
            }

            if ('csv' !== strtolower($uploadedFile->getClientOriginalExtension())) {
                $this->addFlash('danger', $translator->trans('Only .csv files are accepted.'));

                return $this->redirectToRoute('app_category_import');
            }

            try {
                $rows = $this->parseImportCsv($uploadedFile->getPathname(), $translator);
                $result = $this->importCategoryRows($rows, $entityManager, $categoryRepository);
                $entityManager->flush();

                $this->addFlash('success', $translator->trans('%created% tag(s) created, %existing% already existed and were reused.', [
                    '%created%' => $result['created'],
                    '%existing%' => $result['reused'],
                ]));
            } catch (\RuntimeException $exception) {
                $this->addFlash('danger', $exception->getMessage());
            }

            return $this->redirectToRoute('app_category_import');
        }

        return $this->render('category/import.html.twig');
    }

    /**
     * Reads and validates the uploaded file, returning one [name, parentsField] pair per non-blank
     * line. Nothing is persisted here: a single malformed line rejects the whole file up front
     * rather than leaving a half-imported set of tags behind.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function parseImportCsv(string $path, TranslatorInterface $translator): array
    {
        $handle = fopen($path, 'rb');

        if (false === $handle) {
            throw new \RuntimeException($translator->trans('Unable to read the uploaded file.'));
        }

        $rows = [];

        try {
            $lineNumber = 0;

            while (false !== ($fields = fgetcsv($handle, 0, ','))) {
                ++$lineNumber;

                if ([null] === $fields) {
                    continue; // blank line
                }

                if (\count($fields) > self::IMPORT_MAX_COLUMNS) {
                    throw new \RuntimeException($translator->trans('Line %line%: invalid format, expected 1 or 2 columns separated by a comma.', ['%line%' => $lineNumber]));
                }

                $name = trim((string) ($fields[0] ?? ''));
                $name = ltrim($name, "\u{FEFF}"); // strip a UTF-8 BOM on the very first field

                if ('' === $name) {
                    continue;
                }

                $rows[] = [$name, trim((string) ($fields[1] ?? ''))];
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * Creates the tags described by $rows, reusing any tag that already exists instead of creating
     * a duplicate. "Already exists" is checked against name + parent (a tag is reused as soon as
     * both match, regardless of its color), and every tag created here gets a color guaranteed
     * unique among every color already in use — together this can never collide with
     * Category::$UniqueEntity's (name, color, parent) constraint.
     *
     * @param list<array{0: string, 1: string}> $rows
     *
     * @return array{created: int, reused: int}
     */
    private function importCategoryRows(array $rows, EntityManagerInterface $entityManager, CategoryRepository $categoryRepository): array
    {
        $usedColors = array_map(
            static fn (Category $category): string => strtoupper($category->getColor()),
            $categoryRepository->findBy([]),
        );

        $lookup = [];
        foreach ($categoryRepository->findBy([]) as $category) {
            $lookup[$this->importCategoryKey($category->getName(), $category->getParent()?->getName())] = $category;
        }

        $created = 0;
        $reused = 0;

        $findOrCreate = function (string $name, ?string $parentName) use (&$lookup, &$usedColors, &$created, &$reused, $entityManager): Category {
            $key = $this->importCategoryKey($name, $parentName);

            if (isset($lookup[$key])) {
                ++$reused;

                return $lookup[$key];
            }

            $parent = null !== $parentName ? ($lookup[$this->importCategoryKey($parentName, null)] ?? null) : null;

            $category = new Category();
            $category->setName($name);
            $category->setParent($parent);
            $category->setColor($this->generateUniqueColor($usedColors));

            $entityManager->persist($category);
            $lookup[$key] = $category;
            ++$created;

            return $category;
        };

        foreach ($rows as [$name, $parentsField]) {
            if ('' === $parentsField) {
                $findOrCreate($name, null);

                continue;
            }

            $parentNames = array_filter(array_map('trim', explode('|', $parentsField)), static fn (string $parentName): bool => '' !== $parentName);

            foreach ($parentNames as $parentName) {
                $findOrCreate($parentName, null);
                $findOrCreate($name, $parentName);
            }
        }

        return ['created' => $created, 'reused' => $reused];
    }

    private function importCategoryKey(string $name, ?string $parentName): string
    {
        return mb_strtolower($name).'#'.mb_strtolower($parentName ?? '');
    }

    private function generateUniqueColor(array &$usedColors): string
    {
        do {
            $hue = random_int(0, 359);
            $saturation = random_int(35, 55);
            $lightness = random_int(35, 48);
            $color = $this->hslToHex($hue, $saturation, $lightness);
        } while (\in_array($color, $usedColors, true));

        $usedColors[] = $color;

        return $color;
    }

    /**
     * Matches Category::$color's default value: an untouched color picker on a new category
     * submits this value, which we treat the same as "no color chosen".
     */
    private const UNSELECTED_COLOR = '#6C757D';

    private function ensureUniqueColor(Category $category, CategoryRepository $categoryRepository): void
    {
        $usedColors = array_map(
            static fn (Category $existing) => strtoupper($existing->getColor()),
            array_filter(
                $categoryRepository->findBy([], ['id' => 'ASC']),
                static fn (Category $existing) => $existing->getId() !== $category->getId()
            )
        );

        $isNew = null === $category->getId();
        $color = strtoupper((string) $category->getColor());

        if ('' === $color || ($isNew && self::UNSELECTED_COLOR === $color) || \in_array($color, $usedColors, true)) {
            do {
                $hue = random_int(0, 359);
                $saturation = random_int(35, 55);
                $lightness = random_int(35, 48);
                $generated = $this->hslToHex($hue, $saturation, $lightness);
            } while (\in_array($generated, $usedColors, true));

            $category->setColor($generated);
        }
    }

    private function hslToHex(int $hue, int $saturation, int $lightness): string
    {
        $s = $saturation / 100;
        $l = $lightness / 100;

        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($hue / 60, 2) - 1));
        $m = $l - $c / 2;

        [$r, $g, $b] = match (true) {
            $hue < 60 => [$c, $x, 0],
            $hue < 120 => [$x, $c, 0],
            $hue < 180 => [0, $c, $x],
            $hue < 240 => [0, $x, $c],
            $hue < 300 => [$x, 0, $c],
            default => [$c, 0, $x],
        };

        return \sprintf('#%02X%02X%02X', (int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255));
    }

    #[Route('/{id}', name: 'app_category_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Request $request, Category $category): Response
    {
        if ($request->isXmlHttpRequest()) {
            return $this->render('category/_show.html.twig', [
                'category' => $category,
            ]);
        }

        return $this->render('category/show.html.twig', [
            'category' => $category,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_category_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Category $category, EntityManagerInterface $entityManager, CategoryRepository $categoryRepository, TranslatorInterface $translator): Response
    {
        $form = $this->createForm(CategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->ensureUniqueColor($category, $categoryRepository);

            $entityManager->flush();

            $this->addFlash('success', $translator->trans('The tag has been updated successfully.'));

            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'redirect' => $this->generateUrl('app_category_index')]);
            }

            return $this->redirectToRoute('app_category_index', [], Response::HTTP_SEE_OTHER);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->render('category/_form.html.twig', [
                'category' => $category,
                'form' => $form,
                'existingCategoriesJson' => $this->buildExistingCategoriesJson($categoryRepository, $category),
                'button_label' => 'Update',
                'formAction' => $this->generateUrl('app_category_edit', ['id' => $category->getId()]),
            ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
        }

        return $this->render('category/edit.html.twig', [
            'category' => $category,
            'form' => $form,
            'existingCategoriesJson' => $this->buildExistingCategoriesJson($categoryRepository, $category),
        ]);
    }

    #[Route('/{id}', name: 'app_category_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Category $category, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        if ($this->isCsrfTokenValid('delete'.$category->getId(), $request->getPayload()->getString('_token'))) {
            if (!$category->getTransactions()->isEmpty()) {
                $this->addFlash('danger', $translator->trans('Cannot delete a tag that has transactions.'));
            } elseif (!$category->getChildren()->isEmpty()) {
                $this->addFlash('danger', $translator->trans('Cannot delete a tag that has sub-tags.'));
            } else {
                $entityManager->remove($category);
                $entityManager->flush();

                $this->addFlash('success', $translator->trans('The tag has been deleted successfully.'));
            }
        } else {
            $this->addFlash('danger', $translator->trans('Invalid security token, please try again.'));
        }

        return $this->redirectToRoute('app_category_index', [], Response::HTTP_SEE_OTHER);
    }

    private function buildExistingCategoriesJson(CategoryRepository $categoryRepository, ?Category $exclude): string
    {
        $categories = array_filter(
            $categoryRepository->findAllOrderedByHierarchy(),
            static fn (Category $category) => null === $exclude || $category->getId() !== $exclude->getId(),
        );

        $data = array_map(static fn (Category $category) => [
            'id' => $category->getId(),
            'name' => $category->getFullName(),
            'color' => $category->getColor(),
        ], array_values($categories));

        return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    }
}
