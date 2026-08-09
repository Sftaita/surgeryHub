<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 6 (D-100) — EncodingAnomalyReport : signalement chirurgien d'une anomalie sur
 * l'encodage de sa Mission. Domaine dédié, jamais un détournement de MaterialItemRequest.
 */
final class Version20260809090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée encoding_anomaly_report (Lot 6, D-100) : signalement chirurgien sur l\'encodage, résolution manager.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE encoding_anomaly_report (
            id INT AUTO_INCREMENT NOT NULL,
            mission_id INT NOT NULL,
            reporter_id INT NOT NULL,
            resolved_by_id INT DEFAULT NULL,
            type VARCHAR(30) NOT NULL,
            comment LONGTEXT NOT NULL,
            status VARCHAR(20) DEFAULT 'OPEN' NOT NULL,
            resolved_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            resolution_comment LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX idx_encoding_anomaly_report_mission (mission_id),
            INDEX idx_encoding_anomaly_report_status (status),
            INDEX IDX_ENCODING_ANOMALY_REPORT_REPORTER (reporter_id),
            INDEX IDX_ENCODING_ANOMALY_REPORT_RESOLVED_BY (resolved_by_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql('ALTER TABLE encoding_anomaly_report ADD CONSTRAINT FK_ENCODING_ANOMALY_REPORT_MISSION FOREIGN KEY (mission_id) REFERENCES mission (id)');
        $this->addSql('ALTER TABLE encoding_anomaly_report ADD CONSTRAINT FK_ENCODING_ANOMALY_REPORT_REPORTER FOREIGN KEY (reporter_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE encoding_anomaly_report ADD CONSTRAINT FK_ENCODING_ANOMALY_REPORT_RESOLVED_BY FOREIGN KEY (resolved_by_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE encoding_anomaly_report DROP FOREIGN KEY FK_ENCODING_ANOMALY_REPORT_MISSION');
        $this->addSql('ALTER TABLE encoding_anomaly_report DROP FOREIGN KEY FK_ENCODING_ANOMALY_REPORT_REPORTER');
        $this->addSql('ALTER TABLE encoding_anomaly_report DROP FOREIGN KEY FK_ENCODING_ANOMALY_REPORT_RESOLVED_BY');
        $this->addSql('DROP TABLE encoding_anomaly_report');
    }
}
