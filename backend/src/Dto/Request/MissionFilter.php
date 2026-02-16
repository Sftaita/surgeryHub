<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

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
     * Alias utilisés par certains services (ex: MissionService::list)
     * On les expose comme DateTimeImmutable pour éviter ambiguïtés.
     */
    public ?\DateTimeImmutable $from = null;
    public ?\DateTimeImmutable $to = null;

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

        // Support optionnel: clients qui enverraient from/to directement
        $from = isset($q['from']) ? trim((string) $q['from']) : null;
        $to = isset($q['to']) ? trim((string) $q['to']) : null;

        // Construire from/to en priorité depuis query explicit, sinon depuis periodStart/periodEnd
        $dto->from = self::parseDateToDateTimeImmutable($from ?: $dto->periodStart, false);
        $dto->to = self::parseDateToDateTimeImmutable($to ?: $dto->periodEnd, true);

        $dto->page = isset($q['page']) ? max(1, (int) $q['page']) : 1;
        $dto->limit = isset($q['limit']) ? min(100, max(1, (int) $q['limit'])) : 20;

        return $dto;
    }

    /**
     * Parse une date YYYY-MM-DD vers DateTimeImmutable.
     * - endOfDay=false : 00:00:00
     * - endOfDay=true  : 23:59:59
     */
    private static function parseDateToDateTimeImmutable(?string $date, bool $endOfDay): ?\DateTimeImmutable
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        // strict format YYYY-MM-DD
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ($endOfDay ? ' 23:59:59' : ' 00:00:00'));
        if ($dt instanceof \DateTimeImmutable) {
            return $dt;
        }

        // fallback contrôlé : on tente un parse ISO si un client envoie un datetime
        try {
            $parsed = new \DateTimeImmutable($date);
            if ($endOfDay) {
                return $parsed->setTime(23, 59, 59);
            }
            return $parsed->setTime(0, 0, 0);
        } catch (\Throwable) {
            // pas de fallback silencieux -> on retourne null, la validation/usage en aval décidera
            return null;
        }
    }
}
