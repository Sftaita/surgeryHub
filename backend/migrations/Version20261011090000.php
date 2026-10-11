<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-143 — encodage progressif, horaires réels obligatoires, relance J+1/J+2/J+4.
 *
 * Purement additif :
 *  - `mission_execution.break_minutes` (nullable) : pause déclarée, seule information qui
 *    sépare la plage réelle début → fin de la durée prestée. Les lignes existantes gardent
 *    NULL (aucune pause connue) — aucune donnée historique n'est réécrite ;
 *  - table `mission_encoding_reminder` : une ligne par (mission, échéance), contrainte UNIQUE
 *    = idempotence des relances automatiques.
 *
 * Rollback : DROP TABLE + DROP COLUMN (perd l'historique des relances et les pauses saisies
 * depuis le déploiement ; les durées restent dans actual_duration_minutes).
 */
final class Version20261011090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-143 — mission_execution.break_minutes + mission_encoding_reminder (progressive encoding reminders).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission_execution ADD break_minutes INT DEFAULT NULL');
        $this->addSql('CREATE TABLE mission_encoding_reminder (
            id INT AUTO_INCREMENT NOT NULL,
            mission_id INT NOT NULL,
            instrumentist_id INT DEFAULT NULL,
            stage VARCHAR(8) NOT NULL,
            due_at DATETIME NOT NULL,
            sent_at DATETIME NOT NULL,
            situation VARCHAR(20) NOT NULL,
            missing_items JSON NOT NULL,
            instrumentist_channels JSON NOT NULL,
            email_reason VARCHAR(32) DEFAULT NULL,
            managers_notified INT DEFAULT NULL,
            failure VARCHAR(64) DEFAULT NULL,
            INDEX IDX_MER_MISSION (mission_id),
            INDEX IDX_MER_INSTRUMENTIST (instrumentist_id),
            UNIQUE INDEX uniq_mission_encoding_reminder_stage (mission_id, stage),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE mission_encoding_reminder ADD CONSTRAINT FK_MER_MISSION FOREIGN KEY (mission_id) REFERENCES mission (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mission_encoding_reminder ADD CONSTRAINT FK_MER_INSTRUMENTIST FOREIGN KEY (instrumentist_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mission_encoding_reminder');
        $this->addSql('ALTER TABLE mission_execution DROP break_minutes');
    }
}
