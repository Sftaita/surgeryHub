<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A manager's explicit, per-pair authorization to deploy despite a CROSS_SITE_CONFLICT
 * (D-091) — see MissionConflictWaiver's own docblock for the full design. Purely additive:
 * one new table, no change to any existing schema.
 */
final class Version20260911170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds mission_conflict_waiver — manager-authorized, per-pair override of a CROSS_SITE_CONFLICT at deploy time.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE mission_conflict_waiver (
                id INT AUTO_INCREMENT NOT NULL,
                mission_low_id INT NOT NULL,
                mission_high_id INT NOT NULL,
                site_id INT NOT NULL,
                surgeon_id INT NOT NULL,
                instrumentist_id INT NOT NULL,
                mission_low_start_at DATETIME NOT NULL,
                mission_low_end_at DATETIME NOT NULL,
                mission_high_start_at DATETIME NOT NULL,
                mission_high_end_at DATETIME NOT NULL,
                authorized_by_id INT NOT NULL,
                authorized_at DATETIME NOT NULL,
                reason VARCHAR(500) DEFAULT NULL,
                invalidated_at DATETIME DEFAULT NULL,
                invalidated_reason VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql('CREATE INDEX idx_mission_conflict_waiver_pair ON mission_conflict_waiver (mission_low_id, mission_high_id)');
        $this->addSql('ALTER TABLE mission_conflict_waiver ADD CONSTRAINT FK_MCW_MISSION_LOW FOREIGN KEY (mission_low_id) REFERENCES mission (id)');
        $this->addSql('ALTER TABLE mission_conflict_waiver ADD CONSTRAINT FK_MCW_MISSION_HIGH FOREIGN KEY (mission_high_id) REFERENCES mission (id)');
        $this->addSql('ALTER TABLE mission_conflict_waiver ADD CONSTRAINT FK_MCW_AUTHORIZED_BY FOREIGN KEY (authorized_by_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mission_conflict_waiver');
    }
}
