<?php

namespace App\Service;

use App\Entity\PlanningAlert;
use App\Enum\MissionAssignmentEndReason;
use App\Enum\MissionAssignmentSource;
use App\Enum\PlanningAlertType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Clock;

/**
 * D-150 — realigns `mission_assignment` on `mission` after a CODE rollback (production.md
 * §Rollback A): while code older than D-150 ran on the D-150 schema, it changed
 * `mission.instrumentist` / `mission.status` without keeping the affectation history. Run
 * only through `app:mission-assignment:resync`, just before the D-150 code serves traffic
 * again. Maintenance-only writer of `mission_assignment`, beside MissionAssignmentService
 * (like the Version20261014090000 backfill); it never touches `mission` nor a closed row.
 *
 * Corrections (each one listed by plan(), nothing else is written):
 * - CLOSE: an open row that no longer matches its mission (instrumentist removed or changed,
 *   status no longer live). ended_by NULL. When the old code journaled the change, ended_at
 *   and end_reason come from the first assignment AuditEvent of the mission after the row
 *   started (released → RELEASED, cancelled → CANCELLED, declaration rejected → REJECTED, any
 *   other new holder → REPLACED). Otherwise ended_at is the instant of the resync — an upper
 *   bound, reported as such — and end_reason the final status (CANCELLED / REJECTED / CLOSED),
 *   else REPLACED (another instrumentist) or RELEASED (none, or back to OPEN / DRAFT).
 *   Open SURGEON_INCOMPATIBILITY alerts of the mission are resolved, as close() does (D-152).
 * - OPEN: a live mission with an instrumentist and no open row of that instrumentist gets a
 *   LEGACY open row (never refusable), assigned_at by the backfill rule (last assignment
 *   AuditEvent, else planning_version.deployed_at, else mission.created_at).
 * - CLOSED_ROW: a final mission (CANCELLED / REJECTED / CLOSED) still carrying an
 *   instrumentist with no row at all for him gets a closed LEGACY row, ended_at by the
 *   backfill rule (final AuditEvent, else ended_at = assigned_at, « fin non datée »).
 *
 * Financial calculations are never modified: a reopening done by the old code did not set the
 * D-153 marker, staleCalculations() lists them for a manual decision.
 */
final class MissionAssignmentResyncService
{
    public const LIVE_STATUSES = ['DECLARED', 'ASSIGNED', 'IN_PROGRESS', 'ENCODING_IN_PROGRESS', 'SUBMITTED', 'VALIDATED'];
    public const FINAL_STATUSES = ['CANCELLED', 'REJECTED', 'CLOSED'];

    /** AuditEvents written when an instrumentist is put on a mission (same list as the backfill). */
    private const ASSIGNMENT_EVENTS = [
        'MISSION_CLAIMED_FROM_POOL', 'MISSION_REASSIGNED_POST_DEPLOY', 'MISSION_ASSIGNED_DIRECTLY',
        'MISSION_ADDED_POST_DEPLOY', 'MISSION_RESTORED_AFTER_SURGEON_ABSENCE',
        'MISSION_ASSIGNMENT_RESTORED_AFTER_INSTRUMENTIST_ABSENCE', 'MISSION_ASSIGNED_FROM_PLANNING',
        'MISSION_DECLARED',
    ];

    /** AuditEvents that change who holds a mission — the first one after a row started ends it. */
    private const CHANGE_EVENTS = [
        'MISSION_RELEASED_TO_POOL', 'MISSION_CANCELLED_POST_DEPLOY', 'MISSION_DECLARED_REJECTED',
        'MISSION_CLAIMED_FROM_POOL', 'MISSION_REASSIGNED_POST_DEPLOY', 'MISSION_ASSIGNED_DIRECTLY',
        'MISSION_RESTORED_AFTER_SURGEON_ABSENCE', 'MISSION_ASSIGNMENT_RESTORED_AFTER_INSTRUMENTIST_ABSENCE',
        'MISSION_ASSIGNED_FROM_PLANNING',
    ];

    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * Read-only. $missionIds limits it to those missions (default: every mission).
     *
     * @param list<int>|null $missionIds
     *
     * @return array{
     *   close: list<array{assignmentId: int, missionId: int, instrumentistId: int, missionStatus: string, endReason: string, endedAt: \DateTimeImmutable, endedAtSource: string}>,
     *   open: list<array{missionId: int, instrumentistId: int, missionStatus: string, assignedAt: \DateTimeImmutable, assignedAtSource: string}>,
     *   closedRow: list<array{missionId: int, instrumentistId: int, missionStatus: string, assignedAt: \DateTimeImmutable, endedAt: \DateTimeImmutable, assignedAtSource: string, endedAtSource: string}>,
     *   at: \DateTimeImmutable
     * }
     */
    public function plan(?array $missionIds = null): array
    {
        $c = $this->em->getConnection();
        $f = self::scope('m', $missionIds);
        $now = \DateTimeImmutable::createFromInterface(Clock::get()->now());

        $close = [];
        foreach ($c->fetchAllAssociative(
            'SELECT a.id, a.mission_id, a.instrumentist_id, m.status, m.instrumentist_id AS current_instrumentist_id,
                    (SELECT CONCAT(ae.created_at, CHAR(124), ae.event_type) FROM audit_event ae
                      WHERE ae.mission_id = m.id AND ae.event_type IN (?) AND ae.created_at > a.assigned_at
                      ORDER BY ae.created_at, ae.id LIMIT 1) AS change_event
               FROM mission_assignment a
               JOIN mission m ON m.id = a.mission_id
              WHERE a.ended_at IS NULL
                AND (m.instrumentist_id IS NULL OR m.instrumentist_id <> a.instrumentist_id OR m.status NOT IN (?))' . $f . '
              ORDER BY a.mission_id, a.id',
            [self::CHANGE_EVENTS, self::LIVE_STATUSES],
            [ArrayParameterType::STRING, ArrayParameterType::STRING],
        ) as $r) {
            [$changedAt, $changeType] = $r['change_event'] !== null ? explode('|', (string) $r['change_event'], 2) : [null, null];
            $reason = match (true) {
                $changeType === 'MISSION_RELEASED_TO_POOL' => MissionAssignmentEndReason::RELEASED,
                $changeType === 'MISSION_CANCELLED_POST_DEPLOY' => MissionAssignmentEndReason::CANCELLED,
                $changeType === 'MISSION_DECLARED_REJECTED' => MissionAssignmentEndReason::REJECTED,
                $changeType !== null => MissionAssignmentEndReason::REPLACED,
                in_array($r['status'], self::FINAL_STATUSES, true) => MissionAssignmentEndReason::from($r['status']),
                $r['current_instrumentist_id'] !== null && (int) $r['current_instrumentist_id'] !== (int) $r['instrumentist_id'] => MissionAssignmentEndReason::REPLACED,
                default => MissionAssignmentEndReason::RELEASED,
            };
            $close[] = [
                'assignmentId'    => (int) $r['id'],
                'missionId'       => (int) $r['mission_id'],
                'instrumentistId' => (int) $r['instrumentist_id'],
                'missionStatus'   => (string) $r['status'],
                'endReason'       => $reason->value,
                'endedAt'         => $changedAt !== null ? new \DateTimeImmutable($changedAt) : $now,
                'endedAtSource'   => $changeType !== null ? 'audit_event ' . $changeType : 'instant de la reprise (borne supérieure)',
            ];
        }

        $assignedAtSql = 'SELECT m.id, m.instrumentist_id, m.status,
                (SELECT MAX(ae.created_at) FROM audit_event ae WHERE ae.mission_id = m.id AND ae.event_type IN (?)) AS audit_at,
                pv.deployed_at, m.created_at,
                (SELECT MAX(fe.created_at) FROM audit_event fe WHERE fe.mission_id = m.id
                   AND ((m.status = \'CANCELLED\' AND fe.event_type = \'MISSION_CANCELLED_POST_DEPLOY\')
                     OR (m.status = \'REJECTED\' AND fe.event_type = \'MISSION_DECLARED_REJECTED\'))) AS final_at
            FROM mission m
            LEFT JOIN planning_version pv ON pv.id = m.planning_version_id
            WHERE m.instrumentist_id IS NOT NULL AND m.status IN (?) AND %s' . $f . '
            ORDER BY m.id';

        $open = [];
        foreach ($c->fetchAllAssociative(
            sprintf($assignedAtSql, 'NOT EXISTS (SELECT 1 FROM mission_assignment o WHERE o.mission_id = m.id AND o.ended_at IS NULL AND o.instrumentist_id = m.instrumentist_id)'),
            [self::ASSIGNMENT_EVENTS, self::LIVE_STATUSES],
            [ArrayParameterType::STRING, ArrayParameterType::STRING],
        ) as $r) {
            [$assignedAt, $source] = $this->assignedAt($r);
            $open[] = [
                'missionId'        => (int) $r['id'],
                'instrumentistId'  => (int) $r['instrumentist_id'],
                'missionStatus'    => (string) $r['status'],
                'assignedAt'       => $assignedAt,
                'assignedAtSource' => $source,
            ];
        }

        $closedRow = [];
        foreach ($c->fetchAllAssociative(
            sprintf($assignedAtSql, 'NOT EXISTS (SELECT 1 FROM mission_assignment o WHERE o.mission_id = m.id AND o.instrumentist_id = m.instrumentist_id)'),
            [self::ASSIGNMENT_EVENTS, self::FINAL_STATUSES],
            [ArrayParameterType::STRING, ArrayParameterType::STRING],
        ) as $r) {
            [$assignedAt, $source] = $this->assignedAt($r);
            $finalAt = $r['final_at'] !== null ? new \DateTimeImmutable((string) $r['final_at']) : null;
            $dated = $finalAt !== null && $finalAt >= $assignedAt;
            $closedRow[] = [
                'missionId'        => (int) $r['id'],
                'instrumentistId'  => (int) $r['instrumentist_id'],
                'missionStatus'    => (string) $r['status'],
                'assignedAt'       => $assignedAt,
                'endedAt'          => $dated ? $finalAt : $assignedAt,
                'assignedAtSource' => $source,
                'endedAtSource'    => $dated ? 'audit_event' : 'fin non datée (ended_at = assigned_at)',
            ];
        }

        return ['close' => $close, 'open' => $open, 'closedRow' => $closedRow, 'at' => $now];
    }

    /**
     * Applies exactly the given plan in ONE transaction, then re-checks the invariants inside
     * it: committed only if every invariant is 0 and every planned close still applied to an
     * open row (otherwise rolled back and an exception is thrown — nothing is written).
     *
     * @param array{close: list<array<string, mixed>>, open: list<array<string, mixed>>, closedRow: list<array<string, mixed>>} $plan
     * @param list<int>|null $missionIds scope of the invariant re-check (same as plan())
     * @return array<string, int> invariants after the corrections
     */
    public function apply(array $plan, ?array $missionIds = null): array
    {
        $c = $this->em->getConnection();
        $c->beginTransaction();
        try {
            foreach ($plan['close'] as $row) {
                $affected = $c->executeStatement(
                    'UPDATE mission_assignment SET ended_at = ?, end_reason = ?, ended_by_id = NULL WHERE id = ? AND ended_at IS NULL',
                    [$row['endedAt'], $row['endReason'], $row['assignmentId']],
                    [Types::DATETIME_IMMUTABLE, ParameterType::STRING, ParameterType::INTEGER],
                );
                if ($affected !== 1) {
                    throw new \RuntimeException(sprintf('Affectation #%d modifiée entre-temps : rien n\'a été écrit, relancer la vérification.', $row['assignmentId']));
                }
                $this->resolveIncompatibilityAlerts((int) $row['missionId']);
            }
            foreach ($plan['open'] as $row) {
                $this->insert($c, $row['missionId'], $row['instrumentistId'], $row['assignedAt'], null, null);
            }
            foreach ($plan['closedRow'] as $row) {
                $this->insert($c, $row['missionId'], $row['instrumentistId'], $row['assignedAt'], $row['endedAt'], $row['missionStatus']);
            }
            $this->em->flush();

            $invariants = $this->invariants($missionIds);
            $broken = array_filter($invariants);
            if ($broken !== []) {
                throw new \RuntimeException('Invariants encore faux après correction (' . implode(', ', array_map(
                    static fn (string $k, int $n) => "{$k}={$n}",
                    array_keys($broken),
                    $broken,
                )) . ') : transaction annulée, rien n\'a été écrit.');
            }
            $c->commit();

            return $invariants;
        } catch (\Throwable $e) {
            if ($c->isTransactionActive()) {
                $c->rollBack();
            }
            $this->em->clear();
            throw $e;
        }
    }

    /**
     * The D-150 invariant, as counts of offending rows — all 0 when aligned.
     *
     * @param list<int>|null $missionIds
     * @return array<string, int>
     */
    public function invariants(?array $missionIds = null): array
    {
        $c = $this->em->getConnection();
        $f = self::scope('m', $missionIds);
        $fa = self::scope('mission_assignment', $missionIds, 'mission_id');
        $live = [self::LIVE_STATUSES];
        $liveType = [ArrayParameterType::STRING];

        return [
            'open_on_non_live_status' => (int) $c->fetchOne(
                'SELECT COUNT(*) FROM mission_assignment a JOIN mission m ON m.id = a.mission_id
                  WHERE a.ended_at IS NULL AND m.status NOT IN (?)' . $f, $live, $liveType),
            'live_without_open_row' => (int) $c->fetchOne(
                'SELECT COUNT(*) FROM mission m WHERE m.instrumentist_id IS NOT NULL AND m.status IN (?)
                    AND NOT EXISTS (SELECT 1 FROM mission_assignment a WHERE a.mission_id = m.id AND a.ended_at IS NULL)' . $f, $live, $liveType),
            'open_row_of_someone_else' => (int) $c->fetchOne(
                'SELECT COUNT(*) FROM mission_assignment a JOIN mission m ON m.id = a.mission_id
                  WHERE a.ended_at IS NULL AND NOT (a.instrumentist_id <=> m.instrumentist_id)' . $f),
            'final_without_row' => (int) $c->fetchOne(
                'SELECT COUNT(*) FROM mission m WHERE m.instrumentist_id IS NOT NULL AND m.status IN (?)
                    AND NOT EXISTS (SELECT 1 FROM mission_assignment a WHERE a.mission_id = m.id AND a.instrumentist_id = m.instrumentist_id)' . $f,
                [self::FINAL_STATUSES], [ArrayParameterType::STRING]),
            'several_open_rows' => (int) $c->fetchOne(
                'SELECT COUNT(*) FROM (SELECT mission_id FROM mission_assignment WHERE ended_at IS NULL' . $fa . ' GROUP BY mission_id HAVING COUNT(*) > 1) x'),
            'ended_before_assigned' => (int) $c->fetchOne(
                'SELECT COUNT(*) FROM mission_assignment WHERE ended_at < assigned_at' . $fa),
        ];
    }

    /**
     * Active calculations whose mission was reopened after the calculation without the D-153
     * marker (a reopening done by the old code). Read-only: listed for a manual decision.
     *
     * @return list<array{calculationId: int, missionId: int, status: string, calculatedAt: string, reopenedAt: string}>
     */
    public function staleCalculations(): array
    {
        return array_map(static fn (array $r) => [
            'calculationId' => (int) $r['id'],
            'missionId'     => (int) $r['mission_id'],
            'status'        => (string) $r['status'],
            'calculatedAt'  => (string) $r['calculated_at'],
            'reopenedAt'    => (string) $r['reopened_at'],
        ], $this->em->getConnection()->fetchAllAssociative(
            "SELECT fc.id, fc.mission_id, fc.status, fc.calculated_at, MAX(ae.created_at) AS reopened_at
               FROM financial_calculation fc
               JOIN audit_event ae ON ae.mission_id = fc.mission_id AND ae.event_type = 'MISSION_ENCODING_REOPENED'
                                  AND ae.created_at > fc.calculated_at
              WHERE fc.status IN ('CALCULATED', 'APPROVED', 'LOCKED') AND fc.superseded_at IS NULL
                AND fc.cancelled_at IS NULL AND fc.encoding_reopened_at IS NULL
              GROUP BY fc.id, fc.mission_id, fc.status, fc.calculated_at
              ORDER BY fc.mission_id, fc.id",
        ));
    }

    /** @param list<int>|null $missionIds integers only, inlined after (int) casting */
    private static function scope(string $alias, ?array $missionIds, string $column = 'id'): string
    {
        if ($missionIds === null) {
            return '';
        }
        $ids = array_map('intval', $missionIds);

        return sprintf(' AND %s.%s IN (%s)', $alias, $column, $ids === [] ? '0' : implode(', ', $ids));
    }

    /**
     * @param array<string, mixed> $r
     * @return array{0: \DateTimeImmutable, 1: string}
     */
    private function assignedAt(array $r): array
    {
        foreach (['audit_at' => 'audit_event', 'deployed_at' => 'planning_version.deployed_at', 'created_at' => 'mission.created_at'] as $column => $source) {
            if ($r[$column] !== null) {
                return [new \DateTimeImmutable((string) $r[$column]), $source];
            }
        }
        throw new \LogicException(sprintf('Mission #%d sans created_at.', $r['id']));
    }

    private function insert(Connection $c, int $missionId, int $instrumentistId, \DateTimeImmutable $assignedAt, ?\DateTimeImmutable $endedAt, ?string $endReason): void
    {
        $c->executeStatement(
            'INSERT INTO mission_assignment (mission_id, instrumentist_id, source, assigned_at, ended_at, end_reason) VALUES (?, ?, ?, ?, ?, ?)',
            [$missionId, $instrumentistId, MissionAssignmentSource::LEGACY->value, $assignedAt, $endedAt, $endReason],
            [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, ParameterType::STRING],
        );
    }

    private function resolveIncompatibilityAlerts(int $missionId): void
    {
        foreach ($this->em->getRepository(PlanningAlert::class)->findBy([
            'mission' => $missionId,
            'type'    => PlanningAlertType::SURGEON_INCOMPATIBILITY,
        ]) as $alert) {
            if ($alert instanceof PlanningAlert && $alert->isOpenOrAcknowledged()) {
                $alert->resolve(null, "Conflit résolu : l'affectation incompatible a pris fin (réalignement après retour arrière du code).");
            }
        }
    }
}
