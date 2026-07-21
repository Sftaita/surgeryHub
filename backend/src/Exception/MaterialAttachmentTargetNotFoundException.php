<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * EPIC Revue instrumentiste, Lot 3, commit 4 — MaterialAttachmentResolver::resolve() ne
 * trouve pas la cible demandée (missionInterventionId ou interventionDraftId), soit
 * parce qu'elle n'existe pas, soit parce qu'elle appartient à une autre mission — les
 * deux cas produisent la même réponse 404 (même convention que le comportement déjà
 * établi par MaterialLineController/MaterialItemRequestService avant ce lot, jamais de
 * distinction qui révélerait l'existence d'une ressource hors périmètre).
 * Mappée à error.code = 'MATERIAL_ATTACHMENT_TARGET_NOT_FOUND' par ApiExceptionSubscriber.
 */
class MaterialAttachmentTargetNotFoundException extends NotFoundHttpException
{
}
