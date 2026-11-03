<?php

namespace App\Service\Prestation;

use App\Entity\Mission;
use App\Exception\PrestationConflictException;

/**
 * Complément D-156 — tout nouvel encodage de contenu d'une prestation multi-salles
 * (intervention, ligne de matériel, demande au catalogue) s'ajoute sur sa mission de
 * référence (PrestationReference). Sur une autre mission de la prestation : refus explicite
 * (409 PRESTATION_REFERENCE_REQUIRED, identifiant de la référence fourni), jamais une
 * redirection silencieuse.
 *
 * Exception (décision utilisateur du 2026-11-02) : du matériel (ligne ou demande au
 * catalogue) s'ajoute à une intervention — ou une demande d'intervention — HISTORIQUE déjà
 * enregistrée sur une autre mission de la prestation : il reste sur la mission de son
 * intervention, jamais déplacé ni dupliqué. Aucune nouvelle intervention hors référence.
 *
 * Modifications et suppressions d'éléments existants : non concernées (chaque élément reste
 * sur sa mission d'origine, historique compris). Une mission seule n'est jamais concernée.
 */
class PrestationContentGuard
{
    public function __construct(private readonly PrestationGroupResolver $groups) {}

    /**
     * @param ?int $attachedInterventionId matériel : intervention (de CETTE mission) à laquelle il s'attache
     * @param ?int $attachedDraftId        matériel : demande d'intervention (de CETTE mission) à laquelle il s'attache
     */
    public function assertAcceptsNewContent(Mission $mission, ?int $attachedInterventionId = null, ?int $attachedDraftId = null): void
    {
        $group = $this->groups->groupOf($mission);
        if (count($group) < 2) {
            return;
        }

        $reference = PrestationReference::of($group);
        if ($reference->getId() === $mission->getId()) {
            return;
        }
        if (self::attachesToExistingEntry($mission, $attachedInterventionId, $attachedDraftId)) {
            return;
        }

        throw new PrestationConflictException(
            PrestationConflictException::PRESTATION_REFERENCE_REQUIRED,
            sprintf(
                "Cette mission fait partie d'une prestation multi-salles : les interventions, le matériel et les demandes au catalogue s'ajoutent sur la mission de référence n° %d.",
                (int) $reference->getId(),
            ),
            [
                'missionId' => $mission->getId(),
                'referenceMissionId' => $reference->getId(),
                'prestationMissionIds' => PrestationGrouping::ids($group),
            ],
        );
    }

    private static function attachesToExistingEntry(Mission $mission, ?int $interventionId, ?int $draftId): bool
    {
        if ($interventionId !== null) {
            foreach ($mission->getInterventions() as $intervention) {
                if ($intervention->getId() === $interventionId) {
                    return true;
                }
            }
        }
        if ($draftId !== null) {
            foreach ($mission->getMissionInterventionDrafts() as $draft) {
                if ($draft->getId() === $draftId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Ce que le client doit savoir pour n'offrir l'ajout que là où il est accepté (null : mission seule).
     *
     * @return array{size: int, missionIds: list<int>, referenceMissionId: int, acceptsNewContent: bool}|null
     */
    public function describe(Mission $mission): ?array
    {
        $group = $this->groups->groupOf($mission);
        if (count($group) < 2) {
            return null;
        }
        $reference = PrestationReference::of($group);

        return [
            'size' => count($group),
            'missionIds' => PrestationGrouping::ids($group),
            'referenceMissionId' => (int) $reference->getId(),
            'acceptsNewContent' => $reference->getId() === $mission->getId(),
        ];
    }
}
