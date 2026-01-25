<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class MaterialItemFilter
{
    #[Assert\Type('boolean')]
    public ?bool $active = true;

    #[Assert\Length(max: 255)]
    public ?string $manufacturer = null;

    #[Assert\Length(max: 100)]
    public ?string $referenceCode = null;

    #[Assert\Length(max: 255)]
    public ?string $search = null;

    #[Assert\Positive]
    public ?int $page = 1;

    #[Assert\Positive]
    public ?int $limit = 50;

    public static function fromQuery(array $q): self
    {
        $dto = new self();

        if (array_key_exists('active', $q)) {
            $dto->active = filter_var($q['active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        $dto->manufacturer = isset($q['manufacturer']) ? trim((string) $q['manufacturer']) : null;
        $dto->referenceCode = isset($q['referenceCode']) ? trim((string) $q['referenceCode']) : null;
        $dto->search = isset($q['search']) ? trim((string) $q['search']) : null;

        $dto->page = isset($q['page']) ? max(1, (int) $q['page']) : 1;
        $dto->limit = isset($q['limit']) ? min(100, max(1, (int) $q['limit'])) : 50;

        // normalisation
        $dto->manufacturer = $dto->manufacturer === '' ? null : $dto->manufacturer;
        $dto->referenceCode = $dto->referenceCode === '' ? null : $dto->referenceCode;
        $dto->search = $dto->search === '' ? null : $dto->search;

        return $dto;
    }
}
