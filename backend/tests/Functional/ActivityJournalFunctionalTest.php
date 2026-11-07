<?php

namespace App\Tests\Functional;

use App\Entity\Mission;
use App\Enum\MissionStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * H1 (D-126) — the Schiepers / Detrembleur case replayed through the real HTTP API and the
 * real test database, then explained from activity_event ALONE:
 *
 *   surgeon absence encoded before generation → generation skips the occurrence
 *   (SURGEON_ABSENT, habitual instrumentist named) → draft deleted (snapshot kept) →
 *   regeneration + deploy → a later surgeon absence cancels a deployed mission (same
 *   correlation, explicit causation) → a Modification-mode batch releases a mission
 *   (batch trace + the release it caused, same correlation).
 */
final class ActivityJournalFunctionalTest extends WebTestCase
{
    use LivingPlanningFixturesTrait;

    public function test_schiepers_like_scenario_is_explainable_from_the_journal_alone(): void
    {
        $client   = $this->boot();
        $manager  = $this->makeUser('ROLE_MANAGER', 'Samy', 'Ftaita');
        $site     = $this->makeSite();
        $surgeon  = $this->makeUser('ROLE_SURGEON', 'Philippe', 'Schiepers');
        $francoise = $this->makeSiteInstrumentist($site, 'Françoise', 'Detrembleur');
        $this->makeMondayPost($surgeon, $site, $francoise);
        $token = $this->login($client, $manager);

        $monday1 = $this->firstMonday();
        $monday2 = $monday1->modify('+7 days');
        $monday3 = $monday1->modify('+14 days');
        $monday4 = $monday1->modify('+21 days');

        // ── 1. Absence encoded before generation (self-declared, direct persistence) ──
        $earlyAbsence = $this->makeAbsence($surgeon, $monday2);
        $conn = $this->em->getConnection();
        $created = $conn->fetchAssociative("SELECT * FROM activity_event WHERE event_type = 'ABSENCE_CREATED' AND absence_id = ?", [$earlyAbsence->getId()]);
        self::assertSame(sprintf('Philippe Schiepers a enregistré une absence du %s au %s', $monday2->format('d/m/Y'), $monday2->format('d/m/Y')), $created['summary']);

        // ── 2. Generation: the absent Monday is skipped for a typed, explained reason ──
        $gen = $this->api($client, $token, 'POST', '/api/planning/v2/generate', [
            'siteId' => $site->getId(), 'siteGroupId' => null, 'year' => self::testYear(), 'month' => self::testMonth(),
        ]);
        self::assertSame(200, $gen->getStatusCode(), (string) $gen->getContent());
        $draftId = (int) $this->body($gen)['versionId'];

        $generated = $conn->fetchAssociative("SELECT * FROM activity_event WHERE event_type = 'PLANNING_GENERATED' AND planning_version_id = ?", [$draftId]);
        self::assertNotFalse($generated);
        $gp = json_decode((string) $generated['payload'], true);
        self::assertSame(1, $gp['reasonCounts']['SURGEON_ABSENT'] ?? null, json_encode($gp));
        $skipped = array_values(array_filter($gp['occurrences'], static fn ($o) => $o['reason'] === 'SURGEON_ABSENT'));
        self::assertSame($monday2->format('Y-m-d'), $skipped[0]['date']);
        self::assertSame('Philippe Schiepers', $skipped[0]['surgeonName']);
        self::assertSame('Françoise Detrembleur', $skipped[0]['habitualInstrumentistName']);
        self::assertStringContainsString('1 occurrence(s) absence chirurgien', $generated['summary']);
        self::assertSame('Samy Ftaita', $generated['actor_name_snapshot']);

        // ── 3. Draft deleted: the snapshot keeps what disappeared ─────────────────
        $del = $this->api($client, $token, 'DELETE', "/api/planning/versions/{$draftId}");
        self::assertSame(204, $del->getStatusCode(), (string) $del->getContent());
        $deleted = $conn->fetchAssociative("SELECT * FROM activity_event WHERE event_type = 'PLANNING_DRAFT_DELETED' AND planning_version_id = ?", [$draftId]);
        self::assertNotFalse($deleted);
        $dp = json_decode((string) $deleted['payload'], true);
        self::assertSame('MANUAL', $dp['source']);
        self::assertGreaterThanOrEqual(3, $dp['missionCount']);
        self::assertSame('Françoise Detrembleur', $dp['occurrences'][0]['instrumentistName']);
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM planning_version WHERE id = ?', [$draftId]));

        // ── 4. Regenerate + deploy ─────────────────────────────────────────────
        $versionId = $this->generateAndDeploy($client, $token, $site);
        $deployed  = $conn->fetchAssociative("SELECT * FROM activity_event WHERE event_type = 'PLANNING_DEPLOYED' AND planning_version_id = ?", [$versionId]);
        self::assertNotFalse($deployed);
        self::assertGreaterThanOrEqual(3, json_decode((string) $deployed['payload'], true)['missionCount']);

        $missionOn = function (\DateTimeImmutable $day) use ($versionId): Mission {
            $this->em->clear();
            return $this->em->createQuery('SELECT m FROM App\Entity\Mission m WHERE m.planningVersion = :v AND m.startAt >= :d0 AND m.startAt < :d1')
                ->setParameter('v', $versionId)
                ->setParameter('d0', $day->setTime(0, 0))->setParameter('d1', $day->setTime(23, 59))
                ->getSingleResult();
        };
        $mission3 = $missionOn($monday3);
        $mission4 = $missionOn($monday4);
        self::assertSame(MissionStatus::ASSIGNED, $mission3->getStatus());

        // ── 5. Surgeon absence on a deployed, assigned mission (real controller) ──
        $abs = $this->api($client, $token, 'POST', '/api/absences', [
            'userId' => $surgeon->getId(), 'dateStart' => $monday3->format('Y-m-d'), 'dateEnd' => $monday3->format('Y-m-d'),
            'reason' => 'Congrès — ne doit jamais apparaître dans le journal',
        ]);
        self::assertSame(201, $abs->getStatusCode(), (string) $abs->getContent());
        $absenceId = (int) $this->body($abs)['id'];

        $absenceEvent = $conn->fetchAssociative("SELECT * FROM activity_event WHERE event_type = 'ABSENCE_CREATED' AND absence_id = ?", [$absenceId]);
        $cancel = $conn->fetchAssociative("SELECT * FROM activity_event WHERE event_type = 'MISSION_CANCELLED_POST_DEPLOY' AND mission_id = ?", [$mission3->getId()]);
        self::assertSame(sprintf('Samy Ftaita a enregistré une absence pour Philippe Schiepers du %s au %s', $monday3->format('d/m/Y'), $monday3->format('d/m/Y')), $absenceEvent['summary']);
        self::assertNotFalse($cancel);
        self::assertSame($absenceEvent['correlation_id'], $cancel['correlation_id'], 'one operation, one correlation');
        self::assertSame((int) $absenceEvent['id'], (int) $cancel['causation_event_id'], 'explicit cause: the absence');
        self::assertSame($francoise->getId(), (int) $cancel['user_id'], 'the instrumentist who lost the mission is the concerned user');
        self::assertStringContainsString(sprintf('Mission #%d annulée à la suite de l\'absence #%d — ASSIGNED → CANCELLED', $mission3->getId(), $absenceId), $cancel['summary']);
        self::assertStringContainsString('Françoise Detrembleur', $cancel['summary']);
        self::assertNotSame($generated['correlation_id'], $absenceEvent['correlation_id'], 'different requests, different correlations');

        // ── 6. Modification mode releases one mission ──────────────────────────
        $line = [
            'existingMissionId' => $mission4->getId(), 'date' => $monday4->format('Y-m-d'),
            'startTime' => '08:00', 'endTime' => '13:00', 'siteId' => $site->getId(), 'surgeonId' => $surgeon->getId(),
            'missionType' => 'BLOCK', 'instrumentistId' => null, 'status' => 'UNCOVERED',
        ];
        $apply = $this->api($client, $token, 'POST', "/api/planning/versions/{$versionId}/apply-modifications", ['lines' => [$line]]);
        self::assertSame(200, $apply->getStatusCode(), (string) $apply->getContent());

        $batch   = $conn->fetchAssociative("SELECT * FROM activity_event WHERE event_type = 'PLANNING_MODIFICATION_APPLIED' AND planning_version_id = ? ORDER BY id DESC LIMIT 1", [$versionId]);
        $release = $conn->fetchAssociative("SELECT * FROM activity_event WHERE event_type = 'MISSION_RELEASED_TO_POOL' AND mission_id = ?", [$mission4->getId()]);
        $bp = json_decode((string) $batch['payload'], true);
        self::assertSame('SUCCESS', $batch['outcome']);
        self::assertSame(1, $bp['requestedLines']);
        self::assertSame(1, $bp['modifiedMissions']);
        self::assertSame(1, $bp['released']);
        self::assertSame(0, $bp['cancelled']);
        self::assertSame(0, $bp['assigned'] + $bp['reassigned'] + $bp['scheduleChanged'] + $bp['created']);
        self::assertSame($batch['correlation_id'], $release['correlation_id']);
        self::assertStringContainsString('Samy Ftaita a libéré la mission #' . $mission4->getId(), $release['summary']);

        // ── 6b. A batch that fails half-way is rolled back entirely and reported ──
        // H3 (D-129): the whole batch is one transaction — the line that already cancelled
        // mission 4 is undone with the failing one, and nobody is notified.
        $sophie = $this->makeSiteInstrumentist($site, 'Sophie', 'Gillard');
        $this->makeAbsence($sophie, $monday1);
        $mission1 = $missionOn($monday1);
        $failing = $this->api($client, $token, 'POST', "/api/planning/versions/{$versionId}/apply-modifications", ['lines' => [
            ['existingMissionId' => $mission4->getId(), 'status' => 'SKIPPED'], // would cancel the now-OPEN mission
            array_merge($line, ['existingMissionId' => $mission1->getId(), 'date' => $monday1->format('Y-m-d'), 'instrumentistId' => $sophie->getId(), 'status' => 'COVERED']),
        ]]);
        self::assertGreaterThanOrEqual(400, $failing->getStatusCode(), 'reassigning an absent instrumentist is refused');
        $failed = $conn->fetchAssociative("SELECT * FROM activity_event WHERE event_type = 'PLANNING_MODIFICATION_APPLIED' AND planning_version_id = ? ORDER BY id DESC LIMIT 1", [$versionId]);
        $fp = json_decode((string) $failed['payload'], true);
        self::assertSame('FAILED', $failed['outcome']);
        self::assertTrue($fp['rolledBack']);
        self::assertSame(2, $fp['requestedLines']);
        self::assertNotEmpty($fp['errorType']);
        self::assertStringContainsString('aucune modification conservée', $failed['summary']);
        self::assertSame('OPEN', $conn->fetchOne('SELECT status FROM mission WHERE id = ?', [$mission4->getId()]), 'the first line was rolled back with the failing one');
        self::assertFalse($conn->fetchOne(
            "SELECT id FROM activity_event WHERE event_type = 'MISSION_CANCELLED_POST_DEPLOY' AND mission_id = ?", [$mission4->getId()],
        ), 'no trace of a cancellation that never happened');

        // ── 7. Nothing forbidden anywhere in what this scenario wrote ─────────
        $rows = $conn->fetchAllAssociative(
            'SELECT summary, subject_label_snapshot, actor_name_snapshot, payload FROM activity_event WHERE correlation_id IN (?, ?, ?, ?, ?)',
            [$created['correlation_id'], $generated['correlation_id'], $deleted['correlation_id'], $absenceEvent['correlation_id'], $batch['correlation_id']],
        );
        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            $blob = json_encode($row, JSON_UNESCAPED_UNICODE);
            self::assertStringNotContainsString('@', $blob, 'no email address');
            self::assertStringNotContainsString('Congrès', $blob, 'no free-text reason');
            self::assertDoesNotMatchRegularExpression('/"(password|token|jwt|note|patient|email)"/i', (string) $row['payload']);
            // "reason" only ever appears as a typed generation reason, never as free text.
            preg_match_all('/"reason": "([^"]*)"/', (string) $row['payload'], $reasons);
            foreach ($reasons[1] as $reason) {
                self::assertContains($reason, ['SURGEON_ABSENT', 'MANAGER_SKIPPED', 'INSTRUMENTIST_ABSENT', 'CONFLICT']);
            }
        }
    }
}
