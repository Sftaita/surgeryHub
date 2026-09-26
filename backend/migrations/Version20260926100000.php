<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-125 — a TARGETED MissionPublication (a manager's nominative request to one
 * instrumentist) can now be explicitly declined by its target. Purely additive: one
 * nullable column, NULL for every existing publication (= still active, exactly as before).
 */
final class Version20260926100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds mission_publication.declined_at — a TARGETED offer declined by its instrumentist (D-125).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission_publication ADD declined_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission_publication DROP declined_at');
    }
}
