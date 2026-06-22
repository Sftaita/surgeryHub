<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\PlanningOccurrenceException;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\OccurrenceExceptionType;
use App\Enum\PlanningAlertType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Creates/updates one-off exceptions for a single occurrence of a SurgeonSchedulePost.
 *
 * Hard rule: a PlanningOccurrenceException NEVER mutates SurgeonSchedulePost or its
 * RecurrenceRule — it is a fully separate row, keyed by (post, occurrenceDate), so the
 * recurring rule and every other occurrence it produces are structurally untouched no
 * matter what this service does.
 *
 * Missions in a terminal-or-out-of-band state (CLOSED, REJECTED, DECLARED) are excluded
 * from impact detection, same convention as AbsenceImpactService.
 */
class PlanningOccurrenceExceptionService
{
    private const NON_ALERTABLE_STATUSES = [
        MissionStatus::CLOSED,
        MissionStatus::REJECTED,
        MissionStatus::DECLARED,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlanningAlertService $alertService,
    ) {}

    /** Cancels a single occurrence. If a Mission already exists for that date, raises OCCURRENCE_CANCELLED — never deletes or modifies the Mission. */
    public function cancelOccurrence(SurgeonSchedulePost $post, \DateTimeImmutable $date, User $createdBy): PlanningOccurrenceException
    {
        $exception = $this->upsert($post, $date, OccurrenceExceptionType::CANCELLED, $createdBy);
        $this->raiseCancellationAlertIfMissionExists($post, $date);
        $this->em->flush();
        return $exception;
    }

    /** Moves a single occurrence to another date (optionally with new hours). The original date is suppressed, exactly like a cancellation. */
    public function moveOccurrence(
        SurgeonSchedulePost $post,
        \DateTimeImmutable $fromDate,
        \DateTimeImmutable $toDate,
        User $createdBy,
        ?\DateTimeImmutable $newStartTime = null,
        ?\DateTimeImmutable $newEndTime = null,
    ): PlanningOccurrenceException {
        $exception = $this->upsert($post, $fromDate, OccurrenceExceptionType::MOVED, $createdBy);
        $exception->setOverrideDate($toDate);
        $exception->setOverrideStartTime($newStartTime);
        $exception->setOverrideEndTime($newEndTime);

        $this->raiseCancellationAlertIfMissionExists($post, $fromDate);
        $this->em->flush();
        return $exception;
    }

    /** Changes the period/hours of a single occurrence without moving its date. */
    public function changePeriod(SurgeonSchedulePost $post, \DateTimeImmutable $date, \DateTimeImmutable $newStartTime, \DateTimeImmutable $newEndTime, User $createdBy): PlanningOccurrenceException
    {
        $exception = $this->upsert($post, $date, OccurrenceExceptionType::TIME_OVERRIDE, $createdBy);
        $exception->setOverrideStartTime($newStartTime);
        $exception->setOverrideEndTime($newEndTime);
        $this->em->flush();
        return $exception;
    }

    /** Changes the instrumentist of a single occurrence without affecting the recurring post's default assignment. */
    public function changeInstrumentist(SurgeonSchedulePost $post, \DateTimeImmutable $date, ?User $newInstrumentist, User $createdBy): PlanningOccurrenceException
    {
        $exception = $this->upsert($post, $date, OccurrenceExceptionType::INSTRUMENTIST_OVERRIDE, $createdBy);
        $exception->setOverrideInstrumentist($newInstrumentist);
        $this->em->flush();
        return $exception;
    }

    // ── REST CRUD (Batch 6) ──────────────────────────────────────────────────
    //
    // Unlike cancelOccurrence()/moveOccurrence()/etc. above (which upsert — they're
    // idempotent setters used internally), the CRUD endpoints follow normal REST
    // semantics: POST creates-or-409s, PATCH modifies the existing row. "One exception
    // per occurrence" is enforced as a real conflict here, not silently overwritten.

    /** @return PlanningOccurrenceException[] ordered by occurrence date */
    public function listForPost(SurgeonSchedulePost $post): array
    {
        return $this->em->createQueryBuilder()
            ->select('e')
            ->from(PlanningOccurrenceException::class, 'e')
            ->where('e.post = :post')
            ->orderBy('e.occurrenceDate', 'ASC')
            ->setParameter('post', $post)
            ->getQuery()
            ->getResult();
    }

    public function createException(
        SurgeonSchedulePost $post,
        OccurrenceExceptionType $type,
        \DateTimeImmutable $occurrenceDate,
        User $createdBy,
        ?\DateTimeImmutable $overrideDate = null,
        ?User $overrideInstrumentist = null,
        ?\DateTimeImmutable $overrideStartTime = null,
        ?\DateTimeImmutable $overrideEndTime = null,
    ): PlanningOccurrenceException {
        $existing = $this->em->getRepository(PlanningOccurrenceException::class)
            ->findOneBy(['post' => $post, 'occurrenceDate' => $occurrenceDate]);
        if ($existing !== null) {
            throw new ConflictHttpException(
                'Une exception existe déjà pour cette occurrence (poste + date) — utilisez PATCH pour la modifier.'
            );
        }

        $this->assertTypeFieldsValid($type, $overrideDate, $overrideStartTime, $overrideEndTime);

        $exception = new PlanningOccurrenceException();
        $exception->setPost($post);
        $exception->setOccurrenceDate($occurrenceDate);
        $exception->setType($type);
        $exception->setOverrideDate($overrideDate);
        $exception->setOverrideInstrumentist($overrideInstrumentist);
        $exception->setOverrideStartTime($overrideStartTime);
        $exception->setOverrideEndTime($overrideEndTime);
        $exception->setCreatedBy($createdBy);
        $this->em->persist($exception);

        if ($type === OccurrenceExceptionType::CANCELLED || $type === OccurrenceExceptionType::MOVED) {
            $this->raiseCancellationAlertIfMissionExists($post, $occurrenceDate);
        }
        $this->em->flush();

        return $exception;
    }

    /**
     * @param array{type?: OccurrenceExceptionType, overrideDate?: \DateTimeImmutable|null,
     *              overrideInstrumentist?: User|null, overrideStartTime?: \DateTimeImmutable|null,
     *              overrideEndTime?: \DateTimeImmutable|null} $input
     * post/occurrenceDate are immutable via PATCH — they're the identity key; delete+recreate instead.
     */
    public function updateException(PlanningOccurrenceException $exception, array $input): PlanningOccurrenceException
    {
        $type               = $input['type'] ?? $exception->getType();
        $overrideDate       = array_key_exists('overrideDate', $input) ? $input['overrideDate'] : $exception->getOverrideDate();
        $overrideInst       = array_key_exists('overrideInstrumentist', $input) ? $input['overrideInstrumentist'] : $exception->getOverrideInstrumentist();
        $overrideStartTime  = array_key_exists('overrideStartTime', $input) ? $input['overrideStartTime'] : $exception->getOverrideStartTime();
        $overrideEndTime    = array_key_exists('overrideEndTime', $input) ? $input['overrideEndTime'] : $exception->getOverrideEndTime();

        $this->assertTypeFieldsValid($type, $overrideDate, $overrideStartTime, $overrideEndTime);

        $exception->setType($type);
        $exception->setOverrideDate($overrideDate);
        $exception->setOverrideInstrumentist($overrideInst);
        $exception->setOverrideStartTime($overrideStartTime);
        $exception->setOverrideEndTime($overrideEndTime);
        $this->em->flush();

        return $exception;
    }

    /** Hard delete — pure scheduling metadata, not historical/audited data like Mission/PlanningAlert. */
    public function deleteException(PlanningOccurrenceException $exception): void
    {
        $this->em->remove($exception);
        $this->em->flush();
    }

    private function assertTypeFieldsValid(
        OccurrenceExceptionType $type,
        ?\DateTimeImmutable $overrideDate,
        ?\DateTimeImmutable $overrideStartTime,
        ?\DateTimeImmutable $overrideEndTime,
    ): void {
        if ($type === OccurrenceExceptionType::MOVED && $overrideDate === null) {
            throw new BadRequestHttpException('overrideDate est requis pour une exception MOVED.');
        }
        if ($type === OccurrenceExceptionType::TIME_OVERRIDE) {
            if ($overrideStartTime === null || $overrideEndTime === null) {
                throw new BadRequestHttpException('overrideStartTime et overrideEndTime sont requis pour une exception TIME_OVERRIDE.');
            }
            if ($overrideStartTime->format('H:i:s') >= $overrideEndTime->format('H:i:s')) {
                throw new BadRequestHttpException('overrideStartTime doit être avant overrideEndTime.');
            }
        }
    }

    /** One exception per (post, occurrenceDate) — replaces any prior exception for that exact date. */
    private function upsert(SurgeonSchedulePost $post, \DateTimeImmutable $date, OccurrenceExceptionType $type, User $createdBy): PlanningOccurrenceException
    {
        $existing = $this->em->getRepository(PlanningOccurrenceException::class)
            ->findOneBy(['post' => $post, 'occurrenceDate' => $date]);

        $exception = $existing ?? new PlanningOccurrenceException();
        $exception->setPost($post);
        $exception->setOccurrenceDate($date);
        $exception->setType($type);
        $exception->setOverrideDate(null);
        $exception->setOverrideInstrumentist(null);
        $exception->setOverrideStartTime(null);
        $exception->setOverrideEndTime(null);
        $exception->setCreatedBy($createdBy);

        if ($existing === null) {
            $this->em->persist($exception);
        }

        return $exception;
    }

    /**
     * If a Mission already exists for this post's surgeon+site on this exact date (and is
     * not terminal), the underlying recurring slot it came from no longer exists — raise
     * OCCURRENCE_CANCELLED so the manager decides what to do. The Mission itself is never
     * touched.
     */
    private function raiseCancellationAlertIfMissionExists(SurgeonSchedulePost $post, \DateTimeImmutable $date): void
    {
        $dayStart = $date->setTime(0, 0, 0);
        $dayEnd   = $date->setTime(23, 59, 59);

        $missions = $this->em->createQuery(
            'SELECT m FROM App\Entity\Mission m
             WHERE m.surgeon = :surgeon
               AND m.site = :site
               AND m.startAt >= :dayStart
               AND m.startAt <= :dayEnd
               AND m.status NOT IN (:excluded)'
        )
            ->setParameter('surgeon', $post->getSurgeon())
            ->setParameter('site', $post->getSite())
            ->setParameter('dayStart', $dayStart, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->setParameter('dayEnd', $dayEnd, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->setParameter('excluded', self::NON_ALERTABLE_STATUSES)
            ->getResult();

        foreach ($missions as $mission) {
            $this->alertService->createIfNotDuplicate(
                $mission,
                PlanningAlertType::OCCURRENCE_CANCELLED,
                null,
                [
                    'type'           => PlanningAlertType::OCCURRENCE_CANCELLED->value,
                    'missionId'      => $mission->getId(),
                    'missionStatus'  => $mission->getStatus()->value,
                    'postId'         => $post->getId(),
                    'occurrenceDate' => $date->format('Y-m-d'),
                ],
            );
        }
    }
}
