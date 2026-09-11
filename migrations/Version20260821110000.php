<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the weekly recurrence option, remapping any existing weekly transactions to monthly';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE transactions SET recurrence = 'monthly' WHERE recurrence = 'weekly'");
    }

    public function down(Schema $schema): void
    {
        // The original weekly transactions cannot be distinguished from transactions that were
        // already monthly, so this direction is a no-op.
    }
}
