<?php

namespace App\Service;

use App\Repository\AppSettingRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Translation\TranslatorInterface;

class DatabaseBackupService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AppSettingRepository $appSettingRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function isConfigured(): bool
    {
        return null !== $this->getBackupDirectory();
    }

    public function getBackupDirectory(): ?string
    {
        $directory = $this->appSettingRepository->findExistingSettings()?->getBackupDirectory();

        return $directory && '' !== trim($directory) ? $directory : null;
    }

    /**
     * Path of the single backup file kept in the configured directory, or null if not configured.
     */
    public function getBackupFilePath(): ?string
    {
        $directory = $this->getBackupDirectory();

        return $directory ? rtrim($directory, '/').'/compta-backup.db' : null;
    }

    /**
     * Triggers a backup without blocking the current request, used after every database write.
     */
    public function backupInBackground(): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $this->runBackup(wait: false);
    }

    /**
     * Triggers a backup and waits for it to finish, used for the manual "Sauvegarder" action.
     */
    public function backupNow(): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException($this->translator->trans('No backup directory is configured.'));
        }

        return $this->runBackup(wait: true);
    }

    private function runBackup(bool $wait): string
    {
        $directory = $this->getBackupDirectory();

        if (null === $directory || (!is_dir($directory) && !@mkdir($directory, 0775, true)) || !is_writable($directory)) {
            throw new \RuntimeException($this->translator->trans('Backup directory "%directory%" not found or not writable.', ['%directory%' => $directory]));
        }

        $dbPath = $this->getDatabasePath();
        $target = $this->getBackupFilePath();

        if (is_file($target)) {
            unlink($target);
        }

        $process = new Process(['cp', $dbPath, $target]);

        if ($wait) {
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \RuntimeException($this->translator->trans('Backup failed: %error%', ['%error%' => $process->getErrorOutput()]));
            }
        } else {
            // Without this option, Process::__destruct() sends SIGTERM/SIGKILL to "cp" once the PHP
            // request ends (even though $process is unreferenced sooner, PHP only destructs it at
            // shutdown due to internal circular references), killing the copy before it finishes and
            // leaving no backup file behind since the previous one was already unlinked above.
            $process->setOptions(['create_new_console' => true]);
            $process->start();
        }

        return $target;
    }

    private function getDatabasePath(): string
    {
        $path = $this->connection->getParams()['path'] ?? null;

        if (!\is_string($path) || '' === $path) {
            throw new \RuntimeException($this->translator->trans('This feature only supports SQLite databases.'));
        }

        return $path;
    }
}
