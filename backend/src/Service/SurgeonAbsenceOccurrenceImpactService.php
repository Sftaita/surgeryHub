<?php

namespace App\Service;

use App\Entity\Absence;
use App\Entity\PlanningOccurrenceException;
use App\Entity\ShiftPeriodConfig;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\OccurrenceExceptionSource;
use App\Enum\OccurrenceExceptionType;
use App\Message\SurgeonAbsenceOccurrencesNeutralizedMessage;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Lot 3 (D-103) — reacts to a SURGEON absence by detecting theoretical
 * SurgeonSchedulePost occurrences that fall inside the absence window but have NO
 * Mission generated yet, and records that neutralization as a PlanningOccurrenceException
 * (source = SURGEON_ABSENCE) — never mutating the Post/RecurrenceRule, never creating a
 * fake Mission.
 *
 * Deliberately a separate collaborator from AbsenceMissionReactionService (which reacts
 * to already-materialized Mission rows) and AbsenceImpactService (which raises
 * PlanningAlerts for missions in a non-auto-mutable state). This service's whole domain
 * is the complement: occurrences where NO Mission exists at all yet. Composition works
 * the same way as those two: once this service creates an exception for an occurrence, a
 * subsequent generate() naturally skips it (CANCELLED exception → occurrence suppressed,
 * see PlanningGeneratorServiceV2::preview()) — nothing else needs to know about it.
 *
 * Scope: SURGEON absences only (§22 — instrumentist absence on future Posts is a
 * different, not-yet-built lot). No-op for any other role.
 *
 * Idempotency: a brand-new PlanningOccurrenceException is only ever created when NONE
 * exists yet for (post, occurrenceDate) — this service never mutates or overwrites an
 * existing exception, regardless of its type or source (a manager's manual CANCELLED/
 * MOVED/TIME_OVERRIDE/INSTRUMENTIST_OVERRIDE is never touched or duplicated). Re-running
 * onSurgeonAbsenceCreated()/onSurgeonAbsenceUpdated() for the same absence therefore finds
 * nothing left to create for occurrences already neutralized.
 *
 * onAbsenceDeleted() is deliberately NOT implemented here — restoring a neutralized
 * occurrence after its causing absence is deleted/shrunk is Lot 4. The causal link
 * (source, sourceAbsence) and the AuditEvent snapshot already persist everything Lot 4
 * will need; the FK is ON DELETE SET NULL (same convention as PlanningAlert.absence) so a
 * hard-deleted Absence never blocks or erases this history.
 */
class SurgeonAbsenceOccurrenceImpactService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlanningGeneratorServiceV2 $generator,
        private readonly AuditService $auditService,
        private readonly MessageBusInterface $bus,
        private readonly UserRepository $userRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{created: int} */
    public function onSurgeonAbsenceCreated(Absence $absence, User $actor): array
    {
        return $this->react($absence, $actor);
    }

    /** @return array{created: int} */
    public function onSurgeonAbsenceUpdated(Absence $absence, User $actor): array
    {
        return $this->react($absence, $actor);
    }

    /** @return array{created: int} */
    private function react(Absence $absence, User $actor): array
    {
        $surgeon = $absence->getUser();
        if ($surgeon === null || self::roleOf($surgeon) !== 'SURGEON') {
            return ['created' => 0];
        }

        $posts = $this->loadActivePosts($surgeon, $absence->getDateStart(), $absence->getDateEnd());
        if (empty($posts)) {
            return ['created' => 0];
        }

        $neutralized = [];

        foreach ($posts as $post) {
            $dates = $this->generator->theoreticalOccurrenceDates($post, $absence->getDateStart(), $absence->getDateEnd());

            foreach ($dates as $date) {
                if ($this->hasExistingException($post, $date)) {
                    // Already explained one way or another (manual exception, or an
                    // earlier run of this very service) — never overwrite provenance.
                    continue;
                }

                if ($this->hasExistingMission($post, $date)) {
                    // Already handled by AbsenceMissionReactionService — no double treatment (§14).
                    continue;
                }

                $shift = $this->shiftTimes($post);
                if ($shift === null) {
                    $this->logger->warning('SurgeonAbsenceOccurrenceImpact: no ShiftPeriodConfig for post — occurrence skipped', [
                        'postId' => $post->getId(),
                        'siteId' => $post->getSite()?->getId(),
                        'period' => $post->getPeriod()->value,
                        'date'   => $date->format('Y-m-d'),
                    ]);
                    continue;
                }
                [$startTime, $endTime] = $shift;

                $exception = new PlanningOccurrenceException();
                $exception->setPost($post);
                $exception->setOccurrenceDate($date);
                $exception->setType(OccurrenceExceptionType::CANCELLED);
                $exception->setSource(OccurrenceExceptionSource::SURGEON_ABSENCE);
                $exception->setSourceAbsence($absence);
                $exception->setCreatedBy($actor);
                $this->em->persist($exception);

                $instrumentist = $post->getInstrumentist();
                $site          = $post->getSite();

                $snapshot = [
                    'postId'             => $post->getId(),
                    'occurrenceDate'     => $date->format('Y-m-d'),
                    'siteId'             => $site?->getId(),
                    'siteName'           => $site?->getName(),
                    'surgeonId'          => $surgeon->getId(),
                    'surgeonName'        => self::displayName($surgeon),
                    'instrumentistId'    => $instrumentist?->getId(),
                    'instrumentistName'  => $instrumentist !== null ? self::displayName($instrumentist) : null,
                    'startTime'          => $startTime,
                    'endTime'            => $endTime,
                ];

                $this->auditService->recordGlobal(
                    $actor,
                    AuditEventType::PLANNING_OCCURRENCE_CANCELLED_DUE_TO_SURGEON_ABSENCE,
                    array_merge($snapshot, ['absenceId' => $absence->getId()]),
                );

                $neutralized[] = $snapshot;
            }
        }

        if (empty($neutralized)) {
            return ['created' => 0];
        }

        $this->em->flush();

        $managerIds = array_map(
            static fn (User $m) => $m->getId(),
            $this->userRepository->findManagersAndAdmins(true),
        );

        $this->bus->dispatch(new SurgeonAbsenceOccurrencesNeutralizedMessage(
            absenceId: $absence->getId(),
            surgeonId: $surgeon->getId(),
            surgeonName: self::displayName($surgeon),
            dateStart: $absence->getDateStart()->format('Y-m-d'),
            dateEnd: $absence->getDateEnd()->format('Y-m-d'),
            actorId: $actor->getId(),
            occurrences: $neutralized,
            recipientManagerIds: $managerIds,
            occurredAt: new \DateTimeImmutable(),
        ));

        return ['created' => count($neutralized)];
    }

    /** @return SurgeonSchedulePost[] */
    private function loadActivePosts(User $surgeon, \DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd): array
    {
        return $this->em->createQuery(
            'SELECT p FROM App\Entity\SurgeonSchedulePost p
             WHERE p.surgeon = :surgeon
               AND p.active = true
               AND p.startDate <= :windowEnd
               AND (p.endDate IS NULL OR p.endDate >= :windowStart)'
        )
            ->setParameter('surgeon', $surgeon)
            ->setParameter('windowStart', $windowStart, Types::DATE_IMMUTABLE)
            ->setParameter('windowEnd', $windowEnd, Types::DATE_IMMUTABLE)
            ->getResult();
    }

    private function hasExistingException(SurgeonSchedulePost $post, \DateTimeImmutable $date): bool
    {
        return $this->em->getRepository(PlanningOccurrenceException::class)
            ->findOneBy(['post' => $post, 'occurrenceDate' => $date]) !== null;
    }

    /** Any Mission at all for this exact post's surgeon+site+slot — regardless of status (§14: already generated → not this lot's concern). */
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

    private static function roleOf(User $user): ?string
    {
        $roles = $user->getRoles();
        if (in_array('ROLE_SURGEON', $roles, true)) {
            return 'SURGEON';
        }
        if (in_array('ROLE_INSTRUMENTIST', $roles, true)) {
            return 'INSTRUMENTIST';
        }
        return null;
    }

    private static function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }
}
