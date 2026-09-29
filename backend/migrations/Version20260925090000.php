<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-124 — « Reprendre une salle libérée ». Migration purement additive sur
 * `released_operating_room_slot` (Lot D) : quatre colonnes nullables, aucune donnée existante
 * touchée — toutes les lignes existantes restent AVAILABLE avec ces champs à NULL.
 * Écrite à la main, même raison que Version20260906140000 (diff DBAL cassé sur ce projet).
 * FK toutes ON DELETE SET NULL (même résilience que les autres relations de cette table).
 */
final class Version20260925090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-124 — reprise de salle : claimed_by_id, claimed_at, takeover_mission_id, original_mission_id sur released_operating_room_slot.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE released_operating_room_slot
            ADD claimed_by_id INT DEFAULT NULL,
            ADD claimed_at DATETIME DEFAULT NULL,
            ADD takeover_mission_id INT DEFAULT NULL,
            ADD original_mission_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_RELEASED_SLOT_CLAIMED_BY ON released_operating_room_slot (claimed_by_id)');
        $this->addSql('CREATE INDEX IDX_RELEASED_SLOT_TAKEOVER_MISSION ON released_operating_room_slot (takeover_mission_id)');
        $this->addSql('CREATE INDEX IDX_RELEASED_SLOT_ORIGINAL_MISSION ON released_operating_room_slot (original_mission_id)');
        $this->addSql('ALTER TABLE released_operating_room_slot ADD CONSTRAINT FK_RELEASED_SLOT_CLAIMED_BY FOREIGN KEY (claimed_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE released_operating_room_slot ADD CONSTRAINT FK_RELEASED_SLOT_TAKEOVER_MISSION FOREIGN KEY (takeover_mission_id) REFERENCES mission (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE released_operating_room_slot ADD CONSTRAINT FK_RELEASED_SLOT_ORIGINAL_MISSION FOREIGN KEY (original_mission_id) REFERENCES mission (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE released_operating_room_slot DROP FOREIGN KEY FK_RELEASED_SLOT_CLAIMED_BY');
        $this->addSql('ALTER TABLE released_operating_room_slot DROP FOREIGN KEY FK_RELEASED_SLOT_TAKEOVER_MISSION');
        $this->addSql('ALTER TABLE released_operating_room_slot DROP FOREIGN KEY FK_RELEASED_SLOT_ORIGINAL_MISSION');
        $this->addSql('DROP INDEX IDX_RELEASED_SLOT_CLAIMED_BY ON released_operating_room_slot');
        $this->addSql('DROP INDEX IDX_RELEASED_SLOT_TAKEOVER_MISSION ON released_operating_room_slot');
        $this->addSql('DROP INDEX IDX_RELEASED_SLOT_ORIGINAL_MISSION ON released_operating_room_slot');
        $this->addSql('ALTER TABLE released_operating_room_slot DROP claimed_by_id, DROP claimed_at, DROP takeover_mission_id, DROP original_mission_id');
    }
}
