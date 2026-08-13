<?php

namespace App\Service;

use App\Entity\Absence;
use App\Entity\SurgeonSchedulePost;
use App\Entity\ShiftPeriodConfig;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Message\InstrumentistAbsenceOccurrenceImpactMessage;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Symmetric counterpart to SurgeonAbsenceOccurrenceImpactService (Lot 3, D-103), which only
 * ever reacted to SURGEON absences. This service reacts to an INSTRUMENTIST absence by
 * finding SurgeonSchedulePost rows where this person is the configured default
 * `instrumentist`, computing theoretical occurrences inside the absence window that have NO
 * Mission generated yet, and notifying the surgeon(s) concerned — grouped, one recap per
 * surgeon per run.
 *
 * Deliberately does NOT create a PlanningOccurrenceException (unlike the surgeon-side
 * service): a surgeon absence means the occurrence itself won't happen (nobody to operate),
 * so it's genuinely neutralized ahead of generation. An instrumentist absence does NOT cancel
 * anything — the surgeon still operates; only the usual instrumentist won't be there. The
 * occurrence must generate normally, and PlanningGeneratorServiceV2's existing Lot 1 (D-101)
 * revalidation already guarantees the absent instrumentist is never silently reassigned at
 * generation time (falls back to another candidate, or UNCOVERED). This service is therefore
 * purely a heads-up notification, with no effect whatsoever on generation — nothing to
 * persist for correctness, so no new table/migration.
 *
 * Idempotency without persistence: instead of a stored "already notified" marker, each call
 * is given the absence's date range as it stood immediately before this change (null on
 * create). It diffs theoreticalOccurrenceDates() computed over the OLD range against the NEW
 * range: only the delta is ever communicated —
 *   - dates in NEW but not OLD  → "newly impacted" (grow)
 *   - dates in OLD but not NEW  → "no longer impacted" (shrink/restore)
 * An update that doesn't touch dateStart/dateEnd (e.g. only `reason` changed) yields an empty
 * delta both ways, so it never re-notifies — this is what avoids the spam a naive
 * recompute-the-whole-range-every-time approach would cause.
 *
 * A subsequent generate() naturally supersedes any of this once a real Mission exists —
 * hasExistingMission() below skips occurrences that already have one, deferring entirely to
 * the Mission-based path (AbsenceMissionReactionService) so the same occurrence never
 * produces both a "theoretical" and a "Mission" notification (§11/§14).
 */
class InstrumentistAbsenceOccurrenceImpactService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlanningGeneratorServiceV2 $generator,
        private readonly AuditService $auditService,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{newlyImpacted: int, noLongerImpacted: int} */
    public function onInstrumentistAbsenceCreated(Absence $absence, User $actor): array
    {
        return $this->react($absence, $actor, null, null);
    }

    /** @return array{newlyImpacted: int, noLongerImpacted: int} */
    public function onInstrumentistAbsenceUpdated(
        Absence $absence,
        User $actor,
        ?\DateTimeImmutable $previousDateStart,
        ?\DateTimeImmutable $previousDateEnd,
    ): array {
        return $this->react($absence, $actor, $previousDateStart, $previousDateEnd);
    }

    /** @return array{newlyImpacted: int, noLongerImpacted: int} */
    private function react(
        Absence $absence,
        User $actor,
        ?\DateTimeImmutable $previousDateStart,
        ?\DateTimeImmutable $previousDateEnd,
    ): array {
        $instrumentist = $absence->getUser();
        if ($instrumentist === null || !self::isInstrumentist($instrumentist)) {
            return ['newlyImpacted' => 0, 'noLongerImpacted' => 0];
        }

        $newStart = $absence->getDateStart();
        $newEnd   = $absence->getDateEnd();

        $posts = $this->loadActivePosts($instrumentist, $newStart, $newEnd, $previousDateStart, $previousDateEnd);
        if (empty($posts)) {
            return ['newlyImpacted' => 0, 'noLongerImpacted' => 0];
        }

        $newlyImpacted    = [];
        $noLongerImpacted = [];

        foreach ($posts as $post) {
            $newDates = $this->dateKeySet($this->generator->theoreticalOccurrenceDates($post, $newStart, $newEnd));
            $oldDates = ($previousDateStart !== null && $previousDateEnd !== null)
                ? $this->dateKeySet($this->generator->theoreticalOccurrenceDates($post, $previousDateStart, $previousDateEnd))
                : [];

            $shift = $this->shiftTimes($post);
            if ($shift === null) {
                $this->logger->warning('InstrumentistAbsenceOccurrenceImpact: no ShiftPeriodConfig for post — occurrence skipped', [
                    'postId' => $post->getId(),
                    'siteId' => $post->getSite()?->getId(),
                    'period' => $post->getPeriod()->value,
                ]);
                continue;
            }
            [$startTime, $endTime] = $shift;

            foreach (array_diff_key($newDates, $oldDates) as $dateKey => $date) {
                if ($this->hasExistingMission($post, $date)) {
                    // Already handled by AbsenceMissionReactionService — no double treatment.
                    continue;
                }

                $snapshot = $this->buildSnapshot($post, $date, $instrumentist, $startTime, $endTime);
                $this->auditService->recordGlobal(
                    $actor,
                    AuditEventType::PLANNING_OCCURRENCE_INSTRUMENTIST_ABSENCE_NOTICE,
                    array_merge($snapshot, ['absenceId' => $absence->getId(), 'direction' => 'NEWLY_IMPACTED']),
                );
                $newlyImpacted[] = $snapshot;
            }

            foreach (array_diff_key($oldDates, $newDates) as $dateKey => $date) {
                if ($this->hasExistingMission($post, $date)) {
                    continue;
                }

                $snapshot = $this->buildSnapshot($post, $date, $instrumentist, $startTime, $endTime);
                $this->auditService->recordGlobal(
                    $actor,
                    AuditEventType::PLANNING_OCCURRENCE_INSTRUMENTIST_ABSENCE_NOTICE,
                    array_merge($snapshot, ['absenceId' => $absence->getId(), 'direction' => 'NO_LONGER_IMPACTED']),
                );
                $noLongerImpacted[] = $snapshot;
            }
        }

        if (empty($newlyImpacted) && empty($noLongerImpacted)) {
            return ['newlyImpacted' => 0, 'noLongerImpacted' => 0];
        }

        $this->em->flush();

        $this->bus->dispatch(new InstrumentistAbsenceOccurrenceImpactMessage(
            absenceId: $absence->getId(),
            instrumentistId: $instrumentist->getId(),
            instrumentistName: self::displayName($instrumentist),
            dateStart: $newStart->format('Y-m-d'),
            dateEnd: $newEnd->format('Y-m-d'),
            actorId: $actor->getId(),
            newlyImpacted: $newlyImpacted,
            noLongerImpacted: $noLongerImpacted,
            occurredAt: new \DateTimeImmutable(),
        ));

        return ['newlyImpacted' => count($newlyImpacted), 'noLongerImpacted' => count($noLongerImpacted)];
    }

    private function buildSnapshot(SurgeonSchedulePost $post, \DateTimeImmutable $date, User $instrumentist, string $startTime, string $endTime): array
    {
        $surgeon = $post->getSurgeon();
        $site    = $post->getSite();

        return [
            'postId'            => $post->getId(),
            'occurrenceDate'    => $date->format('Y-m-d'),
            'siteId'            => $site?->getId(),
            'siteName'          => $site?->getName(),
            'surgeonId'         => $surgeon?->getId(),
            'surgeonName'       => $surgeon !== null ? self::displayName($surgeon) : null,
            'instrumentistId'   => $instrumentist->getId(),
            'instrumentistName' => self::displayName($instrumentist),
            'startTime'         => $startTime,
            'endTime'           => $endTime,
        ];
    }

    /** @return array<string, \DateTimeImmutable> keyed by 'Y-m-d' for cheap diffing */
    private function dateKeySet(array $dates): array
    {
        $set = [];
        foreach ($dates as $date) {
            $set[$date->format('Y-m-d')] = $date;
        }
        return $set;
    }

    /** @return SurgeonSchedulePost[] */
    private function loadActivePosts(
        User $instrumentist,
        \DateTimeImmutable $newStart,
        \DateTimeImmutable $newEnd,
        ?\DateTimeImmutable $previousDateStart,
        ?\DateTimeImmutable $previousDateEnd,
    ): array {
        // Window covers both the new AND the previous range so a shrink's "no longer
        // impacted" dates (which only fall inside the OLD range) are still found.
        $windowStart = $newStart;
        $windowEnd   = $newEnd;
        if ($previousDateStart !== null && $previousDateStart < $windowStart) {
            $windowStart = $previousDateStart;
        }
        if ($previousDateEnd !== null && $previousDateEnd > $windowEnd) {
            $windowEnd = $previousDateEnd;
        }

        return $this->em->createQuery(
            'SELECT p FROM App\Entity\SurgeonSchedulePost p
             WHERE p.instrumentist = :instrumentist
               AND p.active = true
               AND p.startDate <= :windowEnd
               AND (p.endDate IS NULL OR p.endDate >= :windowStart)'
        )
            ->setParameter('instrumentist', $instrumentist)
            ->setParameter('windowStart', $windowStart, Types::DATE_IMMUTABLE)
            ->setParameter('windowEnd', $windowEnd, Types::DATE_IMMUTABLE)
            ->getResult();
    }

    /** Any Mission at all for this exact post's surgeon+site+slot — regardless of status. */
    private function hasExistingMission(SurgeonSchedulePost $post, \DateTimeImmutable $date): bool
    {
        $dayStart = $date->setTime(0, 0, 0);
        $dayEnd   = $date->setTime(23, 59, 59);

        $count = $this->em->createQuery(
            'SELECT COUNT(m.id) FROM App\Entity\Mission m
             WHERE m.surgeon = :surgeon
               AND m.site = :site
               AND m.startAt >= :dayStart
               AND m.startAt <= :dayEnd'
        )
            ->setParameter('surgeon', $post->getSurgeon())
            ->setParameter('site', $post->getSite())
            ->setParameter('dayStart', $dayStart, Types::DATETIME_IMMUTABLE)
            ->setParameter('dayEnd', $dayEnd, Types::DATETIME_IMMUTABLE)
            ->getSingleScalarResult();

        return ((int) $count) > 0;
    }

    /** @return array{0: string, 1: string}|null [startTime "H:i", endTime "H:i"] */
    private function shiftTimes(SurgeonSchedulePost $post): ?array
    {
        $config = $this->em->getRepository(ShiftPeriodConfig::class)->findOneBy([
            'site'   => $post->getSite(),
            'period' => $post->getPeriod(),
            'active' => true,
        ]);

        if ($config === null) {
            return null;
        }

        return [$config->getStartTime()->format('H:i'), $config->getEndTime()->format('H:i')];
    }

    private static function isInstrumentist(User $user): bool
    {
        return in_array('ROLE_INSTRUMENTIST', $user->getRoles(), true);
    }

    private static function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }
}
