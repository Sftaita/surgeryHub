<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D-171 — reprise manager : `mission.encoding_validated_without_hours_at`, le fait « interventions
 * et matériel validés alors que les heures prestées de l'instrumentiste manquaient ». Tant que ces
 * heures manquent, elles restent déclarables (instrumentiste affectée, manager) malgré le verrou de
 * l'encodage ; aucune heure n'est créée ni validée par ce fait.
 *
 * Facturation firme indépendante des heures (D-171) : `financial_calculation.instrumentist_hours_pending`
 * (calcul produit sans la prestation horaire, heures non déclarées) et `supplement_of_id` (calcul
 * complémentaire portant la seule prestation instrumentiste d'un calcul principal verrouillé).
 *
 * Purement additif : colonnes nullables ou à défaut neutre (false), aucune ligne existante modifiée.
 *
 * Rollback : DROP des colonnes et de la contrainte (les AuditEvent restent). À ne faire qu'en
 * l'absence de calcul complémentaire (sinon ses lignes perdraient leur rattachement).
 */
final class Version20261027090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D-171 — mission.encoding_validated_without_hours_at ; financial_calculation.instrumentist_hours_pending / supplement_of_id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission ADD encoding_validated_without_hours_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE financial_calculation ADD instrumentist_hours_pending TINYINT(1) DEFAULT 0 NOT NULL, ADD supplement_of_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE financial_calculation ADD CONSTRAINT fk_financial_calculation_supplement_of FOREIGN KEY (supplement_of_id) REFERENCES financial_calculation (id)');
        $this->addSql('CREATE INDEX idx_financial_calculation_supplement_of ON financial_calculation (supplement_of_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE financial_calculation DROP FOREIGN KEY fk_financial_calculation_supplement_of');
        $this->addSql('DROP INDEX idx_financial_calculation_supplement_of ON financial_calculation');
        $this->addSql('ALTER TABLE financial_calculation DROP instrumentist_hours_pending, DROP supplement_of_id');
        $this->addSql('ALTER TABLE mission DROP encoding_validated_without_hours_at');
    }
}
