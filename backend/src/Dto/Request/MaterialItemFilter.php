<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class MaterialItemFilter
{
    #[Assert\Length(max: 255)]
    public ?string $search = null;

    #[Assert\Length(max: 255)]
    public ?string $manufacturer = null;

    #[Assert\Length(max: 100)]
    public ?string $referenceCode = null;

    #[Assert\Type('bool')]
    public ?bool $active = null;

    #[Assert\Type('bool')]
    public ?bool $implantOnly = null;

    #[Assert\Positive]
    public ?int $page = 1;

    #[Assert\Positive]
    public ?int $limit = 50;

    /**
     * @param array<string,mixed> $q
     */
    public static function fromQuery(array $q): self
    {
        $dto = new self();

        $dto->search = isset($q['search']) ? trim((string) $q['search']) : null;
        $dto->manufacturer = isset($q['manufacturer']) ? trim((string) $q['manufacturer']) : null;
        $dto->referenceCode = isset($q['referenceCode']) ? trim((string) $q['referenceCode']) : null;

        if (array_key_exists('active', $q)) {
            $dto->active = filter_var($q['active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        if (array_key_exists('implantOnly', $q)) {
            $dto->implantOnly = filter_var($q['implantOnly'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        if (isset($q['page'])) {
            $dto->page = (int) $q['page'];
        }

        if (isset($q['limit'])) {
            $dto->limit = (int) $q['limit'];
        }

        // normalisation vide -> null
        foreach (['search', 'manufacturer', 'referenceCode'] as $k) {
            if ($dto->$k !== null && $dto->$k === '') {
                $dto->$k = null;
            }
        }

        return $dto;
    }
}
