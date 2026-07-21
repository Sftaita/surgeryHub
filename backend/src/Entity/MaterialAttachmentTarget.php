<?php

namespace App\Entity;

use App\Enum\MaterialLineBillingState;

/**
 * EPIC Revue instrumentiste, Lot 3 — contrat métier commun entre MissionIntervention
 * (cible réelle) et MissionInterventionDraft (cible provisoire), pour que les
 * consommateurs (attachement de matériel, moteur financier, futur assemblage de liste
 * ordonnée) ne branchent jamais de logique sur `if ($draft !== null)`/
 * `if ($missionIntervention !== null)`. Même principe déjà établi dans ce code par
 * App\Entity\PayableDocument (FirmInvoice/InstrumentistStatement, D-075) : ne remplace
 * pas les entités par un modèle générique — elles restent deux agrégats et deux tables
 * distinctes, unifiées par une interface.
 *
 * En dehors des entités elles-mêmes, du mapping Doctrine, et du futur résolveur dédié,
 * aucune logique métier ne doit lire MaterialLine::getMissionIntervention()/
 * getInterventionDraft() (ou leurs équivalents sur MaterialItemRequest) directement —
 * toujours passer par MaterialLine::attachmentTarget() et cette interface.
 */
interface MaterialAttachmentTarget
{
    public function getId(): int;

    public function getMission(): Mission;

    public function getOrderIndex(): int;

    /** false une fois l'emplacement clos (CONVERTED / MATERIAL_REASSIGNED / KEPT_AS_HISTORY). */
    public function acceptsNewMaterial(): bool;

    /**
     * Non-null uniquement quand CE target a été remplacé par un autre (CONVERTED,
     * MATERIAL_REASSIGNED) — la cible réelle vers laquelle rediriger silencieusement une
     * nouvelle écriture (pas encore consommé dans ce commit — voir le futur résolveur).
     * Null si acceptsNewMaterial() est true, OU si le target est fermé sans redirection
     * possible (KEPT_AS_HISTORY — seul cas qui restera bloquant/409 une fois branché).
     */
    public function redirectTarget(): ?self;

    /** CATALOGUED | REQUEST_PENDING | HISTORY_ONLY — voir MaterialLineBillingState. */
    public function billingEligibility(): MaterialLineBillingState;
}
