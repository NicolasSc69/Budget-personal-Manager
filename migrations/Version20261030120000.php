<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add transactions.recurrence_generated_until so deleted recurring occurrences are not generated again';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transactions ADD COLUMN recurrence_generated_until DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transactions DROP COLUMN recurrence_generated_until');
    }
}
