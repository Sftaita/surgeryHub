<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-156 — prestations regroupées multi-salles.
 *
 * Purement additif, aucune donnée historique réécrite :
 *  - `worked_presence` : présence réelle unique d'un instrumentiste pour une prestation
 *    regroupée (même instrumentiste / chirurgien / site / date locale, missions BLOC qui se
 *    chevauchent). Créée uniquement par une déclaration d'heures sur une prestation de 2+
 *    missions ; aucune ligne créée par la migration ;
 *  - `mission_execution.presence_id` (nullable, FK RESTRICT) : lien d'une mission vers sa
 *    présence. CHECK : une mission liée n'a jamais ses propres horaires réels (une seule
 *    source d'heures). Toutes les exécutions existantes ont presence_id NULL : le CHECK
 *    est satisfait sans toucher aux données ;
 *  - `mission.room_label` (nullable) : libellé de salle facultatif.
 *
 * Le regroupement prévisionnel n'est jamais persisté : les missions déjà publiées sont
 * reconnues dynamiquement, sans migration de données.
 *
 * Rollback : DROP de la contrainte, des FK, de `presence_id`, de `room_label` et de la
 * table (perd les présences déclarées depuis le déploiement — les parts imputées restent
 * dans actual_duration_minutes des missions liées).
 */
final class Version20261018090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-156 — worked_presence + mission_execution.presence_id (CHECK une seule source d\'heures) + mission.room_label (additif).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE worked_presence (id INT AUTO_INCREMENT NOT NULL, instrumentist_id INT NOT NULL, surgeon_id INT NOT NULL, site_id INT NOT NULL, declared_by_id INT NOT NULL, updated_by_id INT DEFAULT NULL, service_date DATE NOT NULL COMMENT '(DC2Type:date_immutable)', actual_start_at DATETIME DEFAULT NULL COMMENT '(DC2Type:business_datetime_immutable)', actual_end_at DATETIME DEFAULT NULL COMMENT '(DC2Type:business_datetime_immutable)', revision INT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_4B9EE45CFF41632 (instrumentist_id), INDEX IDX_4B9EE45CE506D696 (surgeon_id), INDEX IDX_4B9EE45CF6BD1646 (site_id), INDEX IDX_4B9EE45CC48B85B0 (declared_by_id), INDEX IDX_4B9EE45C896DBBDE (updated_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE worked_presence ADD CONSTRAINT FK_4B9EE45CFF41632 FOREIGN KEY (instrumentist_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE worked_presence ADD CONSTRAINT FK_4B9EE45CE506D696 FOREIGN KEY (surgeon_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE worked_presence ADD CONSTRAINT FK_4B9EE45CF6BD1646 FOREIGN KEY (site_id) REFERENCES hospital (id)');
        $this->addSql('ALTER TABLE worked_presence ADD CONSTRAINT FK_4B9EE45CC48B85B0 FOREIGN KEY (declared_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE worked_presence ADD CONSTRAINT FK_4B9EE45C896DBBDE FOREIGN KEY (updated_by_id) REFERENCES user (id)');

        $this->addSql('ALTER TABLE mission_execution ADD presence_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mission_execution ADD CONSTRAINT FK_B0152013F328FFC4 FOREIGN KEY (presence_id) REFERENCES worked_presence (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX IDX_B0152013F328FFC4 ON mission_execution (presence_id)');
        $this->addSql('ALTER TABLE mission_execution ADD CONSTRAINT chk_mission_execution_single_hours_source CHECK (presence_id IS NULL OR (actual_start_at IS NULL AND actual_end_at IS NULL))');

        $this->addSql('ALTER TABLE mission ADD room_label VARCHAR(60) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission DROP room_label');
        $this->addSql('ALTER TABLE mission_execution DROP CHECK chk_mission_execution_single_hours_source');
        $this->addSql('ALTER TABLE mission_execution DROP FOREIGN KEY FK_B0152013F328FFC4');
        $this->addSql('DROP INDEX IDX_B0152013F328FFC4 ON mission_execution');
        $this->addSql('ALTER TABLE mission_execution DROP presence_id');
        $this->addSql('DROP TABLE worked_presence');
    }
}
