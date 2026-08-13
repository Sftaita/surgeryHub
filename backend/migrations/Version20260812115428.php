<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-110 (J-14) — mission.uncovered_escalation_sent_at: NULL means "no escalation sent for
 * the CURRENT OPEN episode" (never OPEN, or not OPEN right now). Reset to NULL by
 * MissionPostDeployService every time a Mission truly leaves OPEN (to ASSIGNED or
 * CANCELLED), so a later episode of non-coverage can trigger a fresh escalation instead of
 * being silently suppressed by a marker from a previous, already-resolved episode.
 */
final class Version20260812115428 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-110 (J-14) : mission.uncovered_escalation_sent_at (marqueur d\'escalade par épisode OPEN).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission ADD uncovered_escalation_sent_at DATETIME(6) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission DROP uncovered_escalation_sent_at');
    }
}
