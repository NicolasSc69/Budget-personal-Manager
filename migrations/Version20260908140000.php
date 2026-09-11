<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add app_setting.currency to let the ISO 4217 currency displayed across the app be configured';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE app_setting ADD COLUMN currency VARCHAR(3) NOT NULL DEFAULT 'EUR'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__app_setting AS SELECT id, items_per_page, backup_directory, locale FROM app_setting');
        $this->addSql('DROP TABLE app_setting');
        $this->addSql("CREATE TABLE app_setting (id INTEGER NOT NULL, items_per_page INTEGER NOT NULL, backup_directory VARCHAR(255) DEFAULT NULL, locale VARCHAR(10) DEFAULT 'en' NOT NULL, PRIMARY KEY (id))");
        $this->addSql('INSERT INTO app_setting (id, items_per_page, backup_directory, locale) SELECT id, items_per_page, backup_directory, locale FROM __temp__app_setting');
        $this->addSql('DROP TABLE __temp__app_setting');
    }
}
