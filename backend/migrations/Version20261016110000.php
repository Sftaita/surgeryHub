<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-155 — activité exécutée sans instrumentiste : un fait sur la mission, jamais un statut.
 * `mission.executed_without_instrumentist_at` / `executed_without_instrumentist_by_id`
 * (qui l'a déclarée : chirurgien de la mission ou manager).
 *
 * Purement additif : colonnes nullables, aucune mission existante n'est marquée.
 *
 * Rollback : DROP des colonnes (les AuditEvent de déclaration / annulation restent).
 */
final class Version20261016110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-155 — mission.executed_without_instrumentist_at / _by_id (activity executed without instrumentist).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission ADD executed_without_instrumentist_at DATETIME DEFAULT NULL, ADD executed_without_instrumentist_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mission ADD CONSTRAINT FK_MISSION_EXECUTED_WITHOUT_INSTR_BY FOREIGN KEY (executed_without_instrumentist_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_MISSION_EXECUTED_WITHOUT_INSTR_BY ON mission (executed_without_instrumentist_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission DROP FOREIGN KEY FK_MISSION_EXECUTED_WITHOUT_INSTR_BY');
        $this->addSql('DROP INDEX IDX_MISSION_EXECUTED_WITHOUT_INSTR_BY ON mission');
        $this->addSql('ALTER TABLE mission DROP executed_without_instrumentist_at, DROP executed_without_instrumentist_by_id');
    }
}
