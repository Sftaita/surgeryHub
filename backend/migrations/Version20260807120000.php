<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 5 (D-099) — SurgeonMissionRequest : intention chirurgien de mission, jamais une
 * Mission elle-même. createdMission (FK nullable vers mission) n'est posé que lorsque
 * status=ACCEPTED (voir SurgeonMissionRequestService::accept(), atomique).
 */
final class Version20260807120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée surgeon_mission_request (Lot 5, D-099) : demande chirurgien de mission, revue manager, lien vers la Mission créée.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE surgeon_mission_request (
            id INT AUTO_INCREMENT NOT NULL,
            surgeon_id INT NOT NULL,
            site_id INT NOT NULL,
            reviewed_by_id INT DEFAULT NULL,
            created_mission_id INT DEFAULT NULL,
            type VARCHAR(255) NOT NULL,
            start_at DATETIME NOT NULL COMMENT '(DC2Type:business_datetime_immutable)',
            end_at DATETIME NOT NULL COMMENT '(DC2Type:business_datetime_immutable)',
            comment LONGTEXT DEFAULT NULL,
            status VARCHAR(20) DEFAULT 'PENDING' NOT NULL,
            reviewed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            review_comment LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX idx_surgeon_mission_request_surgeon (surgeon_id),
            INDEX idx_surgeon_mission_request_status (status),
            INDEX IDX_SURGEON_MISSION_REQUEST_SITE (site_id),
            INDEX IDX_SURGEON_MISSION_REQUEST_REVIEWED_BY (reviewed_by_id),
            UNIQUE INDEX UNIQ_SURGEON_MISSION_REQUEST_CREATED_MISSION (created_mission_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql('ALTER TABLE surgeon_mission_request ADD CONSTRAINT FK_SURGEON_MISSION_REQUEST_SURGEON FOREIGN KEY (surgeon_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE surgeon_mission_request ADD CONSTRAINT FK_SURGEON_MISSION_REQUEST_SITE FOREIGN KEY (site_id) REFERENCES hospital (id)');
        $this->addSql('ALTER TABLE surgeon_mission_request ADD CONSTRAINT FK_SURGEON_MISSION_REQUEST_REVIEWED_BY FOREIGN KEY (reviewed_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE surgeon_mission_request ADD CONSTRAINT FK_SURGEON_MISSION_REQUEST_CREATED_MISSION FOREIGN KEY (created_mission_id) REFERENCES mission (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE surgeon_mission_request DROP FOREIGN KEY FK_SURGEON_MISSION_REQUEST_SURGEON');
        $this->addSql('ALTER TABLE surgeon_mission_request DROP FOREIGN KEY FK_SURGEON_MISSION_REQUEST_SITE');
        $this->addSql('ALTER TABLE surgeon_mission_request DROP FOREIGN KEY FK_SURGEON_MISSION_REQUEST_REVIEWED_BY');
        $this->addSql('ALTER TABLE surgeon_mission_request DROP FOREIGN KEY FK_SURGEON_MISSION_REQUEST_CREATED_MISSION');
        $this->addSql('DROP TABLE surgeon_mission_request');
    }
}
