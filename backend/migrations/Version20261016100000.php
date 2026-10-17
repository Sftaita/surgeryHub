<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-154 — version optimiste des éléments d'encodage : `mission_intervention.version` et
 * `material_line.version` (Doctrine #[ORM\Version], incrémentée à chaque modification),
 * renvoyée par le client en If-Match pour qu'une modification concurrente (instrumentiste,
 * chirurgien, manager) ne soit jamais écrasée en silence.
 *
 * Purement additif : colonnes NOT NULL DEFAULT 1, les lignes existantes partent de 1.
 *
 * Rollback : DROP COLUMN (le contrôle de concurrence disparaît, aucune donnée métier perdue).
 */
final class Version20261016100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-154 — optimistic version on mission_intervention and material_line.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission_intervention ADD version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE material_line ADD version INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission_intervention DROP version');
        $this->addSql('ALTER TABLE material_line DROP version');
    }
}
