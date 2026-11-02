<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-156 — identité stable d'une mission générée : le poste récurrent et la date de son
 * occurrence (date d'origine pour une occurrence déplacée).
 *
 * Purement additif : deux colonnes nullables sur `mission`, NULL pour toutes les missions
 * existantes (aucune donnée réécrite par la migration). Le générateur rattache une occurrence
 * à SA mission d'abord par ce lien ; sans lien (missions manuelles, anciennes missions), le
 * rapprochement par horaires existant s'applique comme avant. Les anciennes missions peuvent
 * être rattachées ensuite par `app:mission:link-schedule-posts` (contrôle par défaut, écriture
 * seulement avec --apply et l'accord de l'exploitant).
 *
 * FK ON DELETE SET NULL : un poste n'est jamais supprimé en pratique (désactivation), mais
 * une suppression ne doit jamais bloquer ni supprimer une mission.
 *
 * Rollback : DROP de la contrainte, des index et des deux colonnes (perd seulement les liens).
 */
final class Version20261102090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-156 — mission.schedule_post_id + schedule_occurrence_date (lien stable poste ↔ mission, additif).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mission ADD schedule_post_id INT DEFAULT NULL, ADD schedule_occurrence_date DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)'");
        $this->addSql('ALTER TABLE mission ADD CONSTRAINT FK_9067F23CC6E32F9D FOREIGN KEY (schedule_post_id) REFERENCES surgeon_schedule_post (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_9067F23CC6E32F9D ON mission (schedule_post_id)');
        $this->addSql('CREATE INDEX idx_mission_schedule_occurrence ON mission (schedule_post_id, schedule_occurrence_date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission DROP FOREIGN KEY FK_9067F23CC6E32F9D');
        $this->addSql('DROP INDEX idx_mission_schedule_occurrence ON mission');
        $this->addSql('DROP INDEX IDX_9067F23CC6E32F9D ON mission');
        $this->addSql('ALTER TABLE mission DROP schedule_post_id, DROP schedule_occurrence_date');
    }
}
