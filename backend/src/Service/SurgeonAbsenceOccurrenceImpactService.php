<?php

namespace App\Service;

use App\Absence\AbsenceImpact;
use App\Entity\Absence;
use App\Entity\PlanningOccurrenceException;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\AbsenceImpactConsequence;
use App\Enum\AuditEventType;
use App\Enum\OccurrenceExceptionSource;
use App\Enum\OccurrenceExceptionType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lot 3 (D-103), reshaped by H4 (D-130) — APPLIES the N1 consequence of a SURGEON absence:
 * each habitual occurrence with no Mission yet is neutralised by a PlanningOccurrenceException
 * (CANCELLED, source = SURGEON_ABSENCE) — never mutating the Post/RecurrenceRule, never
 * creating a fake Mission. A subsequent generate() skips the occurrence.
 *
 * Which occurrences are concerned is NOT decided here any more: AbsenceImpactResolver is
 * the single place that resolves N1 / N2 / N3 (and therefore never offers an N1 occurrence
 * when a Mission already exists for the slot). Informing the usual instrumentist is done by
 * AbsenceImpactNotifier, through NotificationDispatcher — this service never notifies.
 *
 * Idempotency: an exception is only created when NONE exists for (post, occurrenceDate) —
 * a manager's own exception is never touched, and re-running for the same absence finds the
 * exceptions it already created. Restoration after the absence shrinks / disappears is
 * AbsenceImpactReconciliationService (D-104).
 */
class SurgeonAbsenceOccurrenceImpactService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditService $auditService,
    ) {
    }

    /**
     * @param list<AbsenceImpact> $impacts the resolver's impacts for this absence (others are ignored)
     * @return array{created: int, occurrences: list<array<string, mixed>>} occurrences newly neutralised by this call
     */
    public function neutralize(Absence $absence, User $actor, array $impacts): array
    {
        $neutralized = [];
        foreach ($impacts as $impact) {
            if ($impact->consequence !== AbsenceImpactConsequence::OCCURRENCE_NEUTRALIZED || $impact->postId === null) {
                continue;
            }
            if ($impact->startTime === null) {
                // Unchanged D-103 rule: no active ShiftPeriodConfig for the post's site/period →
                // the occurrence is not neutralised (its hours are unknown to the generator too).
                continue;
            }
            $post = $this->em->find(SurgeonSchedulePost::class, $impact->postId);
            $date = new \DateTimeImmutable($impact->date);
            if ($post === null || $this->em->getRepository(PlanningOccurrenceException::class)->findOneBy(['post' => $post, 'occurrenceDate' => $date]) !== null) {
                continue; // already explained one way or another — never overwrite provenance
            }

            $exception = (new PlanningOccurrenceException())
                ->setPost($post)
                ->setOccurrenceDate($date)
                ->setType(OccurrenceExceptionType::CANCELLED)
                ->setSource(OccurrenceExceptionSource::SURGEON_ABSENCE)
                ->setSourceAbsence($absence)
                ->setCreatedBy($actor);
            $this->em->persist($exception);

            $snapshot = self::snapshot($impact);
            $this->auditService->recordGlobal($actor, AuditEventType::PLANNING_OCCURRENCE_CANCELLED_DUE_TO_SURGEON_ABSENCE, $snapshot + ['absenceId' => $absence->getId()]);
            $neutralized[] = $snapshot;
        }

        if ($neutralized !== []) {
            $this->em->flush();
        }

        return ['created' => count($neutralized), 'occurrences' => $neutralized];
    }

    /** @return array<string, mixed> the occurrence snapshot shape D-103/D-104/D-105 already use */
    public static function snapshot(AbsenceImpact $impact): array
    {
        return [
            'postId'            => $impact->postId,
            'occurrenceDate'    => $impact->date,
            'siteId'            => $impact->siteId,
            'siteName'          => $impact->siteName,
            'surgeonId'         => $impact->surgeonId,
            'surgeonName'       => $impact->surgeonName,
            'instrumentistId'   => $impact->instrumentistId,
            'instrumentistName' => $impact->instrumentistName,
            'startTime'         => $impact->startTime,
            'endTime'           => $impact->endTime,
        ];
    }
}
