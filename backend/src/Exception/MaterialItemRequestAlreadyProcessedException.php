<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * MaterialItemRequestService::resolve()/ignore() relit la demande (sous verrou pessimiste,
 * après refresh()) et constate qu'elle n'est plus PENDING — deuxième tentative, tentative
 * concurrente perdant la course sous le verrou, ou état terminal déjà atteint. Même
 * principe que DraftAlreadyResolvedException pour InterventionTypeRequest.
 * Mappée à error.code = 'MATERIAL_ITEM_REQUEST_ALREADY_PROCESSED' (409) par ApiExceptionSubscriber.
 */
class MaterialItemRequestAlreadyProcessedException extends ConflictHttpException
{
}
