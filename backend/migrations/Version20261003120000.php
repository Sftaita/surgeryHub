<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * H1 (D-126) — transversal append-only activity journal.
 *
 * Purely additive: one new table, no change to any existing table or row. Deliberately
 * NO foreign key: every business reference (actor, subject, site, version, mission,
 * absence, user, causation) is a plain integer so that deleting a DRAFT, an absence or a
 * user can never cascade into — or be blocked by — the history.
 *
 * Append-only is enforced by the application (DBAL middleware + ORM guard), not by DB
 * triggers: production MySQL has binary logging on and the app user lacks SUPER, so
 * CREATE TRIGGER would fail there (error 1419). See docs/decisions.md D-126.
 */
final class Version20261003120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates activity_event — transversal append-only activity journal (H1, D-126).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE activity_event (
                id INT AUTO_INCREMENT NOT NULL,
                event_type VARCHAR(64) NOT NULL,
                domain VARCHAR(32) NOT NULL,
                occurred_at DATETIME(6) NOT NULL,
                actor_type VARCHAR(16) NOT NULL,
                actor_user_id INT DEFAULT NULL,
                actor_name_snapshot VARCHAR(255) DEFAULT NULL,
                actor_role_snapshot VARCHAR(32) DEFAULT NULL,
                subject_type VARCHAR(48) NOT NULL,
                subject_id INT DEFAULT NULL,
                subject_label_snapshot VARCHAR(255) DEFAULT NULL,
                correlation_id CHAR(36) NOT NULL,
                causation_event_id INT DEFAULT NULL,
                site_id INT DEFAULT NULL,
                planning_version_id INT DEFAULT NULL,
                mission_id INT DEFAULT NULL,
                absence_id INT DEFAULT NULL,
                user_id INT DEFAULT NULL,
                outcome VARCHAR(16) NOT NULL,
                summary VARCHAR(500) NOT NULL,
                payload JSON DEFAULT NULL,
                source_ref VARCHAR(64) DEFAULT NULL,
                idempotency_key VARCHAR(191) DEFAULT NULL,
                request_id VARCHAR(64) DEFAULT NULL,
                messenger_message_id VARCHAR(64) DEFAULT NULL,
                UNIQUE INDEX uniq_activity_idempotency (idempotency_key),
                INDEX idx_activity_occurred (occurred_at, id),
                INDEX idx_activity_correlation (correlation_id),
                INDEX idx_activity_mission (mission_id, occurred_at),
                INDEX idx_activity_user (user_id, occurred_at),
                INDEX idx_activity_absence (absence_id, occurred_at),
                INDEX idx_activity_planning_version (planning_version_id, occurred_at),
                INDEX idx_activity_domain (domain, occurred_at),
                INDEX idx_activity_subject (subject_type, subject_id),
                INDEX idx_activity_actor (actor_user_id, occurred_at),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE activity_event');
    }
}
