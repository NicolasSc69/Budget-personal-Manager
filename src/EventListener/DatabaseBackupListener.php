<?php

namespace App\EventListener;

use App\Service\DatabaseBackupService;
use Doctrine\ORM\Event\PostFlushEventArgs;

class DatabaseBackupListener
{
    public function __construct(private readonly DatabaseBackupService $databaseBackupService)
    {
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $this->databaseBackupService->backupInBackground();
    }
}
