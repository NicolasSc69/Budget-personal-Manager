<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260820143138 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE account_type (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(100) NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4DD0835E237E06 ON account_type (name)');
        $this->addSql("INSERT INTO account_type (id, name) VALUES (1, 'Compte courant'), (2, 'LDD'), (3, 'Livret A'), (4, 'Assurance vie')");

        $this->addSql('CREATE TABLE account (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, label VARCHAR(100) NOT NULL, type_id INTEGER NOT NULL, CONSTRAINT FK_7D3656A4C54C8C93 FOREIGN KEY (type_id) REFERENCES account_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_7D3656A4C54C8C93 ON account (type_id)');
        $this->addSql("INSERT INTO account (id, label, type_id) VALUES (1, 'Compte principal', 1)");

        $this->addSql('CREATE TEMPORARY TABLE __temp__transactions AS SELECT id, amount, date, category_id, recurrence, recurrence_parent_id FROM transactions');
        $this->addSql('DROP TABLE transactions');
        $this->addSql('CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, amount NUMERIC(10, 2) NOT NULL, date DATE NOT NULL, category_id INTEGER NOT NULL, recurrence VARCHAR(20) NOT NULL, recurrence_parent_id INTEGER DEFAULT NULL, account_id INTEGER NOT NULL, CONSTRAINT FK_EAA81A4C12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_EAA81A4C6ADCDB06 FOREIGN KEY (recurrence_parent_id) REFERENCES transactions (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_EAA81A4C9B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO transactions (id, amount, date, category_id, recurrence, recurrence_parent_id, account_id) SELECT id, amount, date, category_id, recurrence, recurrence_parent_id, 1 FROM __temp__transactions');
        $this->addSql('DROP TABLE __temp__transactions');
        $this->addSql('CREATE INDEX IDX_EAA81A4C6ADCDB06 ON transactions (recurrence_parent_id)');
        $this->addSql('CREATE INDEX IDX_EAA81A4C12469DE2 ON transactions (category_id)');
        $this->addSql('CREATE INDEX IDX_EAA81A4C9B6B5FBA ON transactions (account_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE account');
        $this->addSql('DROP TABLE account_type');
        $this->addSql('CREATE TEMPORARY TABLE __temp__transactions AS SELECT id, amount, date, recurrence, category_id, recurrence_parent_id FROM transactions');
        $this->addSql('DROP TABLE transactions');
        $this->addSql('CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, amount NUMERIC(10, 2) NOT NULL, date DATE NOT NULL, recurrence VARCHAR(20) NOT NULL, category_id INTEGER NOT NULL, recurrence_parent_id INTEGER DEFAULT NULL, CONSTRAINT FK_EAA81A4C12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_EAA81A4C6ADCDB06 FOREIGN KEY (recurrence_parent_id) REFERENCES transactions (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO transactions (id, amount, date, recurrence, category_id, recurrence_parent_id) SELECT id, amount, date, recurrence, category_id, recurrence_parent_id FROM __temp__transactions');
        $this->addSql('DROP TABLE __temp__transactions');
        $this->addSql('CREATE INDEX IDX_EAA81A4C12469DE2 ON transactions (category_id)');
        $this->addSql('CREATE INDEX IDX_EAA81A4C6ADCDB06 ON transactions (recurrence_parent_id)');
    }
}
