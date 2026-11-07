<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-150 — socle d'affectation : table `mission_assignment` (une ligne par affectation d'un
 * instrumentiste à une mission publiée) + reprise des affectations existantes.
 *
 * « Au plus une affectation ouverte par mission », garanti par la base : `active_mission_key`
 * est une colonne générée STORED (= mission_id tant que ended_at IS NULL, NULL ensuite) portant
 * un index UNIQUE (plusieurs NULL acceptés). Générée plutôt qu'écrite par l'application : l'id
 * de la mission n'existe pas encore quand l'affectation d'une mission neuve est créée.
 *
 * Reprise (source LEGACY, jamais refusable), selon l'invariant D-150 : une affectation n'est
 * ouverte que pour une mission en cours de vie qui a un instrumentiste.
 *
 * - DECLARED, ASSIGNED, IN_PROGRESS, ENCODING_IN_PROGRESS, SUBMITTED, VALIDATED avec
 *   instrumentiste → UNE affectation ouverte (VALIDATED reste rouvrable, D-148) ;
 * - CANCELLED, REJECTED, CLOSED avec instrumentiste → UNE affectation déjà FERMÉE, pour garder
 *   la trace de la personne affectée ; end_reason = statut de la mission (CANCELLED / REJECTED /
 *   CLOSED), ended_by_id = NULL ;
 * - DRAFT, OPEN, ou mission sans instrumentiste → aucune ligne (une mission OPEN qui aurait
 *   encore un instrumentiste est une anomalie relevée par le protocole, pas reprise ici).
 *
 * Dates — aucune n'est présentée comme certaine :
 * - assigned_at : dernier AuditEvent d'affectation de la mission, sinon date de déploiement de
 *   sa version de planning, sinon date de création de la mission ;
 * - ended_at (lignes fermées) : date de l'AuditEvent qui a rendu la mission définitive
 *   (MISSION_CANCELLED_POST_DEPLOY, MISSION_DECLARED_REJECTED) s'il existe et n'est pas
 *   antérieur à assigned_at ; SINON ended_at = assigned_at. Règle de reprise identifiable :
 *   `source = 'LEGACY' AND ended_by_id IS NULL AND ended_at = assigned_at` = « fin non datée,
 *   seulement constatée à la reprise » (durée nulle conventionnelle, jamais une durée réelle).
 *
 * mission.instrumentist_id n'est jamais modifié : encodage, facturation, relevés et
 * consultations continuent d'y lire qui a travaillé sur la mission. Aucune donnée existante
 * n'est modifiée.
 *
 * FK `mission_id` / `instrumentist_id` sans ON DELETE : l'historique n'est jamais effacé en
 * silence (seules des missions DRAFT sont supprimées par l'application — PlanningDraftService).
 *
 * Rollback : DROP TABLE (perd l'historique des affectations ; missions intactes).
 */
final class Version20261014090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-150 — mission_assignment (affectation history, one open per mission) + LEGACY backfill (open for live missions, closed for final ones).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mission_assignment (
            id INT AUTO_INCREMENT NOT NULL,
            mission_id INT NOT NULL,
            instrumentist_id INT NOT NULL,
            assigned_by_id INT DEFAULT NULL,
            ended_by_id INT DEFAULT NULL,
            source VARCHAR(40) NOT NULL,
            assigned_at DATETIME NOT NULL,
            ended_at DATETIME DEFAULT NULL,
            end_reason VARCHAR(40) DEFAULT NULL,
            active_mission_key INT GENERATED ALWAYS AS (IF(ended_at IS NULL, mission_id, NULL)) STORED,
            INDEX idx_mission_assignment_mission (mission_id, ended_at),
            INDEX idx_mission_assignment_instrumentist (instrumentist_id, assigned_at),
            INDEX IDX_MISSION_ASSIGNMENT_ASSIGNED_BY (assigned_by_id),
            INDEX IDX_MISSION_ASSIGNMENT_ENDED_BY (ended_by_id),
            UNIQUE INDEX uniq_mission_assignment_active (active_mission_key),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE mission_assignment ADD CONSTRAINT FK_MISSION_ASSIGNMENT_MISSION FOREIGN KEY (mission_id) REFERENCES mission (id)');
        $this->addSql('ALTER TABLE mission_assignment ADD CONSTRAINT FK_MISSION_ASSIGNMENT_INSTRUMENTIST FOREIGN KEY (instrumentist_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE mission_assignment ADD CONSTRAINT FK_MISSION_ASSIGNMENT_ASSIGNED_BY FOREIGN KEY (assigned_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mission_assignment ADD CONSTRAINT FK_MISSION_ASSIGNMENT_ENDED_BY FOREIGN KEY (ended_by_id) REFERENCES `user` (id) ON DELETE SET NULL');

        // Statuts « vivants » (affectation ouverte) et définitifs (affectation fermée) — D-150.
        $this->addSql("INSERT INTO mission_assignment (mission_id, instrumentist_id, source, assigned_at, ended_at, end_reason)
            SELECT b.mission_id, b.instrumentist_id, 'LEGACY', b.assigned_at,
                CASE
                    WHEN b.status IN ('CANCELLED', 'REJECTED', 'CLOSED') THEN
                        IF(b.final_event_at IS NOT NULL AND b.final_event_at >= b.assigned_at, b.final_event_at, b.assigned_at)
                    ELSE NULL
                END,
                IF(b.status IN ('CANCELLED', 'REJECTED', 'CLOSED'), b.status, NULL)
            FROM (
                SELECT m.id AS mission_id, m.instrumentist_id, m.status,
                    COALESCE(
                        (SELECT MAX(ae.created_at) FROM audit_event ae
                          WHERE ae.mission_id = m.id
                            AND ae.event_type IN (
                                'MISSION_CLAIMED_FROM_POOL', 'MISSION_REASSIGNED_POST_DEPLOY', 'MISSION_ASSIGNED_DIRECTLY',
                                'MISSION_ADDED_POST_DEPLOY', 'MISSION_RESTORED_AFTER_SURGEON_ABSENCE',
                                'MISSION_ASSIGNMENT_RESTORED_AFTER_INSTRUMENTIST_ABSENCE', 'MISSION_ASSIGNED_FROM_PLANNING',
                                'MISSION_DECLARED'
                            )),
                        pv.deployed_at,
                        m.created_at
                    ) AS assigned_at,
                    (SELECT MAX(fe.created_at) FROM audit_event fe
                      WHERE fe.mission_id = m.id
                        AND ((m.status = 'CANCELLED' AND fe.event_type = 'MISSION_CANCELLED_POST_DEPLOY')
                          OR (m.status = 'REJECTED' AND fe.event_type = 'MISSION_DECLARED_REJECTED'))
                    ) AS final_event_at
                FROM mission m
                LEFT JOIN planning_version pv ON pv.id = m.planning_version_id
                WHERE m.instrumentist_id IS NOT NULL
                  AND m.status IN (
                      'DECLARED', 'ASSIGNED', 'IN_PROGRESS', 'ENCODING_IN_PROGRESS', 'SUBMITTED', 'VALIDATED',
                      'CANCELLED', 'REJECTED', 'CLOSED'
                  )
            ) b");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mission_assignment');
    }
}
