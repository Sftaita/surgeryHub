<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Salles libérées » (Lot D, post D-114) — nouvelle table `released_operating_room_slot`.
 * Écrite à la main (comme Version20260906100000) plutôt que via `doctrine:migrations:diff` :
 * l'introspection automatique du schéma existant échoue sur ce projet (type DBAL non
 * enregistré rencontré lors du diff), pratique déjà en place avant ce lot.
 */
final class Version20260906140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée released_operating_room_slot (Lot D — Salles libérées).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE released_operating_room_slot (
                id INT AUTO_INCREMENT NOT NULL,
                site_id INT DEFAULT NULL,
                post_id INT NOT NULL,
                schedule_post_id INT DEFAULT NULL,
                occurrence_date DATE NOT NULL,
                period VARCHAR(12) NOT NULL,
                start_time TIME DEFAULT NULL,
                end_time TIME DEFAULT NULL,
                surgeon_id INT DEFAULT NULL,
                source_absence_id INT DEFAULT NULL,
                status VARCHAR(20) NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_slot_site_post_occurrence (site_id, post_id, occurrence_date),
                INDEX idx_slot_surgeon (surgeon_id),
                INDEX idx_slot_site_status_date (site_id, status, occurrence_date),
                INDEX IDX_RELEASED_SLOT_SCHEDULE_POST (schedule_post_id),
                INDEX IDX_RELEASED_SLOT_SOURCE_ABSENCE (source_absence_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
        $this->addSql('ALTER TABLE released_operating_room_slot ADD CONSTRAINT FK_RELEASED_SLOT_SITE FOREIGN KEY (site_id) REFERENCES hospital (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE released_operating_room_slot ADD CONSTRAINT FK_RELEASED_SLOT_SCHEDULE_POST FOREIGN KEY (schedule_post_id) REFERENCES surgeon_schedule_post (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE released_operating_room_slot ADD CONSTRAINT FK_RELEASED_SLOT_SURGEON FOREIGN KEY (surgeon_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE released_operating_room_slot ADD CONSTRAINT FK_RELEASED_SLOT_SOURCE_ABSENCE FOREIGN KEY (source_absence_id) REFERENCES absence (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE released_operating_room_slot');
    }
}
