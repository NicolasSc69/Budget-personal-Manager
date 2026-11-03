<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add app_setting.recurring_check_day: day of the month from which recurring transactions are checked for duplication';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_setting ADD COLUMN recurring_check_day INTEGER DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_setting DROP COLUMN recurring_check_day');
    }
}
