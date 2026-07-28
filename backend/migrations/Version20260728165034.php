<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Onboarding instrumentiste — ajoute user.instrumentist_onboarding_completed_at
 * (nullable, additif). Posé uniquement par POST /api/me/onboarding/complete.
 */
final class Version20260728165034 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Onboarding instrumentiste : ajoute user.instrumentist_onboarding_completed_at (additif).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD instrumentist_onboarding_completed_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP instrumentist_onboarding_completed_at');
    }
}
