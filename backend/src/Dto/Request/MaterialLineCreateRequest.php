<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

class MaterialLineCreateRequest
{
    #[Assert\NotBlank]
    #[Assert\Positive]
    public int $itemId;

    #[Assert\Positive]
    public ?int $missionInterventionId = null;

    /**
     * On accepte int|float|string pour ne pas forcer le frontend.
     * Normalisation à faire via ->getQuantityAsString().
     */
    #[Assert\NotNull]
    public int|float|string|null $quantity = null;

    #[Assert\Type('string')]
    public ?string $comment = null;

    /**
     * Retourne une string au format DECIMAL(10,2) (ex: "2.00").
     * - accepte "2", 2, 2.5, "2.5", "2,5"
     */
    public function getQuantityAsString(): ?string
    {
        if ($this->quantity === null) {
            return null;
        }

        // int / float
        if (is_int($this->quantity) || is_float($this->quantity)) {
            return number_format((float) $this->quantity, 2, '.', '');
        }

        // string
        $raw = trim($this->quantity);
        if ($raw === '') {
            return null;
        }

        // autoriser virgule décimale
        $raw = str_replace(',', '.', $raw);

        // sécuriser : doit être numérique
        if (!is_numeric($raw)) {
            return null;
        }

        return number_format((float) $raw, 2, '.', '');
    }
}
