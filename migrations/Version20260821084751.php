<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260821084751 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__category AS SELECT id, name, color FROM category');
        $this->addSql('DROP TABLE category');
        $this->addSql('CREATE TABLE category (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(100) NOT NULL, color VARCHAR(7) NOT NULL, parent_id INTEGER DEFAULT NULL, CONSTRAINT FK_64C19C1727ACA70 FOREIGN KEY (parent_id) REFERENCES category (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO category (id, name, color) SELECT id, name, color FROM __temp__category');
        $this->addSql('DROP TABLE __temp__category');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_64C19C15E237E06 ON category (name)');
        $this->addSql('CREATE INDEX IDX_64C19C1727ACA70 ON category (parent_id)');
        $this->addSql('ALTER TABLE transactions ADD COLUMN is_cheque BOOLEAN NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE transactions ADD COLUMN cheque_number VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__category AS SELECT id, name, color FROM category');
        $this->addSql('DROP TABLE category');
        $this->addSql('CREATE TABLE category (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(100) NOT NULL, color VARCHAR(7) NOT NULL)');
        $this->addSql('INSERT INTO category (id, name, color) SELECT id, name, color FROM __temp__category');
        $this->addSql('DROP TABLE __temp__category');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_64C19C15E237E06 ON category (name)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__transactions AS SELECT id, title, amount, date, recurrence, category_id, account_id, recurrence_parent_id FROM transactions');
        $this->addSql('DROP TABLE transactions');
        $this->addSql('CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) DEFAULT NULL, amount NUMERIC(10, 2) NOT NULL, date DATE NOT NULL, recurrence VARCHAR(20) NOT NULL, category_id INTEGER NOT NULL, account_id INTEGER NOT NULL, recurrence_parent_id INTEGER DEFAULT NULL, CONSTRAINT FK_EAA81A4C12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_EAA81A4C9B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_EAA81A4C6ADCDB06 FOREIGN KEY (recurrence_parent_id) REFERENCES transactions (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO transactions (id, title, amount, date, recurrence, category_id, account_id, recurrence_parent_id) SELECT id, title, amount, date, recurrence, category_id, account_id, recurrence_parent_id FROM __temp__transactions');
        $this->addSql('DROP TABLE __temp__transactions');
        $this->addSql('CREATE INDEX IDX_EAA81A4C12469DE2 ON transactions (category_id)');
        $this->addSql('CREATE INDEX IDX_EAA81A4C9B6B5FBA ON transactions (account_id)');
        $this->addSql('CREATE INDEX IDX_EAA81A4C6ADCDB06 ON transactions (recurrence_parent_id)');
    }
}
