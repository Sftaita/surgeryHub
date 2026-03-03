<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class MissionFilter
{
    /**
     * Dates au format YYYY-MM-DD (legacy / API actuelle)
     */
    #[Assert\Date]
    public ?string $periodStart = null;

    #[Assert\Date]
    public ?string $periodEnd = null;

    /**
     * Nouveaux filtres calendrier (ISO 8601 datetime)
     * - from : inclus
     * - to   : exclu
     *
     * NB: on garde DateTimeImmutable pour éviter ambiguïtés.
     */
    public ?\DateTimeImmutable $from = null;
    public ?\DateTimeImmutable $to = null;

    /**
     * Flags pour distinguer "absent" vs "fourni mais invalide/vide"
     * (et éviter tout changement de comportement quand from/to non fournis)
     */
    public bool $fromProvided = false;
    public bool $toProvided = false;

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

    /**
     * @param array<string,mixed> $q
     */
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

        // Nouveaux filtres from/to (datetime ISO 8601) — ne pas dériver depuis periodStart/periodEnd
        $rawFrom = isset($q['from']) ? trim((string) $q['from']) : null;
        $rawTo = isset($q['to']) ? trim((string) $q['to']) : null;

        $dto->fromProvided = isset($q['from']) && $rawFrom !== null && $rawFrom !== '';
        $dto->toProvided = isset($q['to']) && $rawTo !== null && $rawTo !== '';

        $dto->from = $dto->fromProvided ? self::parseIsoDateTimeImmutable($rawFrom) : null;
        $dto->to = $dto->toProvided ? self::parseIsoDateTimeImmutable($rawTo) : null;

        $dto->page = isset($q['page']) ? max(1, (int) $q['page']) : 1;
        $dto->limit = isset($q['limit']) ? min(100, max(1, (int) $q['limit'])) : 20;

        return $dto;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        // from fourni mais non parsable => erreur claire
        if ($this->fromProvided && $this->from === null) {
            $context
                ->buildViolation('Invalid from datetime format (ISO 8601 expected)')
                ->atPath('from')
                ->addViolation();
        }

        // to fourni mais non parsable => erreur claire
        if ($this->toProvided && $this->to === null) {
            $context
                ->buildViolation('Invalid to datetime format (ISO 8601 expected)')
                ->atPath('to')
                ->addViolation();
        }

        // from < to si les deux sont fournis et valides
        if ($this->fromProvided && $this->toProvided && $this->from !== null && $this->to !== null) {
            if ($this->from >= $this->to) {
                $context
                    ->buildViolation('from must be strictly before to')
                    ->atPath('from')
                    ->addViolation();
            }
        }
    }

    private static function parseIsoDateTimeImmutable(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            // Supporte ISO 8601 (avec ou sans timezone, selon conventions existantes)
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}