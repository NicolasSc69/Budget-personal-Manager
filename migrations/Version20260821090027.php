<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260821090027 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE person (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(100) NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_34DCD1765E237E06 ON person (name)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__account AS SELECT id, label, type_id, is_savings, interest_rate, max_amount, initial_balance FROM account');
        $this->addSql('DROP TABLE account');
        $this->addSql('CREATE TABLE account (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, label VARCHAR(100) NOT NULL, type_id INTEGER NOT NULL, is_savings BOOLEAN NOT NULL, interest_rate NUMERIC(5, 2) DEFAULT NULL, max_amount NUMERIC(10, 2) DEFAULT NULL, initial_balance NUMERIC(10, 2) DEFAULT NULL, person_id INTEGER DEFAULT NULL, CONSTRAINT FK_7D3656A4C54C8C93 FOREIGN KEY (type_id) REFERENCES account_type (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7D3656A4217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO account (id, label, type_id, is_savings, interest_rate, max_amount, initial_balance) SELECT id, label, type_id, is_savings, interest_rate, max_amount, initial_balance FROM __temp__account');
        $this->addSql('DROP TABLE __temp__account');
        $this->addSql('CREATE INDEX IDX_7D3656A4C54C8C93 ON account (type_id)');
        $this->addSql('CREATE INDEX IDX_7D3656A4217BBB47 ON account (person_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE person');
        $this->addSql('CREATE TEMPORARY TABLE __temp__account AS SELECT id, label, is_savings, interest_rate, max_amount, initial_balance, type_id FROM account');
        $this->addSql('DROP TABLE account');
        $this->addSql('CREATE TABLE account (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, label VARCHAR(100) NOT NULL, is_savings BOOLEAN NOT NULL, interest_rate NUMERIC(5, 2) DEFAULT NULL, max_amount NUMERIC(10, 2) DEFAULT NULL, initial_balance NUMERIC(10, 2) DEFAULT NULL, type_id INTEGER NOT NULL, CONSTRAINT FK_7D3656A4C54C8C93 FOREIGN KEY (type_id) REFERENCES account_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO account (id, label, is_savings, interest_rate, max_amount, initial_balance, type_id) SELECT id, label, is_savings, interest_rate, max_amount, initial_balance, type_id FROM __temp__account');
        $this->addSql('DROP TABLE __temp__account');
        $this->addSql('CREATE INDEX IDX_7D3656A4C54C8C93 ON account (type_id)');
    }
}
