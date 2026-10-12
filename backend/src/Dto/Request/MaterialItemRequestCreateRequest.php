<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

class MaterialItemRequestCreateRequest
{
    #[Assert\NotBlank(message: 'Le nom du matériel est obligatoire.')]
    #[Assert\Type('string')]
    #[Assert\Length(max: 255)]
    public ?string $label = null;

    #[Assert\Type('string')]
    #[Assert\Length(max: 255)]
    public ?string $referenceCode = null;

    #[Assert\Type('string')]
    public ?string $comment = null;

    #[Assert\Positive]
    public ?int $missionInterventionId = null;

    /**
     * EPIC Revue instrumentiste, Lot 3, commit 4 — alternative à missionInterventionId,
     * jamais les deux à la fois (voir MaterialAttachmentResolver).
     */
    #[Assert\Positive]
    public ?int $interventionDraftId = null;

    /**
     * D-146 bis — firme fabricante choisie parmi les firmes existantes (actives). Exactement
     * UNE des deux informations est exigée : firmId OU proposedFirmName (vérifié par
     * MaterialItemRequestService::create(), refus 422 FIRM_REQUIRED / FIRM_AMBIGUOUS).
     */
    #[Assert\Positive]
    public ?int $firmId = null;

    /** D-146 bis — « Ma firme n'est pas dans la liste » : nom libre, jamais une firme créée. */
    #[Assert\Type('string')]
    #[Assert\Length(max: 150)]
    public ?string $proposedFirmName = null;

    /**
     * D-146 bis — quantité utilisée, obligatoire et strictement positive (nombre ou chaîne
     * numérique, deux décimales au plus — même précision que MaterialLine::$quantity).
     */
    #[Assert\NotBlank(message: 'La quantité utilisée est obligatoire.')]
    public mixed $quantity = null;
}
