<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-178 — auteur de l'encodage d'une intervention (raccourcis « firmes récemment utilisées »).
 *
 * Numérotée après Version20261030150000 (D-177, déjà exécutée en production) : une migration
 * plus ancienne que la dernière exécutée serait bien jouée par Doctrine, mais affichée hors ordre
 * (« Current » = « Latest » alors qu'elle reste à jouer). Anciennement Version20261030120000.
 *
 * Purement additif : colonne nullable, aucune ligne existante modifiée (aucun auteur reconstruit
 * pour l'historique). ON DELETE SET NULL : la suppression d'un utilisateur ne touche jamais
 * l'intervention.
 *
 * Rollback : DROP de la clé étrangère, de l'index et de la colonne ; aucune donnée métier n'en dépend.
 */
final class Version20261031180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-178 — mission_intervention.created_by_id (auteur de l\'encodage).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission_intervention ADD created_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mission_intervention ADD CONSTRAINT FK_672590FB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_672590FB03A8386 ON mission_intervention (created_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission_intervention DROP FOREIGN KEY FK_672590FB03A8386');
        $this->addSql('DROP INDEX IDX_672590FB03A8386 ON mission_intervention');
        $this->addSql('ALTER TABLE mission_intervention DROP created_by_id');
    }
}
