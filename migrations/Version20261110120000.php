<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\ColorGenerator;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add account.color (unique): the color of the account on the charts, a distinct random color is assigned to every existing account';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__account AS SELECT id, label, type_id, is_savings, interest_rate, max_amount, initial_balance, person_id, average_salary FROM account');
        $this->addSql('DROP TABLE account');
        $this->addSql('CREATE TABLE account (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, label VARCHAR(100) NOT NULL, type_id INTEGER NOT NULL, is_savings BOOLEAN NOT NULL, interest_rate NUMERIC(5, 2) DEFAULT NULL, max_amount NUMERIC(10, 2) DEFAULT NULL, initial_balance NUMERIC(10, 2) DEFAULT NULL, person_id INTEGER DEFAULT NULL, average_salary NUMERIC(10, 2) DEFAULT NULL, color VARCHAR(7) NOT NULL, CONSTRAINT FK_7D3656A4C54C8C93 FOREIGN KEY (type_id) REFERENCES account_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7D3656A4217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        // Temporary color derived from the id (hence unique), replaced right below by a readable random color.
        $this->addSql("INSERT INTO account (id, label, type_id, is_savings, interest_rate, max_amount, initial_balance, person_id, average_salary, color) SELECT id, label, type_id, is_savings, interest_rate, max_amount, initial_balance, person_id, average_salary, printf('#%06X', id) FROM __temp__account");
        $this->addSql('DROP TABLE __temp__account');
        $this->addSql('CREATE INDEX IDX_7D3656A4217BBB47 ON account (person_id)');
        $this->addSql('CREATE INDEX IDX_7D3656A4C54C8C93 ON account (type_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7D3656A4665648E9 ON account (color)');

        $accountIds = $this->connection->fetchFirstColumn('SELECT id FROM account ORDER BY label');
        $palette = (new ColorGenerator())->generateSpreadPalette(\count($accountIds));

        foreach ($accountIds as $index => $accountId) {
            $this->addSql('UPDATE account SET color = ? WHERE id = ?', [$palette[$index], (int) $accountId]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__account AS SELECT id, label, type_id, is_savings, interest_rate, max_amount, initial_balance, person_id, average_salary FROM account');
        $this->addSql('DROP TABLE account');
        $this->addSql('CREATE TABLE account (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, label VARCHAR(100) NOT NULL, type_id INTEGER NOT NULL, is_savings BOOLEAN NOT NULL, interest_rate NUMERIC(5, 2) DEFAULT NULL, max_amount NUMERIC(10, 2) DEFAULT NULL, initial_balance NUMERIC(10, 2) DEFAULT NULL, person_id INTEGER DEFAULT NULL, average_salary NUMERIC(10, 2) DEFAULT NULL, CONSTRAINT FK_7D3656A4C54C8C93 FOREIGN KEY (type_id) REFERENCES account_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7D3656A4217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO account (id, label, type_id, is_savings, interest_rate, max_amount, initial_balance, person_id, average_salary) SELECT id, label, type_id, is_savings, interest_rate, max_amount, initial_balance, person_id, average_salary FROM __temp__account');
        $this->addSql('DROP TABLE __temp__account');
        $this->addSql('CREATE INDEX IDX_7D3656A4217BBB47 ON account (person_id)');
        $this->addSql('CREATE INDEX IDX_7D3656A4C54C8C93 ON account (type_id)');
    }
}
