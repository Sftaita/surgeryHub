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

    #[Assert\Type('boolean')]
    public ?bool $eligibleToMe = null;

    #[Assert\Positive]
    public ?int $page = 1;

    #[Assert\Positive]
    public ?int $limit = 20;

    public static function fromQuery(array $q): self
    {
        $dto = new self();

        $dto->siteId = isset($q['siteId']) ? (int) $q['siteId'] : null;

        // Important: on normalise les strings vides en null (robuste Postman / clients)
        $status = isset($q['status']) ? trim((string) $q['status']) : null;
        $dto->status = ($status === '') ? null : $status;

        $type = isset($q['type']) ? trim((string) $q['type']) : null;
        $dto->type = ($type === '') ? null : $type;

        if (isset($q['assignedToMe'])) {
            $dto->assignedToMe = filter_var($q['assignedToMe'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        if (isset($q['eligibleToMe'])) {
            $dto->eligibleToMe = filter_var($q['eligibleToMe'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        $periodStart = isset($q['periodStart']) ? trim((string) $q['periodStart']) : null;
        $dto->periodStart = ($periodStart === '') ? null : $periodStart;

        $periodEnd = isset($q['periodEnd']) ? trim((string) $q['periodEnd']) : null;
        $dto->periodEnd = ($periodEnd === '') ? null : $periodEnd;

        $dto->page = isset($q['page']) ? max(1, (int) $q['page']) : 1;
        $dto->limit = isset($q['limit']) ? min(100, max(1, (int) $q['limit'])) : 20;

        return $dto;
    }
}
