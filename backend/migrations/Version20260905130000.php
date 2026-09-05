<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Communication des absences chirurgiens — Lot B (D-114). Ajoute
 * `surgeon_absence_communication_delivery.dispatch_claimed_at` : garde-fou de claim
 * atomique pour `SendScheduledAbsenceCommunicationsCommand` — `status` seul ne suffit pas à
 * empêcher deux exécutions (concurrentes ou rapprochées) de redispatcher la même
 * communication programmée tant que le pipeline d'envoi réel n'a pas confirmé SENT/FAILED
 * (voir AbsenceCommunicationJournalService::claimScheduledBlockManagementDelivery()).
 * Purement additive.
 */
final class Version20260905130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Communication des absences chirurgiens (Lot B/D-114) — surgeon_absence_communication_delivery.dispatch_claimed_at (claim atomique du scheduler).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE surgeon_absence_communication_delivery ADD dispatch_claimed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE surgeon_absence_communication_delivery DROP dispatch_claimed_at');
    }
}
