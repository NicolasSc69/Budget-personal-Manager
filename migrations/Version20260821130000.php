<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add destination_account_id column to transactions, used for account-to-account transfers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__transactions AS SELECT id, title, amount, date, recurrence, is_cheque, cheque_number, category_id, account_id, recurrence_parent_id FROM transactions');
        $this->addSql('DROP TABLE transactions');
        $this->addSql('CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) DEFAULT NULL, amount NUMERIC(10, 2) NOT NULL, date DATE NOT NULL, recurrence VARCHAR(20) NOT NULL, is_cheque BOOLEAN NOT NULL DEFAULT 0, cheque_number VARCHAR(50) DEFAULT NULL, category_id INTEGER NOT NULL, account_id INTEGER NOT NULL, recurrence_parent_id INTEGER DEFAULT NULL, destination_account_id INTEGER DEFAULT NULL, CONSTRAINT FK_EAA81A4C12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_EAA81A4C9B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_EAA81A4C6ADCDB06 FOREIGN KEY (recurrence_parent_id) REFERENCES transactions (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_EAA81A4CB08FA272 FOREIGN KEY (destination_account_id) REFERENCES account (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO transactions (id, title, amount, date, recurrence, is_cheque, cheque_number, category_id, account_id, recurrence_parent_id) SELECT id, title, amount, date, recurrence, is_cheque, cheque_number, category_id, account_id, recurrence_parent_id FROM __temp__transactions');
        $this->addSql('DROP TABLE __temp__transactions');
        $this->addSql('CREATE INDEX IDX_EAA81A4C6ADCDB06 ON transactions (recurrence_parent_id)');
        $this->addSql('CREATE INDEX IDX_EAA81A4C12469DE2 ON transactions (category_id)');
        $this->addSql('CREATE INDEX IDX_EAA81A4C9B6B5FBA ON transactions (account_id)');
        $this->addSql('CREATE INDEX IDX_EAA81A4CB08FA272 ON transactions (destination_account_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__transactions AS SELECT id, title, amount, date, recurrence, is_cheque, cheque_number, category_id, account_id, recurrence_parent_id FROM transactions');
        $this->addSql('DROP TABLE transactions');
        $this->addSql('CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) DEFAULT NULL, amount NUMERIC(10, 2) NOT NULL, date DATE NOT NULL, recurrence VARCHAR(20) NOT NULL, is_cheque BOOLEAN NOT NULL DEFAULT 0, cheque_number VARCHAR(50) DEFAULT NULL, category_id INTEGER NOT NULL, account_id INTEGER NOT NULL, recurrence_parent_id INTEGER DEFAULT NULL, CONSTRAINT FK_EAA81A4C12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_EAA81A4C9B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_EAA81A4C6ADCDB06 FOREIGN KEY (recurrence_parent_id) REFERENCES transactions (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO transactions (id, title, amount, date, recurrence, is_cheque, cheque_number, category_id, account_id, recurrence_parent_id) SELECT id, title, amount, date, recurrence, is_cheque, cheque_number, category_id, account_id, recurrence_parent_id FROM __temp__transactions');
        $this->addSql('DROP TABLE __temp__transactions');
        $this->addSql('CREATE INDEX IDX_EAA81A4C6ADCDB06 ON transactions (recurrence_parent_id)');
        $this->addSql('CREATE INDEX IDX_EAA81A4C12469DE2 ON transactions (category_id)');
        $this->addSql('CREATE INDEX IDX_EAA81A4C9B6B5FBA ON transactions (account_id)');
    }
}
