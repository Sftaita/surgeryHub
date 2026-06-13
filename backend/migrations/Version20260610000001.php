<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260610000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add index on mission.updated_at (instrumentist mission sync polling)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_MISSION_UPDATED_AT ON mission (updated_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_MISSION_UPDATED_AT ON mission');
    }
}
