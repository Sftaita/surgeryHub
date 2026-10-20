<?php

namespace App\Absence;

use App\Entity\Absence;
use App\Entity\Mission;
use App\Entity\PlanningOccurrenceException;
use App\Entity\ShiftPeriodConfig;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\AbsenceImpactConsequence;
use App\Enum\AbsenceImpactLevel;
use App\Enum\MissionStatus;
use App\Enum\OccurrenceExceptionSource;
use App\Enum\OccurrenceExceptionType;
use App\Service\PlanningGeneratorServiceV2;
use App\Service\AbsenceMissionProtectionPolicy;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Clock;

/**
 * H4 (D-130) — THE single place that answers, for one absence: which occurrences are
 * impacted, at which level (N1 usual schedule / N2 DRAFT / N3 published), who must be
 * informed, and which business consequence applies. Read-only: it never mutates anything
 * (the existing services apply the consequences) and never notifies.
 *
 * Priority for one occurrence — the most concrete state wins, the template never
 * overrides an existing Mission:
 *   1. a Mission exists for the slot (any status) → it is the truth: N3 if it is not a
 *      DRAFT, N2 if it is, and the people are the Mission's own (a DRAFT that put Sophie
 *      instead of the usual Françoise informs Sophie, never Françoise);
 *   2. otherwise the SurgeonSchedulePost occurrence → N1, with its exceptions applied
 *      (CANCELLED/MOVED: the occurrence does not take place → no impact;
 *      INSTRUMENTIST_OVERRIDE: the override is the planned person; TIME_OVERRIDE: its hours).
 * "A Mission exists for the slot" = same surgeon, same site, overlapping hours on that day
 * (any status — a cancelled or declared mission is still the concrete fate of the slot).
 * Two posts of one surgeon the same day (morning / afternoon) are therefore resolved
 * independently.
 *
 * Query budget is fixed (≤ 7), whatever the length of the absence — never per occurrence.
 */
class AbsenceImpactResolver
{
    /** Missions an absence can still concern (terminal / out-of-band ones excluded). */
    private const IMPACTABLE_STATUSES = [
        MissionStatus::DRAFT, MissionStatus::OPEN, MissionStatus::ASSIGNED,
        MissionStatus::SUBMITTED, MissionStatus::VALIDATED, MissionStatus::IN_PROGRESS,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlanningGeneratorServiceV2 $generator,
    ) {
    }

    /** @return list<AbsenceImpact> */
    public function resolve(Absence $absence): array
    {
        $user = $absence->getUser();
        if ($user === null || $absence->getId() === null) {
            return [];
        }

        return $this->resolveRange($user, $absence->getId(), $absence->getDateStart(), $absence->getDateEnd());
    }

    /**
     * Same resolution for an arbitrary range of the same absence — used to diff the range
     * before and after an update (what is newly impacted / no longer impacted).
     *
     * @return list<AbsenceImpact>
     */
    public function resolveRange(User $absent, int $absenceId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $role = self::roleOf($absent);
        if ($role === null) {
            return [];
        }

        $from = $from->setTime(0, 0, 0);
        $to   = $to->setTime(0, 0, 0);

        return [
            ...$this->missionImpacts($absent, $absenceId, $role, $from, $to),
            ...$this->usualScheduleImpacts($absent, $absenceId, $role, $from, $to),
        ];
    }

    // ── N2 / N3 — concrete Missions ───────────────────────────────────────────

    /** @return list<AbsenceImpact> */
    private function missionImpacts(User $absent, int $absenceId, string $role, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var Mission[] $missions */
        $missions = $this->em->createQuery(sprintf(
            'SELECT m FROM App\Entity\Mission m
             WHERE m.%s = :absent AND m.startAt <= :windowEnd AND m.endAt >= :windowStart AND m.status IN (:statuses)
             ORDER BY m.startAt ASC',
            $role === AbsenceImpact::ROLE_SURGEON ? 'surgeon' : 'instrumentist',
        ))
            ->setParameter('absent', $absent)
            ->setParameter('windowStart', $from, Types::DATETIME_IMMUTABLE)
            ->setParameter('windowEnd', $to->setTime(23, 59, 59), Types::DATETIME_IMMUTABLE)
            ->setParameter('statuses', self::IMPACTABLE_STATUSES)
            ->getResult();

        $now = Clock::get()->now();
        $impacts = [];
        foreach ($missions as $m) {
            $status = $m->getStatus();
            $level  = $status === MissionStatus::DRAFT ? AbsenceImpactLevel::DRAFT : AbsenceImpactLevel::PUBLISHED;
            $consequence = match (true) {
                $level === AbsenceImpactLevel::DRAFT => $role === AbsenceImpact::ROLE_SURGEON
                    ? AbsenceImpactConsequence::DRAFT_TO_NEUTRALIZE : AbsenceImpactConsequence::DRAFT_TO_COVER,
                // D-158 — a mission already started or with encoded activity is never cancelled
                // automatically: it falls to the manager (default arm).
                $role === AbsenceImpact::ROLE_SURGEON && AbsenceMissionProtectionPolicy::canAutoCancel($m, $now)
                    => AbsenceImpactConsequence::MISSION_CANCELLED,
                $role === AbsenceImpact::ROLE_INSTRUMENTIST && AbsenceMissionProtectionPolicy::canAutoRelease($m, $now)
                    => AbsenceImpactConsequence::MISSION_OPENED,
                default => AbsenceImpactConsequence::MANAGER_DECISION,
            };

            $impacts[] = new AbsenceImpact(
                absenceId: $absenceId,
                absentUserId: (int) $absent->getId(),
                absentRole: $role,
                date: $m->getStartAt()->format('Y-m-d'),
                level: $level,
                consequence: $consequence,
                missionId: $m->getId(),
                missionStatus: $status->value,
                postId: null,
                siteId: $m->getSite()?->getId(),
                siteName: $m->getSite()?->getName(),
                surgeonId: $m->getSurgeon()?->getId(),
                surgeonName: self::name($m->getSurgeon()),
                instrumentistId: $m->getInstrumentist()?->getId(),
                instrumentistName: self::name($m->getInstrumentist()),
                startTime: $m->getStartAt()->format('H:i'),
                endTime: $m->getEndAt()?->format('H:i'),
            );
        }

        return $impacts;
    }

    // ── N1 — habitual organisation, no Mission for the slot ──────────────────

    /** @return list<AbsenceImpact> */
    private function usualScheduleImpacts(User $absent, int $absenceId, string $role, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $isSurgeon = $role === AbsenceImpact::ROLE_SURGEON;

        // Q — posts where the absent person is the surgeon, or the usual instrumentist.
        /** @var SurgeonSchedulePost[] $posts */
        $posts = $this->em->createQuery(sprintf(
            'SELECT p FROM App\Entity\SurgeonSchedulePost p
             WHERE p.%s = :absent AND p.active = true
               AND p.startDate <= :windowEnd AND (p.endDate IS NULL OR p.endDate >= :windowStart)',
            $isSurgeon ? 'surgeon' : 'instrumentist',
        ))
            ->setParameter('absent', $absent)
            ->setParameter('windowStart', $from, Types::DATE_IMMUTABLE)
            ->setParameter('windowEnd', $to, Types::DATE_IMMUTABLE)
            ->getResult();

        // Q — occurrences of OTHER posts where an INSTRUMENTIST_OVERRIDE puts the absent
        // instrumentist (she is the planned person there, not the template's).
        $overridesOnOthers = [];
        if (!$isSurgeon) {
            /** @var PlanningOccurrenceException[] $rows */
            $rows = $this->em->createQuery(
                'SELECT e, p FROM App\Entity\PlanningOccurrenceException e JOIN e.post p
                 WHERE e.type = :override AND e.overrideInstrumentist = :absent AND p.active = true
                   AND e.occurrenceDate >= :windowStart AND e.occurrenceDate <= :windowEnd'
            )
                ->setParameter('override', OccurrenceExceptionType::INSTRUMENTIST_OVERRIDE)
                ->setParameter('absent', $absent)
                ->setParameter('windowStart', $from, Types::DATE_IMMUTABLE)
                ->setParameter('windowEnd', $to, Types::DATE_IMMUTABLE)
                ->getResult();
            foreach ($rows as $e) {
                $overridesOnOthers[] = $e;
                $posts[] = $e->getPost();
            }
        }
        $posts = array_values(array_filter(
            array_combine(array_map(static fn (SurgeonSchedulePost $p) => (int) $p->getId(), $posts), $posts) ?: [],
        ));
        if ($posts === []) {
            return [];
        }

        // Q — every exception of these posts in the window, keyed post|date.
        $exceptions = [];
        foreach ($this->em->createQuery(
            'SELECT e FROM App\Entity\PlanningOccurrenceException e
             WHERE e.post IN (:posts) AND e.occurrenceDate >= :windowStart AND e.occurrenceDate <= :windowEnd'
        )
            ->setParameter('posts', $posts)
            ->setParameter('windowStart', $from, Types::DATE_IMMUTABLE)
            ->setParameter('windowEnd', $to, Types::DATE_IMMUTABLE)
            ->getResult() as $e) {
            $exceptions[$e->getPost()->getId() . '|' . $e->getOccurrenceDate()->format('Y-m-d')] = $e;
        }
        $overrideKeys = array_flip(array_map(
            static fn (PlanningOccurrenceException $e) => $e->getPost()->getId() . '|' . $e->getOccurrenceDate()->format('Y-m-d'),
            $overridesOnOthers,
        ));

        // Q — shift hours of the sites involved.
        $sites = [];
        foreach ($posts as $p) {
            if ($p->getSite() !== null) {
                $sites[$p->getSite()->getId()] = $p->getSite();
            }
        }
        $shifts = [];
        if ($sites !== []) {
            foreach ($this->em->getRepository(ShiftPeriodConfig::class)->findBy(['site' => array_values($sites), 'active' => true]) as $c) {
                $shifts[$c->getSite()->getId() . '|' . $c->getPeriod()->value] = [$c->getStartTime()->format('H:i'), $c->getEndTime()->format('H:i')];
            }
        }

        // Q — every Mission of the posts' surgeons in the window (any status): a slot with a
        // Mission is resolved by the Mission branch (or by nothing if it isn't this person's).
        $surgeons = [];
        foreach ($posts as $p) {
            if ($p->getSurgeon() !== null) {
                $surgeons[$p->getSurgeon()->getId()] = $p->getSurgeon();
            }
        }
        $missionsBySlot = [];
        if ($surgeons !== []) {
            foreach ($this->em->createQuery(
                'SELECT m.id, IDENTITY(m.surgeon) AS surgeonId, IDENTITY(m.site) AS siteId, m.startAt, m.endAt
                 FROM App\Entity\Mission m
                 WHERE m.surgeon IN (:surgeons) AND m.startAt <= :windowEnd AND m.endAt >= :windowStart'
            )
                ->setParameter('surgeons', array_values($surgeons))
                ->setParameter('windowStart', $from, Types::DATETIME_IMMUTABLE)
                ->setParameter('windowEnd', $to->setTime(23, 59, 59), Types::DATETIME_IMMUTABLE)
                ->getArrayResult() as $row) {
                $missionsBySlot[$row['surgeonId'] . '|' . $row['siteId'] . '|' . $row['startAt']->format('Y-m-d')][] = [
                    $row['startAt']->format('H:i'), $row['endAt']->format('H:i'),
                ];
            }
        }

        $impacts = [];
        foreach ($posts as $post) {
            $surgeon = $post->getSurgeon();
            $site    = $post->getSite();
            $isOverridePost = !$isSurgeon && $post->getInstrumentist()?->getId() !== $absent->getId();

            foreach ($this->generator->theoreticalOccurrenceDates($post, $from, $to) as $date) {
                $key = $post->getId() . '|' . $date->format('Y-m-d');
                if ($isOverridePost && !isset($overrideKeys[$key])) {
                    continue; // she is only planned on this post through an override of THIS date
                }

                $exception     = $exceptions[$key] ?? null;
                $instrumentist = $post->getInstrumentist();
                [$start, $end] = $shifts[($site?->getId() ?? 0) . '|' . $post->getPeriod()->value] ?? [null, null];

                if ($exception !== null) {
                    switch ($exception->getType()) {
                        case OccurrenceExceptionType::CANCELLED:
                            // Neutralised by THIS absence (an earlier pass of it) → still its impact;
                            // cancelled for any other reason → the occurrence simply does not take place.
                            if (!($exception->getSource() === OccurrenceExceptionSource::SURGEON_ABSENCE
                                && $exception->getSourceAbsence()?->getId() === $absenceId)) {
                                continue 2;
                            }
                            break;
                        case OccurrenceExceptionType::MOVED:
                            continue 2; // takes place on another date: not this absence's occurrence
                        case OccurrenceExceptionType::INSTRUMENTIST_OVERRIDE:
                            $instrumentist = $exception->getOverrideInstrumentist();
                            if (!$isSurgeon && $instrumentist?->getId() !== $absent->getId()) {
                                continue 2; // someone else replaces her on this date
                            }
                            break;
                        case OccurrenceExceptionType::TIME_OVERRIDE:
                            $start = $exception->getOverrideStartTime()?->format('H:i') ?? $start;
                            $end   = $exception->getOverrideEndTime()?->format('H:i') ?? $end;
                            break;
                    }
                }

                if ($this->slotHasMission($missionsBySlot, $surgeon?->getId(), $site?->getId(), $date, $start, $end)) {
                    continue;
                }

                $impacts[] = new AbsenceImpact(
                    absenceId: $absenceId,
                    absentUserId: (int) $absent->getId(),
                    absentRole: $isSurgeon ? AbsenceImpact::ROLE_SURGEON : AbsenceImpact::ROLE_INSTRUMENTIST,
                    date: $date->format('Y-m-d'),
                    level: AbsenceImpactLevel::USUAL_SCHEDULE,
                    consequence: $isSurgeon ? AbsenceImpactConsequence::OCCURRENCE_NEUTRALIZED : AbsenceImpactConsequence::OCCURRENCE_TO_COVER,
                    missionId: null,
                    missionStatus: null,
                    postId: $post->getId(),
                    siteId: $site?->getId(),
                    siteName: $site?->getName(),
                    surgeonId: $surgeon?->getId(),
                    surgeonName: self::name($surgeon),
                    instrumentistId: $instrumentist?->getId(),
                    instrumentistName: self::name($instrumentist),
                    startTime: $start,
                    endTime: $end,
                );
            }
        }

        usort($impacts, static fn (AbsenceImpact $a, AbsenceImpact $b) => [$a->date, $a->startTime] <=> [$b->date, $b->startTime]);

        return $impacts;
    }

    /** @param array<string, list<array{0: string, 1: string}>> $missionsBySlot */
    private function slotHasMission(array $missionsBySlot, ?int $surgeonId, ?int $siteId, \DateTimeImmutable $date, ?string $start, ?string $end): bool
    {
        $slots = $missionsBySlot[$surgeonId . '|' . $siteId . '|' . $date->format('Y-m-d')] ?? [];
        if ($slots === []) {
            return false;
        }
        if ($start === null || $end === null) {
            return true; // hours unknown: any Mission that day for this surgeon and site counts
        }
        foreach ($slots as [$mStart, $mEnd]) {
            if ($mStart < $end && $mEnd > $start) {
                return true;
            }
        }

        return false;
    }

    public static function roleOf(User $user): ?string
    {
        $roles = $user->getRoles();

        return match (true) {
            in_array('ROLE_SURGEON', $roles, true)       => AbsenceImpact::ROLE_SURGEON,
            in_array('ROLE_INSTRUMENTIST', $roles, true) => AbsenceImpact::ROLE_INSTRUMENTIST,
            default                                       => null,
        };
    }

    private static function name(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));

        return $name !== '' ? $name : $user->getEmail();
    }
}
