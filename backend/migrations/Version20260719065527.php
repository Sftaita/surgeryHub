<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — §32 du lot : uniquement des index, aucune
 * colonne/contrainte nouvelle, aucune donnée touchée. Chaque index correspond à une
 * requête agrégée réellement exécutée par FinancialStatisticsQueryService/
 * FinancialStatisticsRankingService/FinancialStatisticsDrilldownService (jamais
 * spéculatif) :
 *
 * - financial_calculation_line(effective_at, line_type) — filtre de période + ventilation
 *   firm/instrumentist sur overview/timeseries/by-firm/by-instrumentist/by-surgeon/
 *   by-intervention/top-materials.
 * - firm_invoice/instrumentist_statement(sent_at, status, document_type, currency) —
 *   valeur documentée par période d'émission (§4 du lot : GENERATED jamais compté comme
 *   émis), filtrée par statut/type/devise.
 * - payment(paid_at, direction, document_type, document_id) — flux monétaires par
 *   période, signe réel dérivé de (document_type, direction) — voir D-077.
 * - mission(start_at, status) — pipeline "missions VALIDATED sans calcul".
 * - mission_execution(actual_start_at) — date de rattachement de l'activité
 *   (COALESCE(actual_start_at, mission.start_at), §4 du lot).
 */
final class Version20260719065527 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pilotage financier Lot 7 (D-077) — index statistiques sur financial_calculation_line, firm_invoice, instrumentist_statement, payment, mission, mission_execution.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_fcl_effective_line_type ON financial_calculation_line (effective_at, line_type)');
        $this->addSql('CREATE INDEX idx_firm_invoice_sent_status_type_currency ON firm_invoice (sent_at, status, document_type, currency)');
        $this->addSql('CREATE INDEX idx_stmt_sent_status_type_currency ON instrumentist_statement (sent_at, status, document_type, currency)');
        $this->addSql('CREATE INDEX idx_payment_paid_direction_doc ON payment (paid_at, direction, document_type, document_id)');
        $this->addSql('CREATE INDEX idx_mission_start_status ON mission (start_at, status)');
        $this->addSql('CREATE INDEX idx_mission_execution_actual_start ON mission_execution (actual_start_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_fcl_effective_line_type ON financial_calculation_line');
        $this->addSql('DROP INDEX idx_firm_invoice_sent_status_type_currency ON firm_invoice');
        $this->addSql('DROP INDEX idx_stmt_sent_status_type_currency ON instrumentist_statement');
        $this->addSql('DROP INDEX idx_payment_paid_direction_doc ON payment');
        $this->addSql('DROP INDEX idx_mission_start_status ON mission');
        $this->addSql('DROP INDEX idx_mission_execution_actual_start ON mission_execution');
    }
}
