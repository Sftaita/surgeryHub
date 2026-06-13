<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class InstrumentistMissionSyncRequest
{
    /**
     * Horodatage ISO 8601 du dernier sync connu par le client.
     * Absent => le client n'a encore rien synchronisé (premier appel).
     */
    public ?string $since = null;

    public bool $sinceProvided = false;

    public ?\DateTimeImmutable $sinceParsed = null;

    /**
     * @param array<string,mixed> $q
     */
    public static function fromQuery(array $q): self
    {
        $dto = new self();

        $raw = isset($q['since']) ? trim((string) $q['since']) : null;
        $dto->since = ($raw === '' || $raw === null) ? null : $raw;
        $dto->sinceProvided = $dto->since !== null;

        if ($dto->sinceProvided) {
            try {
                $dto->sinceParsed = new \DateTimeImmutable($dto->since);
            } catch (\Throwable) {
                $dto->sinceParsed = null;
            }
        }

        return $dto;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->sinceProvided && $this->sinceParsed === null) {
            $context
                ->buildViolation('Invalid since datetime format (ISO 8601 expected)')
                ->atPath('since')
                ->addViolation();
        }
    }
}
