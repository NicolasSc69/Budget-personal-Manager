<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add category.excluded_from_forecast to let a tag be left out of the projected balance calculation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category ADD COLUMN excluded_from_forecast BOOLEAN NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__category AS SELECT id, name, color, parent_id FROM category');
        $this->addSql('DROP TABLE category');
        $this->addSql('CREATE TABLE category (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(100) NOT NULL, color VARCHAR(7) NOT NULL, parent_id INTEGER DEFAULT NULL, CONSTRAINT FK_64C19C1727ACA70 FOREIGN KEY (parent_id) REFERENCES category (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO category (id, name, color, parent_id) SELECT id, name, color, parent_id FROM __temp__category');
        $this->addSql('DROP TABLE __temp__category');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_64C19C15E237E06 ON category (name)');
        $this->addSql('CREATE INDEX IDX_64C19C1727ACA70 ON category (parent_id)');
    }
}
