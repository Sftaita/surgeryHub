<?php

namespace App\Tests\Unit\Service;

use App\Entity\Mission;
use App\Entity\User;
use App\Service\EncodingReminderService;
use App\Service\NotificationService;
use App\Service\WebPushService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * D-083 — covers processMission()'s channel selection (Push prioritaire, repli email
 * uniquement si Push n'est pas livrable) and idempotence (réclamation atomique). The
 * eligibility query itself (findEligibleMissions()) is DQL-heavy and covered by a real-DB
 * functional test instead (EncodingReminderServiceEligibilityTest) — mocking QueryBuilder
 * can't meaningfully verify WHERE-clause correctness.
 */
class EncodingReminderServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private WebPushService&MockObject $webPushService;
    private NotificationService&MockObject $notificationService;
    private LoggerInterface&MockObject $logger;

    /** Controls what the atomic claim UPDATE reports as affected rows. */
    private int $claimAffectedRows = 1;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->webPushService = $this->createMock(WebPushService::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->claimAffectedRows = 1;

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('update')->willReturnSelf();
        $qb->method('set')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $q = $this->createMock(Query::class);
        $q->method('execute')->willReturnCallback(fn () => $this->claimAffectedRows);
        $qb->method('getQuery')->willReturn($q);
        $this->em->method('createQueryBuilder')->willReturn($qb);
    }

    private static int $nextId = 1;

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }

    private function makeInstrumentist(): User
    {
        $u = new User();
        $this->setId($u, self::$nextId++);
        return $u;
    }

    private function makeMission(?User $instrumentist): Mission
    {
        $m = new Mission();
        if ($instrumentist !== null) {
            $m->setInstrumentist($instrumentist);
        }
        $this->setId($m, self::$nextId++);
        return $m;
    }

    private function service(): EncodingReminderService
    {
        return new EncodingReminderService($this->em, $this->webPushService, $this->notificationService, $this->logger);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-26 10:00:00', new \DateTimeZone('Europe/Brussels'));
    }

    // ── canal ────────────────────────────────────────────────────────────────

    public function test_sends_push_only_when_deliverable_no_email(): void
    {
        $mission = $this->makeMission($this->makeInstrumentist());

        $this->webPushService->expects($this->once())
            ->method('sendToUserAndReportSuccess')
            ->willReturn(true);
        $this->notificationService->expects($this->never())->method('missionEncodingReminderNotifyInstrumentist');

        $result = $this->service()->processMission($mission, $this->now());

        $this->assertSame('push', $result);
    }

    public function test_falls_back_to_email_when_push_has_no_subscription(): void
    {
        $mission = $this->makeMission($this->makeInstrumentist());

        $this->webPushService->method('sendToUserAndReportSuccess')->willReturn(false);
        $this->notificationService->expects($this->once())->method('missionEncodingReminderNotifyInstrumentist')->with($mission);

        $result = $this->service()->processMission($mission, $this->now());

        $this->assertSame('email', $result);
    }

    public function test_falls_back_to_email_when_all_push_attempts_fail(): void
    {
        // sendToUserAndReportSuccess() already collapses "no subscription", "all expired"
        // and "all attempts failed" into the same false — exercised distinctly in
        // WebPushServiceTest; here we only need the caller-side branch.
        $mission = $this->makeMission($this->makeInstrumentist());

        $this->webPushService->method('sendToUserAndReportSuccess')->willReturn(false);
        $this->notificationService->expects($this->once())->method('missionEncodingReminderNotifyInstrumentist');

        $this->assertSame('email', $this->service()->processMission($mission, $this->now()));
    }

    public function test_never_sends_push_and_email_together(): void
    {
        $mission = $this->makeMission($this->makeInstrumentist());

        $this->webPushService->method('sendToUserAndReportSuccess')->willReturn(true);
        $this->notificationService->expects($this->never())->method('missionEncodingReminderNotifyInstrumentist');

        $this->service()->processMission($mission, $this->now());
    }

    // ── idempotence ─────────────────────────────────────────────────────────

    public function test_skips_when_the_atomic_claim_finds_it_already_reserved(): void
    {
        $this->claimAffectedRows = 0; // a concurrent run already claimed this mission
        $mission = $this->makeMission($this->makeInstrumentist());

        $this->webPushService->expects($this->never())->method('sendToUserAndReportSuccess');
        $this->notificationService->expects($this->never())->method('missionEncodingReminderNotifyInstrumentist');

        $this->assertSame('skipped', $this->service()->processMission($mission, $this->now()));
    }

    public function test_second_call_for_the_same_mission_sends_nothing_more(): void
    {
        $mission = $this->makeMission($this->makeInstrumentist());
        $this->webPushService->method('sendToUserAndReportSuccess')->willReturn(true);

        $service = $this->service();
        $first = $service->processMission($mission, $this->now());

        $this->claimAffectedRows = 0; // simulates the row now having encodingReminderSentAt set
        $second = $service->processMission($mission, $this->now());

        $this->assertSame('push', $first);
        $this->assertSame('skipped', $second);
    }

    public function test_two_distinct_missions_are_processed_independently(): void
    {
        $missionA = $this->makeMission($this->makeInstrumentist());
        $missionB = $this->makeMission($this->makeInstrumentist());

        $this->webPushService->method('sendToUserAndReportSuccess')->willReturn(true);

        $service = $this->service();
        $resultA = $service->processMission($missionA, $this->now());
        $resultB = $service->processMission($missionB, $this->now());

        $this->assertSame('push', $resultA);
        $this->assertSame('push', $resultB);
    }

    public function test_skips_defensively_when_instrumentist_is_somehow_missing(): void
    {
        $mission = $this->makeMission(null);

        $this->webPushService->expects($this->never())->method('sendToUserAndReportSuccess');
        $this->notificationService->expects($this->never())->method('missionEncodingReminderNotifyInstrumentist');

        $this->assertSame('skipped', $this->service()->processMission($mission, $this->now()));
    }

    // ── contenu ─────────────────────────────────────────────────────────────

    public function test_push_payload_contains_no_patient_data(): void
    {
        $mission = $this->makeMission($this->makeInstrumentist());
        $missionId = $mission->getId();

        $captured = [];
        $this->webPushService->method('sendToUserAndReportSuccess')
            ->willReturnCallback(function (User $user, string $title, string $body, array $data) use (&$captured): bool {
                $captured = ['title' => $title, 'body' => $body, 'data' => $data];
                return true;
            });

        $this->service()->processMission($mission, $this->now());

        $this->assertSame('Encodage à finaliser', $captured['title']);
        $this->assertStringNotContainsStringIgnoringCase('patient', $captured['body']);
        $this->assertSame(['missionId', 'url'], array_keys($captured['data']));
        $this->assertSame($missionId, $captured['data']['missionId']);
        $this->assertSame("/app/i/missions/{$missionId}", $captured['data']['url']);
    }

    public function test_message_text_is_human_and_not_guilt_inducing(): void
    {
        $mission = $this->makeMission($this->makeInstrumentist());

        $captured = null;
        $this->webPushService->method('sendToUserAndReportSuccess')
            ->willReturnCallback(function (User $user, string $title, string $body) use (&$captured): bool {
                $captured = $body;
                return true;
            });

        $this->service()->processMission($mission, $this->now());

        $this->assertStringNotContainsStringIgnoringCase('retard', $captured);
        $this->assertStringNotContainsStringIgnoringCase('urgent', $captured);
        $this->assertStringContainsString('lorsque vous êtes disponible', $captured);
    }
}
