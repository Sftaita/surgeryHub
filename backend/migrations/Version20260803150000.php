<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Catalogue > Prestations, refonte UX — ajout du logo de firme (Firm.logoPath), même
 * convention que User.profilePicturePath. Additif pur, aucune donnée existante affectée.
 */
final class Version20260803150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Firm : ajout logo_path (chemin racine-relatif, nullable) pour l'avatar de firme.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE firm ADD logo_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE firm DROP logo_path');
    }
}
