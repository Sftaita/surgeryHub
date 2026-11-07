<?php

namespace App\Activity\Recorder;

use App\Absence\AbsenceImpact;
use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Entity\Absence;
use App\Entity\User;
use App\Enum\AbsenceImpactConsequence;
use App\Enum\AbsenceImpactLevel;
use App\Enum\ActivityEventType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * H4 (D-130) — the manager's reading of one absence-processing run, in activity_event:
 *
 *   ABSENCE_IMPACT_EVALUATED — one per run: every impacted occurrence (date, level N1/N2/N3,
 *     consequence, who is informed), counters per level / consequence, people informed,
 *     what is still uncovered. Answers "what did this absence do?" in one row.
 *   USER_PLANNING_IMPACTED — one per occurrence and person informed: the link from the
 *     absence (and, for N3, the mission event it caused) to her notification.
 *
 * Idempotent per occurrence (an update that keeps an occurrence writes nothing again for
 * it); the aggregate is written for every run that impacts something. Describes decisions
 * already taken — never takes one.
 */
class AbsenceImpactActivityRecorder
{
    private const SNAPSHOT_LIMIT = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ActivityJournal $journal,
    ) {
    }

    /**
     * @param list<AbsenceImpact> $impacts   impacts of the run (current range)
     * @param list<AbsenceImpact> $recovered N1 occurrences an update no longer impacts
     */
    public function evaluated(Absence $absence, string $action, array $impacts, array $recovered, User $actor): void
    {
        if (!$this->journal->isEnabled() || ($impacts === [] && $recovered === [])) {
            return;
        }

        $names = $this->recipientNames($impacts);
        $counts = [];
        $recipients = [];
        $stillUncovered = 0;
        foreach ($impacts as $impact) {
            $counts[$impact->level->value] = ($counts[$impact->level->value] ?? 0) + 1;
            $counts[$impact->consequence->value] = ($counts[$impact->consequence->value] ?? 0) + 1;
            if ($impact->recipientId() !== null) {
                $recipients[$impact->recipientId()] = true;
            }
            if (in_array($impact->consequence, [AbsenceImpactConsequence::MISSION_OPENED, AbsenceImpactConsequence::DRAFT_TO_COVER, AbsenceImpactConsequence::OCCURRENCE_TO_COVER], true)) {
                $stillUncovered++;
            }
        }

        $absent = $absence->getUser();
        $absentName = ActivityJournal::displayName($absent);
        $summary = sprintf('Absence de %s du %s au %s : %d occurrence(s) impactée(s)%s — %d personne(s) informée(s), %d à recouvrir',
            $absentName, $absence->getDateStart()->format('d/m/Y'), $absence->getDateEnd()->format('d/m/Y'), count($impacts),
            self::levelsLabel($counts), count($recipients), $stillUncovered);
        if ($recovered !== []) {
            $summary .= sprintf(', %d de nouveau couverte(s)', count($recovered));
        }

        $this->em->getConnection()->transactional(function () use ($absence, $action, $impacts, $recovered, $counts, $recipients, $stillUncovered, $summary, $names, $actor, $absent): void {
            $aggregateId = $this->journal->record(new ActivityRecord(
                eventType: ActivityEventType::ABSENCE_IMPACT_EVALUATED,
                subjectType: 'Absence',
                subjectId: $absence->getId(),
                subjectLabel: sprintf('Absence #%d', $absence->getId()),
                summary: $summary,
                payload: [
                    'action'             => $action,
                    'absentRole'         => $impacts[0]->absentRole ?? $recovered[0]->absentRole ?? null,
                    'absenceStart'       => $absence->getDateStart()->format('Y-m-d'),
                    'absenceEnd'         => $absence->getDateEnd()->format('Y-m-d'),
                    'counts'             => $counts,
                    'recipientsInformed' => count($recipients),
                    'stillUncovered'     => $stillUncovered,
                    'restoredCount'      => count($recovered),
                    'impactCount'        => count($impacts),
                    'snapshotTruncated'  => count($impacts) > self::SNAPSHOT_LIMIT,
                    'impacts'            => array_map(fn (AbsenceImpact $i) => $this->snapshot($i, $names), array_slice($impacts, 0, self::SNAPSHOT_LIMIT)),
                ],
                actor: $actor,
                absenceId: $absence->getId(),
                userId: $absent?->getId(),
                causeAnchors: ['absence:' . $absence->getId()],
            ));

            foreach ($impacts as $impact) {
                $recipientId = $impact->recipientId();
                if ($recipientId === null) {
                    continue;
                }
                $this->journal->record(new ActivityRecord(
                    eventType: ActivityEventType::USER_PLANNING_IMPACTED,
                    subjectType: $impact->missionId !== null ? 'Mission' : 'SurgeonSchedulePost',
                    subjectId: $impact->missionId ?? $impact->postId,
                    subjectLabel: $impact->missionId !== null ? sprintf('Mission #%d', $impact->missionId) : sprintf('Poste #%d du %s', $impact->postId, $impact->date),
                    summary: self::userSummary($impact, $names[$recipientId] ?? null),
                    payload: $this->snapshot($impact, $names) + ['absenceId' => $impact->absenceId, 'recipientId' => $recipientId],
                    actor: $actor,
                    missionId: $impact->missionId,
                    absenceId: $impact->absenceId,
                    userId: $recipientId,
                    idempotencyKey: 'absence_impact:' . hash('sha256', $impact->key() . '|' . $recipientId),
                    causationEventId: $impact->level === AbsenceImpactLevel::PUBLISHED ? null : $aggregateId,
                    // N3: the mission event the absence caused (cancel / release) is the cause.
                    causeAnchors: $impact->level === AbsenceImpactLevel::PUBLISHED ? ['mission:' . $impact->missionId, 'absence:' . $impact->absenceId] : [],
                ));
            }
        });
    }

    /**
     * @param array<int, ?string> $names
     * @return array<string, mixed>
     */
    private function snapshot(AbsenceImpact $i, array $names): array
    {
        return [
            'date' => $i->date, 'level' => $i->level->value, 'consequence' => $i->consequence->value,
            'missionId' => $i->missionId, 'postId' => $i->postId, 'siteName' => $i->siteName,
            'surgeonName' => $i->surgeonName, 'instrumentistName' => $i->instrumentistName,
            'recipientName' => $i->recipientId() !== null ? ($names[$i->recipientId()] ?? null) : null,
            'startTime' => $i->startTime, 'endTime' => $i->endTime,
        ];
    }

    /** @param list<AbsenceImpact> $impacts @return array<int, ?string> */
    private function recipientNames(array $impacts): array
    {
        $names = [];
        foreach ($impacts as $i) {
            if ($i->recipientId() !== null) {
                $names[$i->recipientId()] = $i->absentRole === AbsenceImpact::ROLE_SURGEON ? $i->instrumentistName : $i->surgeonName;
            }
        }

        return $names;
    }

    private static function userSummary(AbsenceImpact $i, ?string $recipient): string
    {
        $date = (new \DateTimeImmutable($i->date))->format('d/m/Y');
        $where = $i->siteName !== null ? ' — ' . $i->siteName : '';

        return match ($i->consequence) {
            AbsenceImpactConsequence::OCCURRENCE_NEUTRALIZED => sprintf('Planning de %s impacté : créneau habituel du %s avec le Dr %s (indisponible)%s', $recipient, $date, $i->surgeonName, $where),
            AbsenceImpactConsequence::OCCURRENCE_TO_COVER    => sprintf('Planning du Dr %s impacté : créneau habituel du %s sans %s (indisponible)%s', $recipient, $date, $i->instrumentistName, $where),
            AbsenceImpactConsequence::DRAFT_TO_NEUTRALIZE    => sprintf('Planning de %s impacté : créneau du %s (planning en préparation) — Dr %s indisponible%s', $recipient, $date, $i->surgeonName, $where),
            AbsenceImpactConsequence::DRAFT_TO_COVER         => sprintf('Planning du Dr %s impacté : créneau du %s (planning en préparation) à recouvrir — %s indisponible%s', $recipient, $date, $i->instrumentistName, $where),
            AbsenceImpactConsequence::MISSION_CANCELLED      => sprintf('Planning de %s impacté : mission #%d du %s annulée (absence du Dr %s)%s', $recipient, $i->missionId, $date, $i->surgeonName, $where),
            AbsenceImpactConsequence::MISSION_OPENED         => sprintf('Planning du Dr %s impacté : mission #%d du %s à recouvrir (absence de %s)%s', $recipient, $i->missionId, $date, $i->instrumentistName, $where),
            AbsenceImpactConsequence::MANAGER_DECISION       => sprintf('Planning de %s impacté : mission #%d du %s — décision du manager requise%s', $recipient, $i->missionId, $date, $where),
        };
    }

    /** @param array<string, int> $counts */
    private static function levelsLabel(array $counts): string
    {
        $parts = [];
        foreach (['PUBLISHED' => 'publiée(s)', 'DRAFT' => 'en préparation', 'USUAL_SCHEDULE' => 'habituelle(s)'] as $level => $label) {
            if (($counts[$level] ?? 0) > 0) {
                $parts[] = $counts[$level] . ' ' . $label;
            }
        }

        return $parts !== [] ? ' (' . implode(', ', $parts) . ')' : '';
    }
}
