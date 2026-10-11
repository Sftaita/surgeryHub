<?php

namespace App\Activity\Recorder;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Entity\Mission;
use App\Entity\PlanningVersion;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\ActivityEventType;
use App\Enum\ActivityOutcome;
use App\Enum\OccurrenceExceptionSource;
use App\Enum\OccurrenceExceptionType;
use App\Enum\PlanningDraftDeletionSource;
use App\Enum\PlanningOccurrenceOutcomeReason;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * H1 (D-126) — builds and writes the planning-level ActivityEvents:
 * PLANNING_GENERATED, PLANNING_DEPLOYED, PLANNING_DRAFT_DELETED,
 * PLANNING_MODIFICATION_APPLIED.
 *
 * Callers invoke it inside their business transaction (ActivityJournal enforces it).
 * It only DESCRIBES what the planning services already decided — it never changes a
 * decision. The one place it reads extra data is PLANNING_GENERATED's reason
 * classification (absences and SURGEON_ABSENCE occurrence exceptions of the period, two
 * bounded queries), to tell a surgeon-absence skip from a manager "Ignorer" — the
 * preview line itself only says SKIPPED.
 *
 * Snapshots are capped at SNAPSHOT_LIMIT occurrences; counters always cover everything,
 * and truncation is explicit (snapshotTruncated / snapshotCount / totalCount).
 */
class PlanningActivityRecorder
{
    public const SNAPSHOT_LIMIT = 500;

    private const MONTHS = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ActivityJournal $journal,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->journal->isEnabled();
    }

    /**
     * @param list<array<string, mixed>> $lines   the lines generate() materialized (preview or editor override)
     * @param array<string, mixed>       $result  generate()'s own result (created/updated/skipped/rejectedAssignments)
     */
    public function generated(PlanningVersion $version, array $lines, array $result, bool $editorOverride, User $actor): ?int
    {
        if (!$this->journal->isEnabled()) {
            return null;
        }

        $statusCounts = [];
        $reasonCounts = [];
        $occurrences  = [];
        $notCovered   = 0;

        $context = $this->classificationContext($lines, $version);

        foreach ($lines as $line) {
            $status = (string) ($line['status'] ?? 'UNKNOWN');
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;

            if (!in_array($status, ['SKIPPED', 'UNCOVERED', 'CONFLICT'], true)) {
                continue;
            }

            $reason = $this->classify($line, $status, $context);
            if ($reason !== null) {
                $reasonCounts[$reason->value] = ($reasonCounts[$reason->value] ?? 0) + 1;
            }

            $notCovered++;
            if (count($occurrences) < self::SNAPSHOT_LIMIT) {
                $post = isset($line['postId']) && (int) $line['postId'] > 0 ? ($context['posts'][(int) $line['postId']] ?? null) : null;
                $occurrences[] = [
                    'date'                      => $line['date'] ?? null,
                    'postId'                    => $line['postId'] ?? null,
                    'startTime'                 => $line['startTime'] ?? null,
                    'endTime'                   => $line['endTime'] ?? null,
                    'siteId'                    => $line['siteId'] ?? null,
                    'surgeonId'                 => $line['surgeonId'] ?? null,
                    'surgeonName'               => $line['surgeonName'] ?? null,
                    'habitualInstrumentistId'   => $post?->getInstrumentist()?->getId(),
                    'habitualInstrumentistName' => ActivityJournal::displayName($post?->getInstrumentist()),
                    'status'                    => $status,
                    'reason'                    => $reason?->value,
                ];
            }
        }

        $label   = $this->versionLabel($version);
        $summary = sprintf('%s généré — %d mission(s) créée(s)', $label, (int) ($result['created'] ?? 0));
        $parts   = [];
        foreach ([
            PlanningOccurrenceOutcomeReason::SURGEON_ABSENT->value       => 'absence chirurgien',
            PlanningOccurrenceOutcomeReason::MANAGER_SKIPPED->value      => 'ignorée(s) par le manager',
            PlanningOccurrenceOutcomeReason::INSTRUMENTIST_ABSENT->value => 'instrumentiste habituelle absente',
            PlanningOccurrenceOutcomeReason::CONFLICT->value             => 'conflit',
        ] as $reason => $text) {
            if (isset($reasonCounts[$reason])) {
                $parts[] = sprintf('%d occurrence(s) %s', $reasonCounts[$reason], $text);
            }
        }
        if ($parts !== []) {
            $summary .= ' — ' . implode(', ', $parts);
        }

        return $this->journal->record(new ActivityRecord(
            eventType: ActivityEventType::PLANNING_GENERATED,
            subjectType: 'PlanningVersion',
            subjectId: $version->getId(),
            subjectLabel: $label,
            summary: $summary,
            payload: $this->versionPayload($version) + [
                'mode'                    => $editorOverride ? 'EDITOR_OVERRIDE' : 'PREVIEW',
                'lineCount'               => count($lines),
                'created'                 => (int) ($result['created'] ?? 0),
                'updated'                 => (int) ($result['updated'] ?? 0),
                'skipped'                 => (int) ($result['skipped'] ?? 0),
                'rejectedAssignmentCount' => count($result['rejectedAssignments'] ?? []),
                'lineStatusCounts'        => $statusCounts,
                'reasonCounts'            => $reasonCounts,
                'occurrences'             => $occurrences,
            ] + self::truncation(count($occurrences), $notCovered),
            actor: $actor,
            siteId: $version->getSite()?->getId(),
            planningVersionId: $version->getId(),
            idempotencyKey: sprintf('planning_version:%d:generated', $version->getId()),
            anchorKey: 'planning_version:' . $version->getId(),
        ));
    }

    /** @param array<string, mixed> $stats */
    public function deployed(?PlanningVersion $version, int $deploymentId, array $stats, ?User $actor): ?int
    {
        if (!$this->journal->isEnabled()) {
            return null;
        }

        // deploy() clears the identity map mid-way (bulk UPDATEs) — reload a managed copy
        // so the label's lazy associations (site, site group) can initialize.
        if ($version !== null && $version->getId() !== null) {
            $version = $this->em->find(PlanningVersion::class, $version->getId()) ?? $version;
        }

        $label = $version !== null ? $this->versionLabel($version) : sprintf('Planning du %s au %s', $stats['periodFrom'] ?? '?', $stats['periodTo'] ?? '?');

        return $this->journal->record(new ActivityRecord(
            eventType: ActivityEventType::PLANNING_DEPLOYED,
            subjectType: $version !== null ? 'PlanningVersion' : 'PlanningDeployment',
            subjectId: $version?->getId() ?? $deploymentId,
            subjectLabel: $label,
            summary: sprintf('%s déployé par %s — %d mission(s) publiée(s) dont %d ouverte(s) au pool',
                $label, ActivityJournal::displayName($actor), (int) ($stats['missionCount'] ?? 0), (int) ($stats['openPoolCount'] ?? 0)),
            payload: ['deploymentId' => $deploymentId, 'planningVersionId' => $version?->getId(), 'legacy' => $version === null] + $stats,
            actor: $actor,
            siteId: $stats['siteId'] ?? $version?->getSite()?->getId(),
            planningVersionId: $version?->getId(),
            idempotencyKey: 'planning_deployment:' . $deploymentId,
            causeAnchors: $version !== null ? ['planning_version:' . $version->getId()] : [],
        ));
    }

    /**
     * Must be called BEFORE the missions/version rows are deleted, inside the deletion
     * transaction.
     *
     * @param Mission[] $missions
     */
    public function draftDeleted(PlanningVersion $version, array $missions, PlanningDraftDeletionSource $source, ?User $actor): ?int
    {
        if (!$this->journal->isEnabled()) {
            return null;
        }

        $countsByStatus = [];
        $occurrences    = [];
        foreach ($missions as $mission) {
            $status = $mission->getStatus()->value;
            $countsByStatus[$status] = ($countsByStatus[$status] ?? 0) + 1;

            if (count($occurrences) < self::SNAPSHOT_LIMIT) {
                $occurrences[] = [
                    'missionId'         => $mission->getId(),
                    'date'              => $mission->getStartAt()?->format('Y-m-d'),
                    'startTime'         => $mission->getStartAt()?->format('H:i'),
                    'endTime'           => $mission->getEndAt()?->format('H:i'),
                    'siteId'            => $mission->getSite()?->getId(),
                    'siteName'          => $mission->getSite()?->getName(),
                    'surgeonId'         => $mission->getSurgeon()?->getId(),
                    'surgeonName'       => ActivityJournal::displayName($mission->getSurgeon()),
                    'instrumentistId'   => $mission->getInstrumentist()?->getId(),
                    'instrumentistName' => ActivityJournal::displayName($mission->getInstrumentist()),
                    'status'            => $status,
                ];
            }
        }

        $label = $this->versionLabel($version);

        return $this->journal->record(new ActivityRecord(
            eventType: ActivityEventType::PLANNING_DRAFT_DELETED,
            subjectType: 'PlanningVersion',
            subjectId: $version->getId(),
            subjectLabel: $label,
            summary: sprintf('Brouillon %s supprimé par %s — %d mission(s) brouillon supprimée(s)',
                $label, ActivityJournal::displayName($actor) ?? 'Système', count($missions)),
            payload: $this->versionPayload($version) + [
                'source'         => $source->value,
                'generatedAt'    => $version->getGeneratedAt()->format(\DateTimeInterface::ATOM),
                'missionCount'   => count($missions),
                'countsByStatus' => $countsByStatus,
                'occurrences'    => $occurrences,
            ] + self::truncation(count($occurrences), count($missions)),
            actor: $actor,
            siteId: $version->getSite()?->getId(),
            planningVersionId: $version->getId(),
            idempotencyKey: sprintf('planning_version:%d:deleted', $version->getId()),
        ));
    }

    /** @param array<string, int|string|null> $stats */
    public function modificationApplied(PlanningVersion $version, array $stats, ActivityOutcome $outcome, User $actor, bool $standalone = false): ?int
    {
        if (!$this->journal->isEnabled()) {
            return null;
        }

        $label   = $this->versionLabel($version);
        $summary = match ($outcome) {
            ActivityOutcome::SUCCESS => sprintf('%s a appliqué une modification de %s — %d mission(s) modifiée(s) (%d libérée(s), %d annulée(s), %d réassignée(s), %d créée(s), %d changement(s) d\'horaire)',
                ActivityJournal::displayName($actor), $label, (int) ($stats['modifiedMissions'] ?? 0),
                (int) ($stats['released'] ?? 0), (int) ($stats['cancelled'] ?? 0),
                (int) ($stats['assigned'] ?? 0) + (int) ($stats['reassigned'] ?? 0), (int) ($stats['created'] ?? 0),
                (int) ($stats['scheduleChanged'] ?? 0)),
            // H3 (D-129) — the batch is one transaction: a failure keeps NOTHING of it.
            default => sprintf('Modification de %s par %s annulée (%s) — aucune modification conservée, aucune notification envoyée',
                $label, ActivityJournal::displayName($actor), $stats['errorType'] ?? 'erreur'),
        };

        $record = new ActivityRecord(
            eventType: ActivityEventType::PLANNING_MODIFICATION_APPLIED,
            subjectType: 'PlanningVersion',
            subjectId: $version->getId(),
            subjectLabel: $label,
            summary: $summary,
            payload: [
                'planningVersionId' => $version->getId(),
                'periodStart'       => $version->getPeriodStart()->format('Y-m-d'),
                'periodEnd'         => $version->getPeriodEnd()->format('Y-m-d'),
            ] + $stats,
            actor: $actor,
            siteId: $version->getSite()?->getId(),
            planningVersionId: $version->getId(),
            outcome: $outcome,
        );

        return $standalone ? $this->journal->recordStandalone($record) : $this->journal->record($record);
    }

    public function versionLabel(PlanningVersion $version): string
    {
        $start = $version->getPeriodStart();
        $end   = $version->getPeriodEnd();
        $period = $start->format('Y-m') === $end->format('Y-m') && $start->format('d') === '01'
            ? sprintf('%s %s', self::MONTHS[(int) $start->format('n')], $start->format('Y'))
            : sprintf('%s → %s', $start->format('d/m/Y'), $end->format('d/m/Y'));
        $scope = $version->getSite()?->getName() ?? $version->getSiteGroup()?->getName();

        return sprintf('Planning %s%s (v%d)', $period, $scope !== null ? ' — ' . $scope : '', $version->getVersionNumber());
    }

    /** @return array<string, mixed> */
    private function versionPayload(PlanningVersion $version): array
    {
        $siteIds = $version->getScopeSiteIds() ?? ($version->getSite() !== null ? [$version->getSite()->getId()] : []);

        return [
            'planningVersionId' => $version->getId(),
            'versionNumber'     => $version->getVersionNumber(),
            'periodStart'       => $version->getPeriodStart()->format('Y-m-d'),
            'periodEnd'         => $version->getPeriodEnd()->format('Y-m-d'),
            'siteIds'           => array_values($siteIds),
            'siteGroupId'       => $version->getSiteGroup()?->getId(),
        ];
    }

    /** @return array{snapshotTruncated: bool, snapshotCount: int, totalCount: int} */
    private static function truncation(int $snapshotCount, int $totalCount): array
    {
        return [
            'snapshotTruncated' => $snapshotCount < $totalCount,
            'snapshotCount'     => $snapshotCount,
            'totalCount'        => $totalCount,
        ];
    }

    /**
     * Two bounded queries for the whole generation (never per line): the absences of every
     * surgeon / habitual instrumentist involved in a non-covered line over the version's
     * period, and the SURGEON_ABSENCE occurrence exceptions of the posts involved.
     *
     * @param list<array<string, mixed>> $lines
     * @return array{posts: array<int, SurgeonSchedulePost>, absent: array<int, list<array{0: string, 1: string}>>, neutralized: array<string, true>}
     */
    private function classificationContext(array $lines, PlanningVersion $version): array
    {
        $postIds = [];
        foreach ($lines as $line) {
            if (in_array($line['status'] ?? null, ['SKIPPED', 'UNCOVERED', 'CONFLICT'], true) && (int) ($line['postId'] ?? 0) > 0) {
                $postIds[(int) $line['postId']] = true;
            }
        }
        if ($postIds === []) {
            return ['posts' => [], 'absent' => [], 'neutralized' => []];
        }

        /** @var SurgeonSchedulePost[] $posts */
        $posts = $this->em->getRepository(SurgeonSchedulePost::class)->findBy(['id' => array_keys($postIds)]);
        $byId  = [];
        $users = [];
        foreach ($posts as $post) {
            $byId[$post->getId()] = $post;
            if ($post->getSurgeon() !== null) {
                $users[$post->getSurgeon()->getId()] = $post->getSurgeon();
            }
            if ($post->getInstrumentist() !== null) {
                $users[$post->getInstrumentist()->getId()] = $post->getInstrumentist();
            }
        }

        $absent = [];
        if ($users !== []) {
            $rows = $this->em->createQuery(
                'SELECT IDENTITY(a.user) AS userId, a.dateStart, a.dateEnd FROM App\Entity\Absence a
                 WHERE a.user IN (:users) AND a.dateStart <= :to AND a.dateEnd >= :from'
            )
                ->setParameter('users', array_values($users))
                ->setParameter('from', $version->getPeriodStart(), Types::DATE_IMMUTABLE)
                ->setParameter('to', $version->getPeriodEnd(), Types::DATE_IMMUTABLE)
                ->getArrayResult();
            foreach ($rows as $row) {
                $absent[(int) $row['userId']][] = [$row['dateStart']->format('Y-m-d'), $row['dateEnd']->format('Y-m-d')];
            }
        }

        $neutralized = [];
        $exceptions  = $this->em->createQuery(
            'SELECT IDENTITY(e.post) AS postId, e.occurrenceDate FROM App\Entity\PlanningOccurrenceException e
             WHERE e.post IN (:posts) AND e.type = :cancelled AND e.source = :source
               AND e.occurrenceDate BETWEEN :from AND :to'
        )
            ->setParameter('posts', array_keys($byId))
            ->setParameter('cancelled', OccurrenceExceptionType::CANCELLED)
            ->setParameter('source', OccurrenceExceptionSource::SURGEON_ABSENCE)
            ->setParameter('from', $version->getPeriodStart(), Types::DATE_IMMUTABLE)
            ->setParameter('to', $version->getPeriodEnd(), Types::DATE_IMMUTABLE)
            ->getArrayResult();
        foreach ($exceptions as $row) {
            $neutralized[$row['postId'] . '|' . $row['occurrenceDate']->format('Y-m-d')] = true;
        }

        return ['posts' => $byId, 'absent' => $absent, 'neutralized' => $neutralized];
    }

    /**
     * @param array<string, mixed> $line
     * @param array{posts: array<int, SurgeonSchedulePost>, absent: array<int, list<array{0: string, 1: string}>>, neutralized: array<string, true>} $context
     */
    private function classify(array $line, string $status, array $context): ?PlanningOccurrenceOutcomeReason
    {
        $date = (string) ($line['date'] ?? '');

        if ($status === 'CONFLICT') {
            return PlanningOccurrenceOutcomeReason::CONFLICT;
        }

        if ($status === 'SKIPPED') {
            $surgeonId = (int) ($line['surgeonId'] ?? 0);
            if (isset($context['neutralized'][((int) ($line['postId'] ?? 0)) . '|' . $date])
                || self::isAbsent($context['absent'][$surgeonId] ?? [], $date)) {
                return PlanningOccurrenceOutcomeReason::SURGEON_ABSENT;
            }

            return PlanningOccurrenceOutcomeReason::MANAGER_SKIPPED;
        }

        // UNCOVERED — only a known reason is reported; an occurrence whose post simply has
        // no habitual instrumentist has none.
        $habitual = $context['posts'][(int) ($line['postId'] ?? 0)] ?? null;
        $habitualId = $habitual?->getInstrumentist()?->getId();
        if ($habitualId !== null && self::isAbsent($context['absent'][$habitualId] ?? [], $date)) {
            return PlanningOccurrenceOutcomeReason::INSTRUMENTIST_ABSENT;
        }

        return null;
    }

    /** @param list<array{0: string, 1: string}> $ranges */
    private static function isAbsent(array $ranges, string $date): bool
    {
        foreach ($ranges as [$from, $to]) {
            if ($from <= $date && $date <= $to) {
                return true;
            }
        }

        return false;
    }
}
