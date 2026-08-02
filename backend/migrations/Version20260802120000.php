<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Refonte Catalogue/Prestations (D-092) — quatre ajouts additifs, aucune donnée
 * existante réinterprétée silencieusement :
 *
 * 1. firm_service_offering : politique commerciale "présence d'un délégué" (4 booléens,
 *    tous par défaut à leur valeur neutre = comportement inchangé pour toute prestation
 *    existante — representative_presence_relevant=0, les deux suppresses*=0,
 *    fee_applicable=1 = "un forfait est attendu", identique au comportement pré-lot).
 * 2. mission_intervention : representative_present, nullable, jamais backfillée (donnée
 *    factuelle qui n'existait pas avant ce lot — null = "jamais répondu", légitime pour
 *    tout l'historique).
 * 3. material_item : billing_status, backfillée à BILLABLE uniquement pour les matériels
 *    ayant aujourd'hui une PricingRule MATERIAL_FEE active — UNSPECIFIED partout ailleurs
 *    (jamais NOT_BILLABLE par défaut : ce serait deviner une décision commerciale jamais
 *    prise, contraire à l'esprit du lot).
 * 4. financial_calculation_line : gross_amount (backfillée = total_amount existant,
 *    aucun ajustement historique), adjustment_amount (backfillée à 0.00), warnings
 *    (backfillée à '[]').
 */
final class Version20260802120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refonte Catalogue/Prestations (D-092) : politique délégué (FirmServiceOffering), representative_present (MissionIntervention), billing_status (MaterialItem), gross/adjustment/warnings (FinancialCalculationLine).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE firm_service_offering
            ADD representative_presence_relevant TINYINT(1) NOT NULL DEFAULT 0,
            ADD representative_suppresses_intervention_fee TINYINT(1) NOT NULL DEFAULT 0,
            ADD representative_suppresses_own_material_fees TINYINT(1) NOT NULL DEFAULT 0,
            ADD fee_applicable TINYINT(1) NOT NULL DEFAULT 1');

        $this->addSql('ALTER TABLE mission_intervention ADD representative_present TINYINT(1) DEFAULT NULL');

        $this->addSql("ALTER TABLE material_item ADD billing_status VARCHAR(20) NOT NULL DEFAULT 'UNSPECIFIED'");
        $this->addSql("UPDATE material_item mi
            SET billing_status = 'BILLABLE'
            WHERE EXISTS (
                SELECT 1 FROM pricing_rule pr
                WHERE pr.material_item_id = mi.id AND pr.rule_type = 'MATERIAL_FEE' AND pr.active = 1
            )");

        $this->addSql('ALTER TABLE financial_calculation_line ADD gross_amount NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('UPDATE financial_calculation_line SET gross_amount = total_amount WHERE gross_amount IS NULL');
        $this->addSql('ALTER TABLE financial_calculation_line MODIFY gross_amount NUMERIC(10, 2) NOT NULL');

        $this->addSql("ALTER TABLE financial_calculation_line ADD adjustment_amount NUMERIC(10, 2) NOT NULL DEFAULT '0.00'");

        $this->addSql('ALTER TABLE financial_calculation_line ADD warnings JSON DEFAULT NULL');
        $this->addSql("UPDATE financial_calculation_line SET warnings = '[]' WHERE warnings IS NULL");
        $this->addSql('ALTER TABLE financial_calculation_line MODIFY warnings JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE financial_calculation_line DROP gross_amount, DROP adjustment_amount, DROP warnings');
        $this->addSql('ALTER TABLE material_item DROP billing_status');
        $this->addSql('ALTER TABLE mission_intervention DROP representative_present');
        $this->addSql('ALTER TABLE firm_service_offering
            DROP representative_presence_relevant,
            DROP representative_suppresses_intervention_fee,
            DROP representative_suppresses_own_material_fees,
            DROP fee_applicable');
    }
}
