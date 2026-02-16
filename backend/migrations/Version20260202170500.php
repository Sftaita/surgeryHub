<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Final schema alignment (Doctrine index naming only)
 */
final class Version20260202170500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align Doctrine index naming on firm.name';
    }

    public function up(Schema $schema): void
    {
        // Rename unique index on firm.name to Doctrine convention
        $this->addSql(
            'ALTER TABLE firm 
             RENAME INDEX uniq_firm_name TO UNIQ_560581FD5E237E06'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE firm 
             RENAME INDEX UNIQ_560581FD5E237E06 TO uniq_firm_name'
        );
    }
}
