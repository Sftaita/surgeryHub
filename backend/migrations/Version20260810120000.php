<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 3 (D-103) — planning_occurrence_exception gains a provenance: MANAGER (default,
 * every pre-existing row) vs SURGEON_ABSENCE (automatic neutralization of a future Post
 * occurrence, before any Mission was ever generated for it), plus the optional Absence
 * that caused it. ON DELETE SET NULL on source_absence_id — same convention as
 * planning_alert.absence_id (Version20260621100004): a hard-deleted Absence must never
 * block or erase the dependent row's history.
 */
final class Version20260810120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 3 (D-103) : planning_occurrence_exception.source + source_absence_id (provenance manager vs absence chirurgien automatique).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE planning_occurrence_exception
            ADD source VARCHAR(16) DEFAULT 'MANAGER' NOT NULL,
            ADD source_absence_id INT DEFAULT NULL");
        $this->addSql('CREATE INDEX idx_planning_occurrence_exception_source_absence ON planning_occurrence_exception (source_absence_id)');
        $this->addSql('ALTER TABLE planning_occurrence_exception ADD CONSTRAINT FK_POE_SOURCE_ABSENCE FOREIGN KEY (source_absence_id) REFERENCES absence (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planning_occurrence_exception DROP FOREIGN KEY FK_POE_SOURCE_ABSENCE');
        $this->addSql('DROP INDEX idx_planning_occurrence_exception_source_absence ON planning_occurrence_exception');
        $this->addSql('ALTER TABLE planning_occurrence_exception DROP source, DROP source_absence_id');
    }
}
