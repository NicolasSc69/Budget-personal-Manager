<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260821062611 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE account ADD COLUMN initial_balance NUMERIC(10, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE __temp__account AS
            SELECT
              id,
              label,
              is_savings,
              interest_rate,
              max_amount,
              type_id
            FROM
              account
        SQL);
        $this->addSql('DROP TABLE account');
        $this->addSql(<<<'SQL'
            CREATE TABLE account (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              label VARCHAR(100) NOT NULL,
              is_savings BOOLEAN NOT NULL,
              interest_rate NUMERIC(5, 2) DEFAULT NULL,
              max_amount NUMERIC(10, 2) DEFAULT NULL,
              type_id INTEGER NOT NULL,
              CONSTRAINT FK_7D3656A4C54C8C93 FOREIGN KEY (type_id) REFERENCES account_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO account (
              id, label, is_savings, interest_rate,
              max_amount, type_id
            )
            SELECT
              id,
              label,
              is_savings,
              interest_rate,
              max_amount,
              type_id
            FROM
              __temp__account
        SQL);
        $this->addSql('DROP TABLE __temp__account');
        $this->addSql('CREATE INDEX IDX_7D3656A4C54C8C93 ON account (type_id)');
    }
}
