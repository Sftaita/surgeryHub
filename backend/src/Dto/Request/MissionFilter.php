<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

class MissionFilter
{
    #[Assert\Date]
    public ?string $periodStart = null;

    #[Assert\Date]
    public ?string $periodEnd = null;

    #[Assert\Positive]
    public ?int $siteId = null;

    #[Assert\Type('string')]
    public ?string $status = null;

    #[Assert\Type('string')]
    public ?string $type = null;

    #[Assert\Type('boolean')]
    public ?bool $assignedToMe = null;

    #[Assert\Positive]
    public ?int $page = 1;

    #[Assert\Positive]
    public ?int $limit = 20;

    public static function fromQuery(array $q): self
    {
        $dto = new self();

        $dto->siteId = isset($q['siteId']) ? (int) $q['siteId'] : null;
        $dto->status = isset($q['status']) ? (string) $q['status'] : null;
        $dto->type = isset($q['type']) ? (string) $q['type'] : null;

        if (isset($q['assignedToMe'])) {
            $dto->assignedToMe = filter_var($q['assignedToMe'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        $dto->periodStart = isset($q['periodStart']) ? (string) $q['periodStart'] : null;
        $dto->periodEnd = isset($q['periodEnd']) ? (string) $q['periodEnd'] : null;

        $dto->page = isset($q['page']) ? max(1, (int) $q['page']) : 1;
        $dto->limit = isset($q['limit']) ? min(100, max(1, (int) $q['limit'])) : 20;

        return $dto;
    }
}
