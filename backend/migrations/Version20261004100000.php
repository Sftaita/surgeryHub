<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * H2 (D-128) — notification decision + per-channel delivery traceability.
 *
 * Purely additive:
 *   - notification_dispatch (the decision: recipient, type, subject, episode, resolved
 *     channels, UNIQUE idempotency_key) — recipient/mission FKs SET NULL on delete;
 *   - notification_dispatch_delivery (N:N dispatch ↔ outbound_notification, so one grouped
 *     email can serve several dispatches of a batch);
 *   - outbound_notification: skip_reason, correlation_id, group_key (UNIQUE, NULL for
 *     non-grouped rows), notification_event_id (IN_APP rows → their feed item, SET NULL).
 * The new IN_APP channel value needs no DDL (VARCHAR column backed by a PHP enum).
 * No existing row is modified.
 */
final class Version20261004100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates notification_dispatch (+ delivery link) and extends outbound_notification (H2, D-128).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE notification_dispatch (
                id INT AUTO_INCREMENT NOT NULL,
                recipient_user_id INT DEFAULT NULL,
                recipient_name_snapshot VARCHAR(255) DEFAULT NULL,
                notification_type VARCHAR(100) NOT NULL,
                mission_id INT DEFAULT NULL,
                subject_type VARCHAR(48) DEFAULT NULL,
                subject_id INT DEFAULT NULL,
                episode_ref VARCHAR(150) DEFAULT NULL,
                correlation_id CHAR(36) NOT NULL,
                mode VARCHAR(16) NOT NULL,
                channels_resolved JSON NOT NULL,
                payload JSON DEFAULT NULL,
                idempotency_key VARCHAR(191) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_notification_dispatch_idempotency (idempotency_key),
                INDEX idx_notification_dispatch_recipient (recipient_user_id, created_at),
                INDEX idx_notification_dispatch_mission (mission_id),
                INDEX idx_notification_dispatch_correlation (correlation_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE notification_dispatch ADD CONSTRAINT FK_NOTIF_DISPATCH_RECIPIENT FOREIGN KEY (recipient_user_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE notification_dispatch ADD CONSTRAINT FK_NOTIF_DISPATCH_MISSION FOREIGN KEY (mission_id) REFERENCES mission (id) ON DELETE SET NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE notification_dispatch_delivery (
                dispatch_id INT NOT NULL,
                outbound_notification_id INT NOT NULL,
                INDEX idx_notif_dispatch_delivery_outbound (outbound_notification_id),
                PRIMARY KEY (dispatch_id, outbound_notification_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE notification_dispatch_delivery ADD CONSTRAINT FK_NOTIF_DELIVERY_DISPATCH FOREIGN KEY (dispatch_id) REFERENCES notification_dispatch (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification_dispatch_delivery ADD CONSTRAINT FK_NOTIF_DELIVERY_OUTBOUND FOREIGN KEY (outbound_notification_id) REFERENCES outbound_notification (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE outbound_notification ADD skip_reason VARCHAR(32) DEFAULT NULL, ADD correlation_id CHAR(36) DEFAULT NULL, ADD group_key VARCHAR(191) DEFAULT NULL, ADD notification_event_id INT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_outbound_notification_group_key ON outbound_notification (group_key)');
        $this->addSql('CREATE INDEX idx_outbound_notification_correlation ON outbound_notification (correlation_id)');
        $this->addSql('CREATE INDEX IDX_OUTBOUND_NOTIFICATION_EVENT ON outbound_notification (notification_event_id)');
        $this->addSql('ALTER TABLE outbound_notification ADD CONSTRAINT FK_OUTBOUND_NOTIFICATION_EVENT FOREIGN KEY (notification_event_id) REFERENCES notification_event (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE outbound_notification DROP FOREIGN KEY FK_OUTBOUND_NOTIFICATION_EVENT');
        $this->addSql('DROP INDEX IDX_OUTBOUND_NOTIFICATION_EVENT ON outbound_notification');
        $this->addSql('DROP INDEX idx_outbound_notification_correlation ON outbound_notification');
        $this->addSql('DROP INDEX uniq_outbound_notification_group_key ON outbound_notification');
        $this->addSql('ALTER TABLE outbound_notification DROP skip_reason, DROP correlation_id, DROP group_key, DROP notification_event_id');
        $this->addSql('DROP TABLE notification_dispatch_delivery');
        $this->addSql('DROP TABLE notification_dispatch');
    }
}
