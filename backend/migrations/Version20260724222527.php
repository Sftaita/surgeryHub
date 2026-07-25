<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 1 (fiabilisation du socle Web Push) — ajoute la contrainte UNIQUE(endpoint) sur
 * push_subscription, déjà déclarée côté entité Doctrine (PushSubscription.php) depuis sa
 * création mais jamais portée par la migration d'origine (Version20260107053952) — dérive
 * entité/schéma identifiée par l'audit PWA/push du 24-07-2026.
 *
 * Audit préalable des données (dev + test, dbal:run-sql) : 0 ligne dans push_subscription
 * dans les deux environnements au moment de cette migration. Aucun doublon à nettoyer :
 * migration strictement additive.
 */
final class Version20260724222527 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 1 Web Push : ajoute la contrainte UNIQUE(endpoint) manquante sur push_subscription (table vide, additive).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE push_subscription ADD CONSTRAINT uniq_push_subscription_endpoint UNIQUE (endpoint)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE push_subscription DROP INDEX uniq_push_subscription_endpoint');
    }
}
