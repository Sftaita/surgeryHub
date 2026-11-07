<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-151 — refus d'affectation par le chirurgien : colonnes additives et NULLABLES sur
 * `mission_assignment` (aucune donnée existante réécrite) :
 *  - `refusal_scope` : portée du refus (PUNCTUAL), posée uniquement quand end_reason =
 *    REFUSED_BY_SURGEON ;
 *  - `review_requested_at` / `review_requested_by_id` : demande d'intervention du manager
 *    (une par affectation) ;
 *  - index (mission_id, end_reason) : « cet instrumentiste a-t-il été refusé sur cette
 *    mission ? », lu par MissionEligibilityService sur chaque voie d'éligibilité.
 *
 * Rollback : DROP des colonnes et index (perd les portées et demandes enregistrées depuis).
 */
final class Version20261014120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-151 — mission_assignment.refusal_scope / review_requested_at / review_requested_by_id (nullable, additive) + refusal index.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission_assignment ADD refusal_scope VARCHAR(20) DEFAULT NULL, ADD review_requested_at DATETIME DEFAULT NULL, ADD review_requested_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mission_assignment ADD CONSTRAINT FK_MISSION_ASSIGNMENT_REVIEW_BY FOREIGN KEY (review_requested_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_MISSION_ASSIGNMENT_REVIEW_BY ON mission_assignment (review_requested_by_id)');
        $this->addSql('CREATE INDEX idx_mission_assignment_refusal ON mission_assignment (mission_id, end_reason)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission_assignment DROP FOREIGN KEY FK_MISSION_ASSIGNMENT_REVIEW_BY');
        $this->addSql('DROP INDEX IDX_MISSION_ASSIGNMENT_REVIEW_BY ON mission_assignment');
        $this->addSql('DROP INDEX idx_mission_assignment_refusal ON mission_assignment');
        $this->addSql('ALTER TABLE mission_assignment DROP refusal_scope, DROP review_requested_at, DROP review_requested_by_id');
    }
}
