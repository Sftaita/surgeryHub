<?php

namespace App\Service;

use App\Entity\Absence;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningSlot;
use App\Entity\PlanningTemplate;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\PlanningTemplateType;
use App\Enum\SchedulePrecision;
use Doctrine\ORM\EntityManagerInterface;

class PlanningGeneratorService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlanningScoreService $scoreService,
    ) {}

    /**
     * Preview: returns array of preview lines WITHOUT persisting anything.
     *
     * @return array<int, array{
     *   date: string,
     *   slotId: int,
     *   surgeonId: int,
     *   surgeonName: string,
     *   missionType: string,
     *   startTime: string,
     *   endTime: string,
     *   siteId: int|null,
     *   siteName: string|null,
     *   instrumentistId: int|null,
     *   instrumentistName: string|null,
     *   status: string,
     *   existingMissionId: int|null
     * }>
     */
    public function preview(string $from, string $to, ?int $siteId, ?int $surgeonId): array
    {
        $start = new \DateTimeImmutable($from);
        $end   = new \DateTimeImmutable($to);

        $lines = [];
        $current = $start;

        while ($current <= $end) {
            $isoWeek = (int) $current->format('W');
            $weekType = ($isoWeek % 2 === 0) ? PlanningTemplateType::PAIR : PlanningTemplateType::IMPAIR;
            $isoDay   = (int) $current->format('N'); // 1=Monday ... 7=Sunday

            // Query active templates matching week type and site
            $qb = $this->em->createQueryBuilder()
                ->select('t', 's')
                ->from(PlanningTemplate::class, 't')
                ->leftJoin('t.slots', 's')
                ->where('t.type = :weekType')
                ->andWhere('t.dateStart <= :day')
                ->andWhere('(t.dateEnd IS NULL OR t.dateEnd >= :day)')
                ->setParameter('weekType', $weekType)
                ->setParameter('day', $current->format('Y-m-d'));

            if ($siteId !== null) {
                $qb->andWhere('(t.site = :siteId OR t.site IS NULL)')
                   ->setParameter('siteId', $siteId);
            }

            /** @var PlanningTemplate[] $templates */
            $templates = $qb->getQuery()->getResult();

            foreach ($templates as $template) {
                foreach ($template->getSlots() as $slot) {
                    if ($slot->getDayOfWeek() !== $isoDay) {
                        continue;
                    }

                    if ($surgeonId !== null && $slot->getSurgeon()?->getId() !== $surgeonId) {
                        continue;
                    }

                    $effectiveSite = $slot->getSite() ?? $template->getSite();

                    $surgeon     = $slot->getSurgeon();
                    $surgeonName = $this->displayName($surgeon);
                    $instrumentist = $slot->getInstrumentist();

                    // Check surgeon absence
                    $surgeonAbsent = $this->isAbsent($surgeon, $current);
                    if ($surgeonAbsent) {
                        // Skip — no point generating a mission if surgeon is absent
                        $lines[] = $this->buildLine(
                            $current, $slot, $effectiveSite, $surgeonName, $instrumentist, 'SKIPPED', null
                        );
                        continue;
                    }

                    // Check instrumentist absence
                    $instrumentistAbsent = $instrumentist !== null && $this->isAbsent($instrumentist, $current);

                    // Look for existing mission
                    $existingMission = $this->findExistingMission($surgeon, $effectiveSite, $current, $slot->getStartTime());

                    $status = 'UNCOVERED';
                    if ($existingMission !== null) {
                        $existingInstrumentist = $existingMission->getInstrumentist();
                        if ($existingInstrumentist === null && $instrumentist === null) {
                            $status = 'COVERED';
                        } elseif ($existingInstrumentist?->getId() === $instrumentist?->getId()) {
                            $status = 'COVERED';
                        } else {
                            $status = 'MODIFIED';
                        }
                    } else {
                        if ($instrumentist !== null && !$instrumentistAbsent) {
                            // Check instrumentist conflict
                            $hasConflict = $this->hasInstrumentistConflict($instrumentist, $current, $slot->getStartTime(), $slot->getEndTime());
                            if ($hasConflict) {
                                $status = 'CONFLICT';
                            } else {
                                $status = 'COVERED';
                            }
                        } else {
                            $status = 'UNCOVERED';
                        }
                    }

                    $lines[] = $this->buildLine(
                        $current, $slot, $effectiveSite, $surgeonName,
                        $instrumentistAbsent ? null : $instrumentist,
                        $status,
                        $existingMission?->getId()
                    );
                }
            }

            $current = $current->modify('+1 day');
        }

        return $lines;
    }

    /**
     * Generate: actually create/update missions based on preview data.
     *
     * @return array{created: int, updated: int, skipped: int}
     */
    public function generate(string $from, string $to, ?int $siteId, ?int $surgeonId): array
    {
        $lines = $this->preview($from, $to, $siteId, $surgeonId);

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($lines as $line) {
            if ($line['status'] === 'SKIPPED') {
                $skipped++;
                continue;
            }

            if ($line['existingMissionId'] !== null && $line['status'] === 'MODIFIED') {
                // Update existing mission's instrumentist
                $mission = $this->em->find(Mission::class, $line['existingMissionId']);
                if ($mission !== null) {
                    $newInstrumentist = $line['instrumentistId'] !== null
                        ? $this->em->find(User::class, $line['instrumentistId'])
                        : null;
                    $mission->setInstrumentist($newInstrumentist);
                    $updated++;
                }
                continue;
            }

            if ($line['existingMissionId'] !== null) {
                // Already covered — skip
                $skipped++;
                continue;
            }

            // Create new mission
            $slot = $this->em->find(PlanningSlot::class, $line['slotId']);
            if ($slot === null) {
                $skipped++;
                continue;
            }

            $surgeon = $this->em->find(User::class, $line['surgeonId']);
            if ($surgeon === null) {
                $skipped++;
                continue;
            }

            $site = $line['siteId'] !== null ? $this->em->find(Hospital::class, $line['siteId']) : null;
            if ($site === null) {
                $skipped++;
                continue;
            }

            $instrumentist = $line['instrumentistId'] !== null
                ? $this->em->find(User::class, $line['instrumentistId'])
                : null;

            $day = new \DateTimeImmutable($line['date']);
            $startTime = $slot->getStartTime();
            $endTime   = $slot->getEndTime();

            $startAt = $day->setTime(
                (int) $startTime->format('H'),
                (int) $startTime->format('i'),
                (int) $startTime->format('s')
            );
            $endAt = $day->setTime(
                (int) $endTime->format('H'),
                (int) $endTime->format('i'),
                (int) $endTime->format('s')
            );

            // Determine createdBy: use deployedBy from context — not available here,
            // so we use the surgeon as a fallback proxy (will be overridden in controller)
            $mission = new Mission();
            $mission->setStatus(MissionStatus::DRAFT);
            $mission->setType($slot->getMissionType());
            $mission->setSurgeon($surgeon);
            $mission->setInstrumentist($instrumentist);
            $mission->setSite($site);
            $mission->setStartAt($startAt);
            $mission->setEndAt($endAt);
            $mission->setSchedulePrecision(SchedulePrecision::EXACT);
            $mission->setCreatedBy($surgeon); // placeholder — caller should override

            $this->em->persist($mission);
            $created++;
        }

        $this->em->flush();

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function isAbsent(User $user, \DateTimeImmutable $day): bool
    {
        $result = $this->em->createQuery(
            'SELECT COUNT(a.id) FROM App\Entity\Absence a
             WHERE a.user = :user
               AND a.dateStart <= :day
               AND a.dateEnd >= :day'
        )
            ->setParameter('user', $user)
            ->setParameter('day', $day->format('Y-m-d'))
            ->getSingleScalarResult();

        return $result > 0;
    }

    private function findExistingMission(User $surgeon, ?Hospital $site, \DateTimeImmutable $day, \DateTimeImmutable $slotStartTime): ?Mission
    {
        if ($site === null) {
            return null;
        }

        $dayStart = $day->setTime(0, 0, 0);
        $dayEnd   = $day->setTime(23, 59, 59);

        $slotStartMinutes = (int) $slotStartTime->format('H') * 60 + (int) $slotStartTime->format('i');

        /** @var Mission[] $missions */
        $missions = $this->em->createQuery(
            'SELECT m FROM App\Entity\Mission m
             WHERE m.surgeon = :surgeon
               AND m.site = :site
               AND m.startAt >= :dayStart
               AND m.startAt <= :dayEnd
               AND m.status NOT IN (:excluded)'
        )
            ->setParameter('surgeon', $surgeon)
            ->setParameter('site', $site)
            ->setParameter('dayStart', $dayStart)
            ->setParameter('dayEnd', $dayEnd)
            ->setParameter('excluded', [MissionStatus::REJECTED])
            ->getResult();

        foreach ($missions as $mission) {
            $mStartMinutes = (int) $mission->getStartAt()->format('H') * 60 + (int) $mission->getStartAt()->format('i');
            if (abs($mStartMinutes - $slotStartMinutes) <= 30) {
                return $mission;
            }
        }

        return null;
    }

    private function hasInstrumentistConflict(User $instrumentist, \DateTimeImmutable $day, \DateTimeImmutable $slotStart, \DateTimeImmutable $slotEnd): bool
    {
        $startAt = $day->setTime(
            (int) $slotStart->format('H'),
            (int) $slotStart->format('i'),
            (int) $slotStart->format('s')
        );
        $endAt = $day->setTime(
            (int) $slotEnd->format('H'),
            (int) $slotEnd->format('i'),
            (int) $slotEnd->format('s')
        );

        $count = (int) $this->em->createQuery(
            'SELECT COUNT(m.id) FROM App\Entity\Mission m
             WHERE m.instrumentist = :user
               AND m.startAt < :end
               AND m.endAt > :start
               AND m.status NOT IN (:excluded)'
        )
            ->setParameter('user', $instrumentist)
            ->setParameter('start', $startAt)
            ->setParameter('end', $endAt)
            ->setParameter('excluded', [MissionStatus::REJECTED, MissionStatus::DRAFT])
            ->getSingleScalarResult();

        return $count > 0;
    }

    private function displayName(?User $user): string
    {
        if ($user === null) {
            return '';
        }
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }

    /**
     * @return array{
     *   date: string,
     *   slotId: int,
     *   surgeonId: int,
     *   surgeonName: string,
     *   missionType: string,
     *   startTime: string,
     *   endTime: string,
     *   siteId: int|null,
     *   siteName: string|null,
     *   instrumentistId: int|null,
     *   instrumentistName: string|null,
     *   status: string,
     *   existingMissionId: int|null
     * }
     */
    private function buildLine(
        \DateTimeImmutable $day,
        PlanningSlot $slot,
        ?Hospital $effectiveSite,
        string $surgeonName,
        ?User $instrumentist,
        string $status,
        ?int $existingMissionId
    ): array {
        return [
            'date'              => $day->format('Y-m-d'),
            'slotId'            => $slot->getId(),
            'surgeonId'         => $slot->getSurgeon()?->getId(),
            'surgeonName'       => $surgeonName,
            'missionType'       => $slot->getMissionType()->value,
            'startTime'         => $slot->getStartTime()->format('H:i'),
            'endTime'           => $slot->getEndTime()->format('H:i'),
            'siteId'            => $effectiveSite?->getId(),
            'siteName'          => $effectiveSite?->getName(),
            'instrumentistId'   => $instrumentist?->getId(),
            'instrumentistName' => $this->displayName($instrumentist),
            'status'            => $status,
            'existingMissionId' => $existingMissionId,
        ];
    }
}
