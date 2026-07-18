<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * EPIC Exécution & Valorisation, Lot 2 (D-072) — historisation des tarifs.
 *
 * 1) audit_event.mission_id devient nullable : les événements catalogue/tarifaires
 *    (PricingRule, InstrumentistRate) n'ont aucune Mission à rattacher — voir
 *    AuditService::recordGlobal(). Aucune donnée existante affectée (élargissement
 *    d'une contrainte, jamais un rétrécissement).
 *
 * 2) Nouvelle table instrumentist_rate — modèle historisé remplaçant progressivement
 *    User.hourlyRate/consultationFee comme source de vérité financière. User.hourlyRate/
 *    consultationFee ne sont PAS supprimés (compatibilité legacy explicite, D-072 §5.2) :
 *    le endpoint PATCH /api/instrumentists/{id}/rates existant continue de les utiliser
 *    directement, inchangé dans ce lot.
 *
 * 3) Backfill : pour chaque utilisateur ayant actuellement un hourlyRate/consultationFee
 *    non-null, une première InstrumentistRate est créée avec :
 *      - amount = valeur actuelle du champ (préservée telle quelle)
 *      - currency = User.defaultCurrency (COALESCE 'EUR' si jamais renseigné)
 *      - validFrom = DATE(User.createdAt) — voir la priorité documentée dans D-072 :
 *        (1) date historique fiable si disponible — AUCUNE n'existe dans le schéma
 *            actuel (User n'a pas de champ "rateEffectiveSince"), donc cette option
 *            n'est jamais atteignable par ce backfill ;
 *        (2) date de création de l'utilisateur (User.createdAt) — retenue ici, c'est
 *            la meilleure date métier-compatible réellement disponible ;
 *        (3) date de migration — dernier recours, non utilisé puisque (2) est toujours
 *            disponible (createdAt est NOT NULL sur User).
 *      - validTo = NULL (ouvert, tarif actuellement en vigueur)
 *    Documenté explicitement : cette date de début est une approximation (le tarif
 *    aurait pu changer entre la création du compte et aujourd'hui sans laisser de
 *    trace) — c'est un cas assumé de "donnée passée ambiguë conservée sans deviner
 *    une relation incorrecte" (D-072 §5) : on ne peut pas reconstruire l'historique
 *    réel, seulement figer la valeur actuelle sur la date la plus fiable disponible.
 *
 * 4) PricingRule existantes : AUCUN backfill de validFrom appliqué. Vérifié avant
 *    d'écrire cette migration (0 ligne dans pricing_rule en production au moment de ce
 *    lot). Si une future ligne avec validFrom=NULL existe, elle N'EST PAS ambiguë ni
 *    incomplète — c'est la sémantique D-067 "valide depuis toujours", délibérée et
 *    correcte, jamais changée par ce lot. Deviner une date de début synthétique
 *    changerait rétroactivement le résultat de résolution (violerait l'invariant même
 *    que ce lot construit) — voir D-072 pour la décision explicite de ne PAS toucher
 *    aux lignes existantes.
 */
final class Version20260718121937 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Exécution & Valorisation Lot 2 — audit_event.mission_id nullable ; nouvelle table instrumentist_rate + backfill depuis User.hourlyRate/consultationFee.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_event MODIFY mission_id INT DEFAULT NULL');

        $this->addSql('
            CREATE TABLE instrumentist_rate (
                id INT AUTO_INCREMENT NOT NULL,
                instrumentist_id INT NOT NULL,
                rate_type VARCHAR(30) NOT NULL,
                amount NUMERIC(10, 2) NOT NULL,
                currency VARCHAR(3) NOT NULL,
                valid_from DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
                valid_to DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_instrumentist_rate_resolution (instrumentist_id, rate_type, valid_from, valid_to),
                CONSTRAINT fk_instrumentist_rate_instrumentist FOREIGN KEY (instrumentist_id) REFERENCES user (id),
                CONSTRAINT chk_instrumentist_rate_amount_nonneg CHECK (amount >= 0),
                CONSTRAINT chk_instrumentist_rate_valid_to_after_from CHECK (valid_to IS NULL OR valid_to > valid_from),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        // Backfill — voir docblock de la classe pour la stratégie et sa justification.
        $this->addSql("
            INSERT INTO instrumentist_rate (instrumentist_id, rate_type, amount, currency, valid_from, valid_to, created_at, updated_at)
            SELECT id, 'HOURLY_RATE', hourly_rate, COALESCE(NULLIF(default_currency, ''), 'EUR'), DATE(created_at), NULL, NOW(), NOW()
            FROM user WHERE hourly_rate IS NOT NULL
        ");
        $this->addSql("
            INSERT INTO instrumentist_rate (instrumentist_id, rate_type, amount, currency, valid_from, valid_to, created_at, updated_at)
            SELECT id, 'CONSULTATION_FEE', consultation_fee, COALESCE(NULLIF(default_currency, ''), 'EUR'), DATE(created_at), NULL, NOW(), NOW()
            FROM user WHERE consultation_fee IS NOT NULL
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE instrumentist_rate');

        // Best-effort : un rollback qui restaurerait NOT NULL échouerait s'il existe déjà
        // des AuditEvent catalogue (mission_id NULL) créés depuis ce lot — supprimés ici
        // délibérément (perte de données assumée sur rollback, cohérent avec les autres
        // migrations de ce projet dont down() n'est pas garanti data-perfect).
        $this->addSql('DELETE FROM audit_event WHERE mission_id IS NULL');
        $this->addSql('ALTER TABLE audit_event MODIFY mission_id INT NOT NULL');
    }
}
