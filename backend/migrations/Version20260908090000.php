<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CAS D (brouillons Planning V2) — ajoute `preview_hash` sur `planning_version`, la même
 * empreinte SHA-256 que `PlanningGeneratorServiceV2::computePreviewVersion()` calcule déjà
 * pour la garde anti-preview-périmée de generate(). Persistée à la création du brouillon,
 * elle permet de détecter — sans jamais bloquer ni écraser — qu'un Post/ShiftPeriodConfig/
 * absence a changé depuis, à la réouverture (voir docs/decisions.md D-115). Purement
 * additive, nullable (tous les brouillons existants avant ce lot restent lisibles, juste
 * sans détection de divergence rétroactive).
 */
final class Version20260908090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CAS D — ajoute planning_version.preview_hash (détection de divergence de brouillon).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planning_version ADD preview_hash VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planning_version DROP preview_hash');
    }
}
