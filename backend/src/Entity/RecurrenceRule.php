<?php

namespace App\Entity;

use App\Enum\RecurrenceFrequency;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Embeddable]
class RecurrenceRule
{
    #[ORM\Column(enumType: RecurrenceFrequency::class, length: 10)]
    #[Groups(['planning:read'])]
    private RecurrenceFrequency $frequency;

    /** Interval in units of frequency. 1 = every week/month, 2 = every-other. */
    #[ORM\Column(type: 'smallint')]
    #[Groups(['planning:read'])]
    private int $interval = 1;

    /** ISO weekdays (1=Monday..7=Sunday). Required for WEEKLY, ignored for MONTHLY. */
    #[ORM\Column(type: 'json')]
    #[Groups(['planning:read'])]
    private array $weekdays = [];

    /**
     * Reference date used to compute recurrence phase: an ISO week W is active iff
     * (isoWeekIndex(W) - isoWeekIndex(anchorDate)) % interval === 0. Generalizes
     * PAIR/IMPAIR (interval=2) and arbitrary one-week-on-N patterns.
     */
    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['planning:read'])]
    private \DateTimeImmutable $anchorDate;

    /** MONTHLY only: nth weekday of month (e.g. 1 = first occurrence of that weekday). Null = same day-of-month as post startDate. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Groups(['planning:read'])]
    private ?int $monthlyNthWeekday = null;

    public function getFrequency(): RecurrenceFrequency { return $this->frequency; }
    public function setFrequency(RecurrenceFrequency $frequency): static { $this->frequency = $frequency; return $this; }

    public function getInterval(): int { return $this->interval; }
    public function setInterval(int $interval): static { $this->interval = $interval; return $this; }

    public function getWeekdays(): array { return $this->weekdays; }
    public function setWeekdays(array $weekdays): static { $this->weekdays = $weekdays; return $this; }

    public function getAnchorDate(): \DateTimeImmutable { return $this->anchorDate; }
    public function setAnchorDate(\DateTimeImmutable $anchorDate): static { $this->anchorDate = $anchorDate; return $this; }

    public function getMonthlyNthWeekday(): ?int { return $this->monthlyNthWeekday; }
    public function setMonthlyNthWeekday(?int $monthlyNthWeekday): static { $this->monthlyNthWeekday = $monthlyNthWeekday; return $this; }
}
