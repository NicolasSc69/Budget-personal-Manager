<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260901000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename transactions.destination_account_id index to match current mapping';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_EAA81A4CB08FA272');
        $this->addSql('CREATE INDEX IDX_EAA81A4CC652C408 ON transactions (destination_account_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_EAA81A4CC652C408');
        $this->addSql('CREATE INDEX IDX_EAA81A4CB08FA272 ON transactions (destination_account_id)');
    }
}
