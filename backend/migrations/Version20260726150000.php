<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-084 (historique centralisé des notifications sortantes) — crée outbound_notification
 * et outbound_notification_attempt. Purement additive : deux nouvelles tables, aucune
 * modification d'un schéma existant.
 */
final class Version20260726150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-084 : crée outbound_notification et outbound_notification_attempt (additif).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE outbound_notification (
                id INT AUTO_INCREMENT NOT NULL,
                recipient_user_id INT NOT NULL,
                channel VARCHAR(255) NOT NULL,
                notification_type VARCHAR(100) NOT NULL,
                status VARCHAR(255) NOT NULL,
                title VARCHAR(255) DEFAULT NULL,
                subject VARCHAR(255) DEFAULT NULL,
                body_text LONGTEXT DEFAULT NULL,
                body_html LONGTEXT DEFAULT NULL,
                payload JSON DEFAULT NULL,
                mission_id INT DEFAULT NULL,
                planning_version_id INT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                queued_at DATETIME DEFAULT NULL,
                sent_at DATETIME DEFAULT NULL,
                failed_at DATETIME DEFAULT NULL,
                failure_code VARCHAR(100) DEFAULT NULL,
                failure_message LONGTEXT DEFAULT NULL,
                provider_message_id VARCHAR(255) DEFAULT NULL,
                attempt_count INT NOT NULL,
                fallback_of_id INT DEFAULT NULL,
                fallback_reason VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql('CREATE INDEX idx_outbound_notification_created_at ON outbound_notification (created_at)');
        $this->addSql('CREATE INDEX idx_outbound_notification_recipient ON outbound_notification (recipient_user_id)');
        $this->addSql('CREATE INDEX idx_outbound_notification_channel ON outbound_notification (channel)');
        $this->addSql('CREATE INDEX idx_outbound_notification_status ON outbound_notification (status)');
        $this->addSql('CREATE INDEX idx_outbound_notification_type ON outbound_notification (notification_type)');
        $this->addSql('CREATE INDEX idx_outbound_notification_mission ON outbound_notification (mission_id)');
        $this->addSql('CREATE INDEX idx_outbound_notification_planning_version ON outbound_notification (planning_version_id)');
        $this->addSql('CREATE INDEX idx_outbound_notification_fallback_of ON outbound_notification (fallback_of_id)');
        $this->addSql('ALTER TABLE outbound_notification ADD CONSTRAINT fk_outbound_notification_recipient FOREIGN KEY (recipient_user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE outbound_notification ADD CONSTRAINT fk_outbound_notification_mission FOREIGN KEY (mission_id) REFERENCES mission (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE outbound_notification ADD CONSTRAINT fk_outbound_notification_planning_version FOREIGN KEY (planning_version_id) REFERENCES planning_version (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE outbound_notification ADD CONSTRAINT fk_outbound_notification_fallback_of FOREIGN KEY (fallback_of_id) REFERENCES outbound_notification (id) ON DELETE SET NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE outbound_notification_attempt (
                id INT AUTO_INCREMENT NOT NULL,
                notification_id INT NOT NULL,
                attempt_number INT NOT NULL,
                started_at DATETIME NOT NULL,
                finished_at DATETIME DEFAULT NULL,
                success TINYINT(1) NOT NULL,
                status_code INT DEFAULT NULL,
                reason VARCHAR(255) DEFAULT NULL,
                provider VARCHAR(50) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql('CREATE INDEX idx_outbound_notification_attempt_notification ON outbound_notification_attempt (notification_id)');
        $this->addSql('ALTER TABLE outbound_notification_attempt ADD CONSTRAINT fk_outbound_notification_attempt_notification FOREIGN KEY (notification_id) REFERENCES outbound_notification (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE outbound_notification_attempt DROP FOREIGN KEY fk_outbound_notification_attempt_notification');
        $this->addSql('DROP TABLE outbound_notification_attempt');

        $this->addSql('ALTER TABLE outbound_notification DROP FOREIGN KEY fk_outbound_notification_recipient');
        $this->addSql('ALTER TABLE outbound_notification DROP FOREIGN KEY fk_outbound_notification_mission');
        $this->addSql('ALTER TABLE outbound_notification DROP FOREIGN KEY fk_outbound_notification_planning_version');
        $this->addSql('ALTER TABLE outbound_notification DROP FOREIGN KEY fk_outbound_notification_fallback_of');
        $this->addSql('DROP TABLE outbound_notification');
    }
}
