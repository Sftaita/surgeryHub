<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Communication des absences chirurgiens — Lot A (D-114). Trois nouvelles tables, purement
 * additives : réglage par site (AbsenceCommunicationSiteConfig), communication logique
 * (SurgeonAbsenceCommunication — snapshot immuable "quoi/pour qui/quelles occurrences") et
 * livraison par destinataire (SurgeonAbsenceCommunicationDelivery — statut d'envoi réel,
 * une ligne par destinataire, jamais un statut agrégé). Aucune table/colonne existante
 * modifiée.
 */
final class Version20260905090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Communication des absences chirurgiens (Lot A/D-114) — absence_communication_site_config, surgeon_absence_communication, surgeon_absence_communication_delivery.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE absence_communication_site_config (
            id INT AUTO_INCREMENT NOT NULL,
            site_id INT NOT NULL,
            notify_colleagues_enabled TINYINT(1) NOT NULL,
            notify_block_management_enabled TINYINT(1) NOT NULL,
            block_management_email_to VARCHAR(255) DEFAULT NULL,
            block_management_email_cc JSON NOT NULL,
            block_management_delay_days INT DEFAULT NULL,
            UNIQUE INDEX uniq_absence_comm_site_config_site (site_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE surgeon_absence_communication (
            id INT AUTO_INCREMENT NOT NULL,
            absence_id INT DEFAULT NULL,
            surgeon_id INT NOT NULL,
            site_id INT NOT NULL,
            type VARCHAR(40) NOT NULL,
            revision_number INT NOT NULL,
            subject_snapshot VARCHAR(255) NOT NULL,
            body_snapshot LONGTEXT NOT NULL,
            occurrences_snapshot JSON NOT NULL,
            absence_date_start_snapshot DATE NOT NULL,
            absence_date_end_snapshot DATE NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_absence_comm_surgeon (surgeon_id),
            INDEX idx_absence_comm_site (site_id),
            INDEX idx_absence_comm_absence (absence_id),
            UNIQUE INDEX uniq_absence_site_type_revision (absence_id, site_id, type, revision_number),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE surgeon_absence_communication_delivery (
            id INT AUTO_INCREMENT NOT NULL,
            communication_id INT NOT NULL,
            recipient_id INT DEFAULT NULL,
            recipient_email_snapshot VARCHAR(255) NOT NULL,
            recipient_cc_snapshot JSON NOT NULL,
            status VARCHAR(20) NOT NULL,
            scheduled_at DATETIME DEFAULT NULL,
            sent_at DATETIME DEFAULT NULL,
            cancelled_at DATETIME DEFAULT NULL,
            attempt_count INT NOT NULL,
            last_error LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_absence_comm_delivery_communication (communication_id),
            INDEX idx_absence_comm_delivery_recipient (recipient_id),
            INDEX idx_absence_comm_delivery_status (status),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE absence_communication_site_config ADD CONSTRAINT fk_absence_comm_site_config_site FOREIGN KEY (site_id) REFERENCES hospital (id)');

        $this->addSql('ALTER TABLE surgeon_absence_communication ADD CONSTRAINT fk_absence_comm_absence FOREIGN KEY (absence_id) REFERENCES absence (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE surgeon_absence_communication ADD CONSTRAINT fk_absence_comm_surgeon FOREIGN KEY (surgeon_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE surgeon_absence_communication ADD CONSTRAINT fk_absence_comm_site FOREIGN KEY (site_id) REFERENCES hospital (id)');

        $this->addSql('ALTER TABLE surgeon_absence_communication_delivery ADD CONSTRAINT fk_absence_comm_delivery_communication FOREIGN KEY (communication_id) REFERENCES surgeon_absence_communication (id)');
        $this->addSql('ALTER TABLE surgeon_absence_communication_delivery ADD CONSTRAINT fk_absence_comm_delivery_recipient FOREIGN KEY (recipient_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE surgeon_absence_communication_delivery DROP FOREIGN KEY fk_absence_comm_delivery_communication');
        $this->addSql('ALTER TABLE surgeon_absence_communication_delivery DROP FOREIGN KEY fk_absence_comm_delivery_recipient');
        $this->addSql('ALTER TABLE surgeon_absence_communication DROP FOREIGN KEY fk_absence_comm_absence');
        $this->addSql('ALTER TABLE surgeon_absence_communication DROP FOREIGN KEY fk_absence_comm_surgeon');
        $this->addSql('ALTER TABLE surgeon_absence_communication DROP FOREIGN KEY fk_absence_comm_site');
        $this->addSql('ALTER TABLE absence_communication_site_config DROP FOREIGN KEY fk_absence_comm_site_config_site');

        $this->addSql('DROP TABLE surgeon_absence_communication_delivery');
        $this->addSql('DROP TABLE surgeon_absence_communication');
        $this->addSql('DROP TABLE absence_communication_site_config');
    }
}
