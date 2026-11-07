<?php

namespace App\Tests\Service;

use App\Service\DatabaseExportTracker;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class DatabaseExportTrackerTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir().'/export-tracker-'.uniqid().'.db';
        file_put_contents($this->dbPath, 'data');
    }

    protected function tearDown(): void
    {
        @unlink($this->dbPath);
        @unlink($this->dbPath.'.unsaved-changes');
    }

    private function createTracker(?string $path): DatabaseExportTracker
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getParams')->willReturn(['path' => $path]);

        return new DatabaseExportTracker($connection);
    }

    public function testDatabaseIsNotReportedAsModifiedByDefault(): void
    {
        self::assertFalse($this->createTracker($this->dbPath)->hasChangesSinceExport());
    }

    public function testModificationIsReportedUntilTheNextExport(): void
    {
        $tracker = $this->createTracker($this->dbPath);

        $tracker->markModified();
        self::assertTrue($tracker->hasChangesSinceExport());

        $tracker->markExported();
        self::assertFalse($tracker->hasChangesSinceExport());
    }

    public function testNonSqliteDatabaseIsNeverReportedAsModified(): void
    {
        $tracker = $this->createTracker(null);
        $tracker->markModified();

        self::assertFalse($tracker->hasChangesSinceExport());
    }
}
