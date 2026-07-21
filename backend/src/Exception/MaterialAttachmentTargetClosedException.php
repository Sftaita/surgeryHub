<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * EPIC Revue instrumentiste, Lot 3, commit 4 — MaterialAttachmentResolver::resolve()
 * appelé avec un interventionDraftId dont le statut est KEPT_AS_HISTORY : fermé
 * définitivement, aucune cible de redirection réelle n'existe (contrairement à
 * CONVERTED/MATERIAL_REASSIGNED). Seul cas encore bloquant du modèle (revue de
 * conception) — tous les autres statuts terminaux redirigent silencieusement.
 * Mappée à error.code = 'MATERIAL_ATTACHMENT_TARGET_CLOSED' (409) par ApiExceptionSubscriber.
 */
class MaterialAttachmentTargetClosedException extends ConflictHttpException
{
}
