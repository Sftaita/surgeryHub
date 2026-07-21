<?php

namespace App\Exception;

/**
 * EPIC Revue instrumentiste, Lot 3 — MissionInterventionDraft en statut CONVERTED ou
 * MATERIAL_REASSIGNED sans resolvedMissionIntervention renseigné : état invalide qui ne
 * devrait jamais être atteignable via MissionInterventionDraftService (ces deux statuts
 * ne sont écrits qu'en même temps que resolvedMissionIntervention, dans la même
 * transaction — pas encore branché à ce stade). LogicException, même raisonnement que
 * ConflictingAttachmentTargetsException : signale un bug, pas une erreur métier.
 */
class InvalidDraftResolutionStateException extends \LogicException
{
}
