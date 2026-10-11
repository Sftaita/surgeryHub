<?php

namespace App\Service;

use App\Absence\AbsenceImpact;
use App\Entity\Absence;
use App\Entity\User;
use App\Enum\AbsenceImpactConsequence;
use App\Enum\AuditEventType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-107, reshaped by H4 (D-130) — records the N1 consequence of an INSTRUMENTIST absence on
 * the habitual organisation: the occurrence will need another instrumentist. Nothing is
 * mutated (the surgeon still operates; generation never assigns an absent person, D-101):
 * this service only writes the audit trail (mirrored in activity_event) for the occurrences
 * newly impacted, and for the ones an update/shrink no longer impacts.
 *
 * Which occurrences: AbsenceImpactResolver (never an occurrence that already has a Mission).
 * Informing the usual surgeon: AbsenceImpactNotifier, through NotificationDispatcher.
 */
class InstrumentistAbsenceOccurrenceImpactService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditService $auditService,
    ) {
    }

    /**
     * @param list<AbsenceImpact> $newlyImpacted
     * @param list<AbsenceImpact> $noLongerImpacted
     * @return array{newlyImpacted: int, noLongerImpacted: int}
     */
    public function record(Absence $absence, User $actor, array $newlyImpacted, array $noLongerImpacted): array
    {
        $count = ['newlyImpacted' => 0, 'noLongerImpacted' => 0];
        foreach (['newlyImpacted' => $newlyImpacted, 'noLongerImpacted' => $noLongerImpacted] as $direction => $impacts) {
            foreach ($impacts as $impact) {
                if ($impact->consequence !== AbsenceImpactConsequence::OCCURRENCE_TO_COVER) {
                    continue;
                }
                $this->auditService->recordGlobal($actor, AuditEventType::PLANNING_OCCURRENCE_INSTRUMENTIST_ABSENCE_NOTICE,
                    SurgeonAbsenceOccurrenceImpactService::snapshot($impact) + [
                        'absenceId' => $absence->getId(),
                        'direction' => $direction === 'newlyImpacted' ? 'NEWLY_IMPACTED' : 'NO_LONGER_IMPACTED',
                    ]);
                $count[$direction]++;
            }
        }
        if ($count['newlyImpacted'] + $count['noLongerImpacted'] > 0) {
            $this->em->flush();
        }

        return $count;
    }
}
