<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * EPIC Exécution & Valorisation, Lot 1 — InstrumentistService devient MissionExecution
 * (le RÉALISÉ, distinct du planifié et de la future valorisation financière).
 *
 * Stratégie : renommage de table + colonnes, PAS une nouvelle table + copie — aucune
 * donnée à recopier, seule la forme change. Vérifié avant migration (0 ligne en
 * production au moment de ce lot) : aucun risque de perte, mais la migration est
 * écrite pour être correcte même avec des données réelles.
 *
 * Mapping ancien → nouveau :
 *   instrumentist_service          → mission_execution
 *     .hours (NUMERIC 5,2, décimal en HEURES)     → .actual_duration_minutes (INT, MINUTES) = ROUND(hours * 60)
 *     .hours_source                                → .hours_source (inchangé)
 *     .mission_id                                  → .mission_id (inchangé, désormais UNIQUE : vraie relation 1—0..1)
 *     .service_type                                → SUPPRIMÉ (dupliquait Mission.type, jamais relu)
 *     .employment_type_snapshot                    → SUPPRIMÉ (jamais lu par aucun chemin de production)
 *     .consultation_fee_applied                    → SUPPRIMÉ (jamais lu — InstrumentistStatementService relit User.consultationFee en direct)
 *     .status (CALCULATED/APPROVED/PAID)           → SUPPRIMÉ (statut financier mort, jamais piloté ni lu)
 *     .computed_amount                             → SUPPRIMÉ (jamais écrit par aucun code — champ absent de ServiceUpdateRequest)
 *     —                                             → .actual_start_at (NOUVEAU, nullable — aucune donnée antérieure : le formulaire
 *                                                       existant ne capturait qu'une durée, jamais des horaires réels)
 *     —                                             → .actual_end_at (NOUVEAU, nullable, idem)
 *
 *   service_hours_dispute          → mission_execution_dispute
 *     .service_id                                  → .mission_execution_id (renommage de colonne uniquement)
 *     tout le reste (raised_by_id, reason_code, comment, status, resolution_comment,
 *     created_at, updated_at)                       → inchangé, aucune perte
 *
 * Voir docs/decisions.md pour la décision d'architecture (Mission = planifié,
 * MissionExecution = réalisé, FinancialCalculation = valorisation — non implémenté
 * dans ce lot).
 */
final class Version20260718065425 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Exécution & Valorisation Lot 1 — InstrumentistService → MissionExecution (renommage), ServiceHoursDispute → MissionExecutionDispute (renommage), suppression des champs financiers morts.';
    }

    public function up(Schema $schema): void
    {
        // 1) Détacher les FK existantes avant renommage/altération des colonnes qu'elles ciblent.
        $this->addSql('ALTER TABLE service_hours_dispute DROP FOREIGN KEY FK_48E23C5DBE6CAE90');
        $this->addSql('ALTER TABLE service_hours_dispute DROP FOREIGN KEY FK_48E23C5DED5CA9E6');
        $this->addSql('ALTER TABLE service_hours_dispute DROP FOREIGN KEY FK_48E23C5DB0CDEB44');
        $this->addSql('ALTER TABLE instrumentist_service DROP FOREIGN KEY FK_AC5ED2ADBE6CAE90');
        $this->addSql('DROP INDEX IDX_AC5ED2ADBE6CAE90 ON instrumentist_service');
        $this->addSql('DROP INDEX IDX_48E23C5DBE6CAE90 ON service_hours_dispute');
        $this->addSql('DROP INDEX IDX_48E23C5DED5CA9E6 ON service_hours_dispute');
        $this->addSql('DROP INDEX IDX_48E23C5DB0CDEB44 ON service_hours_dispute');

        // 2) Renommage des tables — pas de nouvelle table, pas de copie.
        $this->addSql('RENAME TABLE instrumentist_service TO mission_execution');
        $this->addSql('RENAME TABLE service_hours_dispute TO mission_execution_dispute');

        // 3) Nouvelles colonnes "réalisé" (nullable — aucune donnée antérieure à migrer vers elles).
        $this->addSql('ALTER TABLE mission_execution ADD actual_start_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:business_datetime_immutable)\'');
        $this->addSql('ALTER TABLE mission_execution ADD actual_end_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:business_datetime_immutable)\'');
        $this->addSql('ALTER TABLE mission_execution ADD actual_duration_minutes INT DEFAULT NULL');

        // 4) Migration des données existantes : hours (décimal, heures) → actual_duration_minutes (entier, minutes).
        $this->addSql('UPDATE mission_execution SET actual_duration_minutes = ROUND(hours * 60) WHERE hours IS NOT NULL');

        // 5) Suppression des champs financiers/administratifs morts (vérifié : aucun chemin de
        //    production n'en dépend — voir MissionExecution.php et docs/decisions.md).
        $this->addSql('ALTER TABLE mission_execution DROP service_type');
        $this->addSql('ALTER TABLE mission_execution DROP employment_type_snapshot');
        $this->addSql('ALTER TABLE mission_execution DROP consultation_fee_applied');
        $this->addSql('ALTER TABLE mission_execution DROP status');
        $this->addSql('ALTER TABLE mission_execution DROP computed_amount');
        $this->addSql('ALTER TABLE mission_execution DROP hours');

        // 6) mission_id devient une vraie relation 1—0..1 (Mission ↔ MissionExecution).
        $this->addSql('ALTER TABLE mission_execution ADD CONSTRAINT uniq_mission_execution_mission UNIQUE (mission_id)');

        // 7) Renommage de colonne sur la table de contestations (service_id → mission_execution_id).
        $this->addSql('ALTER TABLE mission_execution_dispute CHANGE service_id mission_execution_id INT NOT NULL');

        // 8) Index propres (noms explicites, cohérents avec le reste du code — voir Lot 7).
        $this->addSql('CREATE INDEX idx_mission_execution_dispute_mission ON mission_execution_dispute (mission_id)');
        $this->addSql('CREATE INDEX idx_mission_execution_dispute_execution ON mission_execution_dispute (mission_execution_id)');
        $this->addSql('CREATE INDEX idx_mission_execution_dispute_raised_by ON mission_execution_dispute (raised_by_id)');

        // 9) FK reconstituées avec des noms explicites.
        $this->addSql('ALTER TABLE mission_execution ADD CONSTRAINT fk_mission_execution_mission FOREIGN KEY (mission_id) REFERENCES mission (id)');
        $this->addSql('ALTER TABLE mission_execution_dispute ADD CONSTRAINT fk_mission_execution_dispute_mission FOREIGN KEY (mission_id) REFERENCES mission (id)');
        $this->addSql('ALTER TABLE mission_execution_dispute ADD CONSTRAINT fk_mission_execution_dispute_execution FOREIGN KEY (mission_execution_id) REFERENCES mission_execution (id)');
        $this->addSql('ALTER TABLE mission_execution_dispute ADD CONSTRAINT fk_mission_execution_dispute_raised_by FOREIGN KEY (raised_by_id) REFERENCES user (id)');

        // 10) Contrainte d'unicité (mission_execution_id, status) — une seule contestation
        //     OPEN à la fois par exécution (même invariant qu'avant, appliqué aussi en code).
        $this->addSql('ALTER TABLE mission_execution_dispute ADD CONSTRAINT uniq_execution_status UNIQUE (mission_execution_id, status)');
    }

    public function down(Schema $schema): void
    {
        // Réversion structurelle — les champs financiers morts supprimés à l'étape 5 de
        // up() sont recréés nullable (pas de reconstruction de données, ils étaient déjà
        // vérifiés inutilisés avant suppression).
        $this->addSql('ALTER TABLE mission_execution_dispute DROP CONSTRAINT uniq_execution_status');
        $this->addSql('ALTER TABLE mission_execution_dispute DROP FOREIGN KEY fk_mission_execution_dispute_raised_by');
        $this->addSql('ALTER TABLE mission_execution_dispute DROP FOREIGN KEY fk_mission_execution_dispute_execution');
        $this->addSql('ALTER TABLE mission_execution_dispute DROP FOREIGN KEY fk_mission_execution_dispute_mission');
        $this->addSql('ALTER TABLE mission_execution DROP FOREIGN KEY fk_mission_execution_mission');

        $this->addSql('DROP INDEX idx_mission_execution_dispute_raised_by ON mission_execution_dispute');
        $this->addSql('DROP INDEX idx_mission_execution_dispute_execution ON mission_execution_dispute');
        $this->addSql('DROP INDEX idx_mission_execution_dispute_mission ON mission_execution_dispute');

        $this->addSql('ALTER TABLE mission_execution_dispute CHANGE mission_execution_id service_id INT NOT NULL');

        $this->addSql('ALTER TABLE mission_execution DROP CONSTRAINT uniq_mission_execution_mission');

        $this->addSql('ALTER TABLE mission_execution ADD hours NUMERIC(5, 2) DEFAULT NULL');
        $this->addSql('UPDATE mission_execution SET hours = actual_duration_minutes / 60 WHERE actual_duration_minutes IS NOT NULL');
        $this->addSql('ALTER TABLE mission_execution ADD service_type VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE mission_execution ADD employment_type_snapshot VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE mission_execution ADD consultation_fee_applied NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE mission_execution ADD status VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE mission_execution ADD computed_amount NUMERIC(10, 2) DEFAULT NULL');

        $this->addSql('ALTER TABLE mission_execution DROP actual_start_at');
        $this->addSql('ALTER TABLE mission_execution DROP actual_end_at');
        $this->addSql('ALTER TABLE mission_execution DROP actual_duration_minutes');

        $this->addSql('RENAME TABLE mission_execution_dispute TO service_hours_dispute');
        $this->addSql('RENAME TABLE mission_execution TO instrumentist_service');

        $this->addSql('CREATE INDEX IDX_AC5ED2ADBE6CAE90 ON instrumentist_service (mission_id)');
        $this->addSql('CREATE INDEX IDX_48E23C5DBE6CAE90 ON service_hours_dispute (mission_id)');
        $this->addSql('CREATE INDEX IDX_48E23C5DED5CA9E6 ON service_hours_dispute (service_id)');
        $this->addSql('CREATE INDEX IDX_48E23C5DB0CDEB44 ON service_hours_dispute (raised_by_id)');

        $this->addSql('ALTER TABLE instrumentist_service ADD CONSTRAINT FK_AC5ED2ADBE6CAE90 FOREIGN KEY (mission_id) REFERENCES mission (id)');
        $this->addSql('ALTER TABLE service_hours_dispute ADD CONSTRAINT FK_48E23C5DBE6CAE90 FOREIGN KEY (mission_id) REFERENCES mission (id)');
        $this->addSql('ALTER TABLE service_hours_dispute ADD CONSTRAINT FK_48E23C5DED5CA9E6 FOREIGN KEY (service_id) REFERENCES instrumentist_service (id)');
        $this->addSql('ALTER TABLE service_hours_dispute ADD CONSTRAINT FK_48E23C5DB0CDEB44 FOREIGN KEY (raised_by_id) REFERENCES user (id)');
    }
}
