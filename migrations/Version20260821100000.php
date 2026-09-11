<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add backup_directory column to app_setting';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_setting ADD COLUMN backup_directory VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__app_setting AS SELECT id, items_per_page FROM app_setting');
        $this->addSql('DROP TABLE app_setting');
        $this->addSql('CREATE TABLE app_setting (id INTEGER NOT NULL, items_per_page INTEGER NOT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO app_setting (id, items_per_page) SELECT id, items_per_page FROM __temp__app_setting');
        $this->addSql('DROP TABLE __temp__app_setting');
    }
}
