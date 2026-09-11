<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add app_setting.theme to let the light/dark/auto theme be configured server-side';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE app_setting ADD COLUMN theme VARCHAR(10) NOT NULL DEFAULT 'auto'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__app_setting AS SELECT id, items_per_page, backup_directory, locale, currency FROM app_setting');
        $this->addSql('DROP TABLE app_setting');
        $this->addSql("CREATE TABLE app_setting (id INTEGER NOT NULL, items_per_page INTEGER NOT NULL, backup_directory VARCHAR(255) DEFAULT NULL, locale VARCHAR(10) DEFAULT 'en' NOT NULL, currency VARCHAR(3) DEFAULT 'EUR' NOT NULL, PRIMARY KEY (id))");
        $this->addSql('INSERT INTO app_setting (id, items_per_page, backup_directory, locale, currency) SELECT id, items_per_page, backup_directory, locale, currency FROM __temp__app_setting');
        $this->addSql('DROP TABLE __temp__app_setting');
    }
}
