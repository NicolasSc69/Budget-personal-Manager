<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260821063446 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE transactions ADD COLUMN title VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE __temp__transactions AS
            SELECT
              id,
              amount,
              date,
              recurrence,
              category_id,
              account_id,
              recurrence_parent_id
            FROM
              transactions
        SQL);
        $this->addSql('DROP TABLE transactions');
        $this->addSql(<<<'SQL'
            CREATE TABLE transactions (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              amount NUMERIC(10, 2) NOT NULL,
              date DATE NOT NULL,
              recurrence VARCHAR(20) NOT NULL,
              category_id INTEGER NOT NULL,
              account_id INTEGER NOT NULL,
              recurrence_parent_id INTEGER DEFAULT NULL,
              CONSTRAINT FK_EAA81A4C12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_EAA81A4C9B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT FK_EAA81A4C6ADCDB06 FOREIGN KEY (recurrence_parent_id) REFERENCES transactions (id) ON DELETE
              SET
                NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO transactions (
              id, amount, date, recurrence, category_id,
              account_id, recurrence_parent_id
            )
            SELECT
              id,
              amount,
              date,
              recurrence,
              category_id,
              account_id,
              recurrence_parent_id
            FROM
              __temp__transactions
        SQL);
        $this->addSql('DROP TABLE __temp__transactions');
        $this->addSql('CREATE INDEX IDX_EAA81A4C12469DE2 ON transactions (category_id)');
        $this->addSql('CREATE INDEX IDX_EAA81A4C9B6B5FBA ON transactions (account_id)');
        $this->addSql('CREATE INDEX IDX_EAA81A4C6ADCDB06 ON transactions (recurrence_parent_id)');
    }
}
