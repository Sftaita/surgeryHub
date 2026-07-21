<?php

namespace App\Exception;

/**
 * EPIC Revue instrumentiste, Lot 3 — MaterialLine/MaterialItemRequest avec
 * missionIntervention ET interventionDraft renseignés simultanément : état invalide qui
 * ne devrait jamais être atteignable via les services (invariant appliqué par
 * MissionInterventionDraftService — pas encore branché à ce stade), donc une
 * LogicException plutôt qu'une exception HTTP mappée : ceci signale un bug, pas une
 * erreur métier qu'un client pourrait légitimement déclencher. Si elle atteint malgré
 * tout le kernel HTTP, elle tombe correctement sur 500 (comportement par défaut,
 * jamais enregistrée dans ApiExceptionSubscriber).
 */
class ConflictingAttachmentTargetsException extends \LogicException
{
}
