<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-178 — auteur de l'encodage d'une intervention (raccourcis « firmes récemment utilisées »)
 * et interventions sans firme.
 *
 * Numérotée après Version20261030150000 (D-177, déjà exécutée en production) : une migration
 * plus ancienne que la dernière exécutée serait bien jouée par Doctrine, mais affichée hors ordre
 * (« Current » = « Latest » alors qu'elle reste à jouer). Anciennement Version20261030120000.
 *
 * Purement additif : colonne nullable, aucune ligne existante modifiée (aucun auteur reconstruit
 * pour l'historique). ON DELETE SET NULL : la suppression d'un utilisateur ne touche jamais
 * l'intervention.
 *
 * Interventions sans firme : trois indicateurs NOT NULL DEFAULT 0 — aucune ligne existante
 * autorisée ni marquée (pas de validation rétroactive) :
 *  - intervention_type.allowed_without_firm : paramétrage manager (Catalogue › Interventions) ;
 *  - mission_intervention.without_firm : choix « Aucune firme » enregistré sur l'intervention ;
 *  - mission_intervention_draft.without_firm : demande d'intervention faite sans firme.
 *
 * Rollback (down) : jamais en production une fois des auteurs enregistrés ou des interventions
 * encodées sans firme (perte de données) ; l'ancien code ignore simplement ces colonnes.
 */
final class Version20261031180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-178 — mission_intervention.created_by_id (auteur de l\'encodage) ; interventions sans firme (allowed_without_firm, without_firm).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission_intervention ADD created_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mission_intervention ADD CONSTRAINT FK_672590FB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_672590FB03A8386 ON mission_intervention (created_by_id)');
        $this->addSql('ALTER TABLE intervention_type ADD allowed_without_firm TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE mission_intervention ADD without_firm TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE mission_intervention_draft ADD without_firm TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission_intervention_draft DROP without_firm');
        $this->addSql('ALTER TABLE mission_intervention DROP without_firm');
        $this->addSql('ALTER TABLE intervention_type DROP allowed_without_firm');
        $this->addSql('ALTER TABLE mission_intervention DROP FOREIGN KEY FK_672590FB03A8386');
        $this->addSql('DROP INDEX IDX_672590FB03A8386 ON mission_intervention');
        $this->addSql('ALTER TABLE mission_intervention DROP created_by_id');
    }
}
