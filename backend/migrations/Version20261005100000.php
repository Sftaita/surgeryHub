<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * H4 (D-130) — mission_communication registry + the dispatch-side spec that feeds it.
 *
 * Purely additive:
 *   - mission_communication: one row per (mission, person) communication episode, opened
 *     only on proof of delivery, closed (never deleted) on withdrawal; mission / user /
 *     dispatch FKs SET NULL with snapshots, so the history outlives what it was about;
 *   - notification_dispatch.communicates (JSON, nullable): which missions the notification
 *     presents as the recipient's, once a channel reaches her.
 * No existing row is modified; nothing is backfilled (communications start being recorded
 * from deployment of this lot — see docs/decisions.md D-130).
 */
final class Version20261005100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates mission_communication and adds notification_dispatch.communicates (H4, D-130).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE mission_communication (
                id INT AUTO_INCREMENT NOT NULL,
                mission_id INT DEFAULT NULL,
                mission_id_snapshot INT NOT NULL,
                mission_start_snapshot DATETIME DEFAULT NULL COMMENT '(DC2Type:business_datetime_immutable)',
                user_id INT DEFAULT NULL,
                user_name_snapshot VARCHAR(255) DEFAULT NULL,
                role VARCHAR(16) NOT NULL,
                source VARCHAR(24) NOT NULL,
                communicated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                correlation_id CHAR(36) DEFAULT NULL,
                notification_dispatch_id INT DEFAULT NULL,
                withdrawn_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                withdrawn_reason VARCHAR(24) DEFAULT NULL,
                INDEX idx_mission_communication_mission_user (mission_id_snapshot, user_id),
                INDEX idx_mission_communication_user (user_id, communicated_at),
                INDEX IDX_MISSION_COMMUNICATION_MISSION (mission_id),
                INDEX IDX_MISSION_COMMUNICATION_DISPATCH (notification_dispatch_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE mission_communication ADD CONSTRAINT FK_MISSION_COMMUNICATION_MISSION FOREIGN KEY (mission_id) REFERENCES mission (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mission_communication ADD CONSTRAINT FK_MISSION_COMMUNICATION_USER FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mission_communication ADD CONSTRAINT FK_MISSION_COMMUNICATION_DISPATCH FOREIGN KEY (notification_dispatch_id) REFERENCES notification_dispatch (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE notification_dispatch ADD communicates JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification_dispatch DROP communicates');
        $this->addSql('DROP TABLE mission_communication');
    }
}
