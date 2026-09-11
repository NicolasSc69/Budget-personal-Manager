<?php

namespace App\Controller;

use App\Repository\AccountRepository;
use App\Repository\AppSettingRepository;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use App\Service\DatabaseBackupService;
use App\Service\TestReportReader;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin')]
final class AdminController extends AbstractController
{
    #[Route(name: 'app_admin_index', methods: ['GET'])]
    public function index(
        Connection $connection,
        AccountRepository $accountRepository,
        CategoryRepository $categoryRepository,
        TransactionRepository $transactionRepository,
        AppSettingRepository $appSettingRepository,
        DatabaseBackupService $databaseBackupService,
        TestReportReader $testReportReader,
        KernelInterface $kernel,
    ): Response {
        $dbPath = $this->getDatabasePath($connection);
        $exists = is_file($dbPath);
        $backupFilePath = $databaseBackupService->getBackupFilePath();
        $backupFileExists = $backupFilePath && is_file($backupFilePath);

        return $this->render('admin/index.html.twig', [
            'dbSize' => $exists ? filesize($dbPath) : null,
            'dbModifiedAt' => $exists ? $this->fileModifiedAt($dbPath) : null,
            'accountCount' => $accountRepository->count([]),
            'categoryCount' => $categoryRepository->count([]),
            'transactionCount' => $transactionRepository->count([]),
            'itemsPerPage' => $appSettingRepository->getSettings()->getItemsPerPage(),
            'theme' => $appSettingRepository->getSettings()->getTheme(),
            'backupDirectory' => $appSettingRepository->getSettings()->getBackupDirectory(),
            'backupFileExists' => $backupFileExists,
            'backupFileModifiedAt' => $backupFileExists ? $this->fileModifiedAt($backupFilePath) : null,
            'testReport' => $testReportReader->read(),
            'caches' => $this->listCacheDirectories($kernel),
        ]);
    }

    #[Route('/settings', name: 'app_admin_settings', methods: ['POST'])]
    public function updateSettings(Request $request, AppSettingRepository $appSettingRepository, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        if (!$this->isCsrfTokenValid('admin_settings', $request->request->getString('_token'))) {
            $this->addFlash('danger', $translator->trans('Invalid security token, please try again.'));

            return $this->redirectToRoute('app_admin_index');
        }

        $itemsPerPage = (int) $request->request->get('items_per_page', 20);

        if ($itemsPerPage < 5 || $itemsPerPage > 200) {
            $this->addFlash('danger', $translator->trans('The number of items per page must be between 5 and 200.'));

            return $this->redirectToRoute('app_admin_index');
        }

        $theme = $request->request->getString('theme', 'auto');

        if (!\in_array($theme, ['light', 'dark', 'auto'], true)) {
            $this->addFlash('danger', $translator->trans('Invalid theme.'));

            return $this->redirectToRoute('app_admin_index');
        }

        $settings = $appSettingRepository->getSettings();
        $settings->setItemsPerPage($itemsPerPage);
        $settings->setTheme($theme);
        $entityManager->flush();

        $this->addFlash('success', $translator->trans('Settings have been updated.'));

        return $this->redirectToRoute('app_admin_index');
    }

    #[Route('/backup-settings', name: 'app_admin_backup_settings', methods: ['POST'])]
    public function updateBackupSettings(Request $request, AppSettingRepository $appSettingRepository, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        if (!$this->isCsrfTokenValid('admin_backup_settings', $request->request->getString('_token'))) {
            $this->addFlash('danger', $translator->trans('Invalid security token, please try again.'));

            return $this->redirectToRoute('app_admin_index');
        }

        $backupDirectory = trim($request->request->getString('backup_directory'));

        if ('' !== $backupDirectory && !str_starts_with($backupDirectory, '/')) {
            $this->addFlash('danger', $translator->trans('The backup directory must be an absolute path (starting with "/").'));

            return $this->redirectToRoute('app_admin_index');
        }

        if ('' !== $backupDirectory && !is_dir($backupDirectory)) {
            error_clear_last();

            if (!@mkdir($backupDirectory, 0775, true)) {
                $reason = error_get_last()['message'] ?? $translator->trans('unknown reason');

                $this->addFlash('danger', $translator->trans('Unable to create directory "%directory%" (%reason%).', ['%directory%' => $backupDirectory, '%reason%' => $reason]));

                return $this->redirectToRoute('app_admin_index');
            }
        }

        if ('' !== $backupDirectory && !is_writable($backupDirectory)) {
            $this->addFlash('danger', $translator->trans('Directory "%directory%" is not writable.', ['%directory%' => $backupDirectory]));

            return $this->redirectToRoute('app_admin_index');
        }

        $appSettingRepository->getSettings()->setBackupDirectory('' !== $backupDirectory ? $backupDirectory : null);
        $entityManager->flush();

        $this->addFlash('success', '' !== $backupDirectory
            ? $translator->trans('Automatic backup is now enabled for this directory.')
            : $translator->trans('Automatic backup has been disabled.'));

        return $this->redirectToRoute('app_admin_index');
    }

    #[Route('/backup/browse', name: 'app_admin_backup_browse', methods: ['GET'])]
    public function browseBackupDirectories(Request $request): Response
    {
        $path = realpath($request->query->get('path', '/')) ?: '/';

        if (!is_dir($path)) {
            $path = '/';
        }

        $directories = [];
        foreach (scandir($path) ?: [] as $entry) {
            if (str_starts_with($entry, '.')) {
                continue;
            }

            $fullPath = rtrim($path, '/').'/'.$entry;

            if (is_dir($fullPath) && is_readable($fullPath)) {
                $directories[] = $entry;
            }
        }

        sort($directories, SORT_NATURAL | SORT_FLAG_CASE);

        return $this->json([
            'path' => $path,
            'parent' => '/' !== $path ? \dirname($path) : null,
            'directories' => $directories,
            'writable' => is_writable($path),
        ]);
    }

    #[Route('/backup', name: 'app_admin_backup', methods: ['POST'])]
    public function backup(Request $request, DatabaseBackupService $databaseBackupService, TranslatorInterface $translator): Response
    {
        $redirectUrl = $this->resolveLocalRedirectUrl($request->request->get('redirectTo'), $this->generateUrl('app_admin_index'));

        if (!$this->isCsrfTokenValid('admin_backup', $request->request->getString('_token'))) {
            $this->addFlash('danger', $translator->trans('Invalid security token, please try again.'));

            return $this->redirect($redirectUrl, Response::HTTP_SEE_OTHER);
        }

        try {
            $databaseBackupService->backupNow();
            $this->addFlash('success', $translator->trans('The database backup completed successfully.'));
        } catch (\RuntimeException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirect($redirectUrl, Response::HTTP_SEE_OTHER);
    }

    #[Route('/backup/download', name: 'app_admin_backup_download', methods: ['GET'])]
    public function downloadBackup(DatabaseBackupService $databaseBackupService, TranslatorInterface $translator): Response
    {
        $path = $databaseBackupService->getBackupFilePath();

        if (!$path || !is_file($path)) {
            $this->addFlash('danger', $translator->trans('Backup file not found.'));

            return $this->redirectToRoute('app_admin_index');
        }

        $filename = 'compta-backup-'.$this->fileModifiedAt($path)->format('Y-m-d_His').'.db';

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);

        return $response;
    }

    #[Route('/export', name: 'app_admin_export', methods: ['GET'])]
    public function export(Connection $connection, TranslatorInterface $translator): Response
    {
        $dbPath = $this->getDatabasePath($connection);

        if (!is_file($dbPath)) {
            $this->addFlash('danger', $translator->trans('Database file not found.'));

            return $this->redirectToRoute('app_admin_index');
        }

        $filename = 'compta-export-'.(new \DateTimeImmutable())->format('Y-m-d_His').'.db';

        $response = new BinaryFileResponse($dbPath);
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);

        return $response;
    }

    #[Route('/import', name: 'app_admin_import', methods: ['POST'])]
    public function import(Request $request, Connection $connection, TranslatorInterface $translator): Response
    {
        if (!$this->isCsrfTokenValid('admin_import', $request->request->getString('_token'))) {
            $this->addFlash('danger', $translator->trans('Invalid security token, please try again.'));

            return $this->redirectToRoute('app_admin_index');
        }

        $uploadedFile = $request->files->get('database_file');

        if (!$uploadedFile instanceof UploadedFile || !$uploadedFile->isValid()) {
            $this->addFlash('danger', $translator->trans('Please select a valid file (it might be too large for the server).'));

            return $this->redirectToRoute('app_admin_index');
        }

        $header = file_get_contents($uploadedFile->getPathname(), false, null, 0, 16);
        if ("SQLite format 3\000" !== $header) {
            $this->addFlash('danger', $translator->trans('The selected file is not a valid SQLite database.'));

            return $this->redirectToRoute('app_admin_index');
        }

        $dbPath = $this->getDatabasePath($connection);
        $connection->close();

        if (is_file($dbPath)) {
            copy($dbPath, $dbPath.'.bak-'.(new \DateTimeImmutable())->format('YmdHis'));
        }

        if (!@rename($uploadedFile->getPathname(), $dbPath)) {
            copy($uploadedFile->getPathname(), $dbPath);
        }

        $this->addFlash('success', $translator->trans('The database has been imported successfully. A backup of the previous database was kept next to the file.'));

        return $this->redirectToRoute('app_admin_index');
    }

    #[Route('/clear-cache', name: 'app_admin_clear_cache', methods: ['POST'])]
    public function clearCache(Request $request, KernelInterface $kernel, TranslatorInterface $translator): Response
    {
        if (!$this->isCsrfTokenValid('admin_clear_cache', $request->request->getString('_token'))) {
            $this->addFlash('danger', $translator->trans('Invalid security token, please try again.'));

            return $this->redirectToRoute('app_admin_index');
        }

        // Deleting the cache directory while this very request is still using its (lazily-loaded)
        // container service files would break the request itself, so the removal is deferred until
        // after the response has been sent.
        //
        // Every environment's cache under var/cache/ is cleared, not just the current one (normally
        // "prod"): var/cache/test in particular is only ever written by the Admin > Unit tests page
        // running phpunit under a forced APP_ENV=test (see phpunit.dist.xml and
        // TestReportController), so nothing else would ever invalidate it. Left stale, it silently
        // keeps serving an outdated compiled container to that page — e.g. an "ArgumentCountError"
        // after a service's constructor changes — even right after an admin clears "the" cache
        // believing it covers everything.
        $cacheDirs = $this->cacheDirectoryPaths($kernel);
        register_shutdown_function(static function () use ($cacheDirs): void {
            try {
                (new Filesystem())->remove($cacheDirs);
            } catch (\Throwable) {
                // Nothing left to report to the user at this point, the response was already sent.
            }
        });

        $this->addFlash('success', $translator->trans('The cache has been cleared.'));

        return $this->redirectToRoute('app_admin_index');
    }

    private function getDatabasePath(Connection $connection): string
    {
        $path = $connection->getParams()['path'] ?? null;

        if (!$path) {
            throw new \RuntimeException('This page only supports SQLite databases.');
        }

        return $path;
    }

    private function fileModifiedAt(string $path): \DateTimeImmutable
    {
        return $this->timestampToDateTime(filemtime($path));
    }

    private function timestampToDateTime(int $timestamp): \DateTimeImmutable
    {
        // A DateTimeImmutable built from a "@timestamp" is always in UTC regardless of the
        // default timezone, so it must be converted explicitly before being displayed.
        return (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    /**
     * @return string[] absolute paths of every var/cache/<env> directory currently on disk
     */
    private function cacheDirectoryPaths(KernelInterface $kernel): array
    {
        $dirs = glob(\dirname($kernel->getCacheDir()).'/*', GLOB_ONLYDIR) ?: [];
        sort($dirs, SORT_STRING);

        return $dirs;
    }

    /**
     * @return list<array{env: string, current: bool, size: int, fileCount: int, modifiedAt: ?\DateTimeImmutable}>
     */
    private function listCacheDirectories(KernelInterface $kernel): array
    {
        $currentEnv = $kernel->getEnvironment();

        return array_map(function (string $dir) use ($currentEnv): array {
            $size = 0;
            $fileCount = 0;
            $lastModified = null;

            // This runs right after the directory may have been wiped and lazily recreated by the
            // current request itself (see clearCache()): a permission glitch or a file disappearing
            // mid-scan (another process still warming it up) must not turn a simple cache listing
            // into a 500 on the whole admin page. Worst case, that one row just shows as empty.
            try {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                );

                foreach ($files as $file) {
                    if ($file->isFile()) {
                        $size += $file->getSize();
                        ++$fileCount;
                        $lastModified = max($lastModified ?? 0, $file->getMTime());
                    }
                }
            } catch (\Throwable) {
                $size = 0;
                $fileCount = 0;
                $lastModified = null;
            }

            return [
                'env' => basename($dir),
                'current' => basename($dir) === $currentEnv,
                'size' => $size,
                'fileCount' => $fileCount,
                'modifiedAt' => null !== $lastModified ? $this->timestampToDateTime($lastModified) : null,
            ];
        }, $this->cacheDirectoryPaths($kernel));
    }

    private function resolveLocalRedirectUrl(mixed $candidate, string $fallbackUrl): string
    {
        if (is_string($candidate) && '' !== $candidate && str_starts_with($candidate, '/') && !str_starts_with($candidate, '//')) {
            return $candidate;
        }

        return $fallbackUrl;
    }
}
