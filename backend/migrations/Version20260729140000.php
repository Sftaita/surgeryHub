<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Badge "offres non lues" (audit PWA/mobile/admin 2026-07-29, Lot 6) — ajoute
 * user.offers_last_seen_at (nullable, additif). Remplace le badge cumulatif de la nav
 * instrumentiste (comptait toutes les offres OPEN, sans notion de lecture) par un
 * compteur serveur (GET /api/missions/offers/unread-count) filtrant sur ce checkpoint,
 * mis à jour via POST /api/me/offers-seen.
 */
final class Version20260729140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Badge offres non lues : ajoute user.offers_last_seen_at (additif).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD offers_last_seen_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP offers_last_seen_at');
    }
}
