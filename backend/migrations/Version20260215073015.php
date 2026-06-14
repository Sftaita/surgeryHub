<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260215073015 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // No-op: Version20260107053952 already creates `mission.instrumentist_id`
        // (incl. index and FK), so this migration would be a duplicate on a fresh database.
    }

    public function down(Schema $schema): void
    {
        // No-op: see up().
    }
}
