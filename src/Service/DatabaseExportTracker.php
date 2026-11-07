<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Remembers whether an entity was added, modified or deleted since the database was last
 * downloaded, through a flag file kept next to the SQLite database (so that it survives the
 * session and is shared by every tab).
 */
class DatabaseExportTracker
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function markModified(): void
    {
        $flagPath = $this->getFlagPath();

        if (null !== $flagPath) {
            @touch($flagPath);
        }
    }

    public function markExported(): void
    {
        $flagPath = $this->getFlagPath();

        if (null !== $flagPath && is_file($flagPath)) {
            @unlink($flagPath);
        }
    }

    public function hasChangesSinceExport(): bool
    {
        $flagPath = $this->getFlagPath();

        return null !== $flagPath && is_file($flagPath);
    }

    private function getFlagPath(): ?string
    {
        $path = $this->connection->getParams()['path'] ?? null;

        return \is_string($path) && '' !== $path ? $path.'.unsaved-changes' : null;
    }
}
