<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * D-171 — « Terminer et valider » (manager). `comment` : même règle que
 * MissionEncodingCompleteRequest (justification d'un bloc sans matériel, vérifiée par le
 * workflow). `expectedRevision` : empreinte de l'encodage lu (`encodingRevision` du détail de
 * mission), obligatoire — 428 sinon, 409 si l'encodage a changé. `takeoverConfirmed` : reprise
 * explicitement confirmée, exigée quand une instrumentiste est affectée (422 sinon).
 */
class MissionEncodingCompleteAndValidateRequest
{
    #[Assert\Type('string')]
    public ?string $comment = null;

    #[Assert\Type('string')]
    public ?string $expectedRevision = null;

    #[Assert\Type('bool')]
    public bool $takeoverConfirmed = false;
}
