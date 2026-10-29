<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add transactions.is_deferred_debit and transactions.debit_date for deferred debit card payments';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transactions ADD COLUMN is_deferred_debit BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE transactions ADD COLUMN debit_date DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transactions DROP COLUMN debit_date');
        $this->addSql('ALTER TABLE transactions DROP COLUMN is_deferred_debit');
    }
}
