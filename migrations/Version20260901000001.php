<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260901000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Realign app_setting.locale column definition with the entity mapping';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__app_setting AS SELECT id, items_per_page, backup_directory, locale FROM app_setting');
        $this->addSql('DROP TABLE app_setting');
        $this->addSql("CREATE TABLE app_setting (id INTEGER NOT NULL, items_per_page INTEGER NOT NULL, backup_directory VARCHAR(255) DEFAULT NULL, locale VARCHAR(10) DEFAULT 'en' NOT NULL, PRIMARY KEY (id))");
        $this->addSql('INSERT INTO app_setting (id, items_per_page, backup_directory, locale) SELECT id, items_per_page, backup_directory, locale FROM __temp__app_setting');
        $this->addSql('DROP TABLE __temp__app_setting');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__app_setting AS SELECT id, items_per_page, backup_directory, locale FROM app_setting');
        $this->addSql('DROP TABLE app_setting');
        $this->addSql('CREATE TABLE app_setting (id INTEGER NOT NULL, items_per_page INTEGER NOT NULL, backup_directory VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql("ALTER TABLE app_setting ADD COLUMN locale VARCHAR(10) NOT NULL DEFAULT 'en'");
        $this->addSql('INSERT INTO app_setting (id, items_per_page, backup_directory, locale) SELECT id, items_per_page, backup_directory, locale FROM __temp__app_setting');
        $this->addSql('DROP TABLE __temp__app_setting');
    }
}
