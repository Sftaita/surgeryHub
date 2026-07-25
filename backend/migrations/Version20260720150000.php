<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 7 (D-070) suite — commentaire instrumentiste requis à la clôture de l'encodage
 * quand aucune ligne de matériel active (quantité > 0) n'a été encodée. Deux colonnes,
 * intentionnellement distinctes :
 *
 * - `no_material_comment` (texte) : le contenu du commentaire. Nom explicite — jamais un
 *   "submit_comment" générique — parce qu'il n'a qu'un seul usage possible : justifier
 *   l'absence de matériel. Conservé pour traçabilité même après une resoumission
 *   ultérieure avec matériel (jamais effacé automatiquement).
 * - `submitted_without_material` (booléen) : capture, À CHAQUE clôture, si CETTE
 *   soumission précise avait 0 matériel. Sans ce flag, on ne pourrait pas distinguer
 *   après coup "cette soumission n'avait pas de matériel" de "il y avait du matériel
 *   mais le commentaire existe encore d'une soumission précédente" — le manager doit
 *   voir le commentaire comme justification active seulement dans le premier cas.
 *
 * `MissionSubmitRequest::$comment` existait déjà côté DTO mais n'était jamais persisté
 * (voir MissionService::submit()) — ces colonnes lui donnent enfin une destination.
 */
final class Version20260720150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Lot 7 (D-070) — ajoute mission.no_material_comment (justification instrumentiste) et mission.submitted_without_material (flag figé par soumission).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission ADD no_material_comment LONGTEXT DEFAULT NULL, ADD submitted_without_material TINYINT(1) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission DROP no_material_comment, DROP submitted_without_material');
    }
}
