<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Mission;
use App\Entity\User;
use App\Message\AbsenceMissionsReactedMessage;
use App\Message\SendBillingEmailMessage;
use App\MessageHandler\AbsenceMissionsReactedMessageHandler;
use App\Service\NotificationChannels;
use App\Service\NotificationPreferenceResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Unit coverage for the batching/grouping logic — the part that actually implements "un seul
 * email récapitulatif par destinataire" (§7). Real Twig rendering of the 3 templates is
 * covered separately (integration test); real end-to-end delivery is verified against
 * Mailpit manually per the deployment procedure.
 */
final class AbsenceMissionsReactedMessageHandlerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private NotificationPreferenceResolver&MockObject $preferenceResolver;
    private MessageBusInterface&MockObject $bus;
    private LoggerInterface&MockObject $logger;

    private array $dispatched = [];
    private array $persisted  = [];
    private array $usersById  = [];

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->preferenceResolver = $this->createMock(NotificationPreferenceResolver::class);
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->preferenceResolver->method('resolve')
            ->willReturn(new NotificationChannels(inApp: true, email: true, push: false));

        $this->dispatched = [];
        $this->bus->method('dispatch')->willReturnCallback(function (object $msg): Envelope {
            $this->dispatched[] = $msg;
            return new Envelope($msg);
        });

        $this->persisted = [];
        $this->em->method('persist')->willReturnCallback(function ($e) { $this->persisted[] = $e; });
        $this->em->method('flush');
        $this->em->method('getReference')->willReturnCallback(function ($class, $id) {
            $m = new Mission();
            $ref = new \ReflectionProperty($m, 'id');
            $ref->setAccessible(true);
            $ref->setValue($m, $id);
            return $m;
        });

        $this->usersById = [];
        $this->em->method('find')->willReturnCallback(fn ($class, $id) => $this->usersById[$id] ?? null);
    }

    private function makeHandler(): AbsenceMissionsReactedMessageHandler
    {
        return new AbsenceMissionsReactedMessageHandler(
            $this->em, $this->preferenceResolver, $this->bus, $this->logger,
            'noreply@test.com', 'SurgicalHub',
        );
    }

    private static int $nextId = 1;

    private function registerUser(string $email): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setFirstname('Test');
        $u->setLastname('User');
        $id = self::$nextId++;
        $ref = new \ReflectionProperty($u, 'id');
        $ref->setAccessible(true);
        $ref->setValue($u, $id);
        $this->usersById[$id] = $u;
        return $u;
    }

    private function missionRow(array $overrides = []): array
    {
        return array_merge([
            'missionId'         => 1,
            'changeType'        => 'RELEASED',
            'date'              => '10/09/2026',
            'moment'            => 'Matin',
            'horaire'           => '08:00–13:00',
            'siteName'          => 'Site Test',
            'surgeonId'         => null,
            'surgeonName'       => null,
            'instrumentistId'   => null,
            'instrumentistName' => null,
        ], $overrides);
    }

    // ── Instrumentist absence ────────────────────────────────────────────────

    public function test_instrumentist_absence_sends_one_email_to_the_absent_instrumentist_covering_all_missions(): void
    {
        $instr    = $this->registerUser('instr@test.com');
        $surgeon  = $this->registerUser('surgeon@test.com');

        $message = new AbsenceMissionsReactedMessage(
            absenceId: 1,
            absentUserId: $instr->getId(),
            absentUserRole: 'INSTRUMENTIST',
            actorId: 99,
            missions: [
                $this->missionRow(['missionId' => 10, 'surgeonId' => $surgeon->getId()]),
                $this->missionRow(['missionId' => 11, 'surgeonId' => $surgeon->getId()]),
            ],
            occurredAt: new \DateTimeImmutable(),
        );

        $this->makeHandler()->__invoke($message);

        $toInstr = array_values(array_filter(
            $this->dispatched,
            fn ($m) => $m instanceof SendBillingEmailMessage && $m->to === 'instr@test.com',
        ));
        self::assertCount(1, $toInstr, 'Exactly one recap email to the removed instrumentist, not one per mission');
        self::assertCount(2, $toInstr[0]->context['missions']);
    }

    public function test_instrumentist_absence_sends_one_email_per_distinct_affected_surgeon(): void
    {
        $instr     = $this->registerUser('instr@test.com');
        $surgeonA  = $this->registerUser('surgeonA@test.com');
        $surgeonB  = $this->registerUser('surgeonB@test.com');

        $message = new AbsenceMissionsReactedMessage(
            absenceId: 1,
            absentUserId: $instr->getId(),
            absentUserRole: 'INSTRUMENTIST',
            actorId: 99,
            missions: [
                $this->missionRow(['missionId' => 10, 'surgeonId' => $surgeonA->getId()]),
                $this->missionRow(['missionId' => 11, 'surgeonId' => $surgeonA->getId()]),
                $this->missionRow(['missionId' => 12, 'surgeonId' => $surgeonB->getId()]),
            ],
            occurredAt: new \DateTimeImmutable(),
        );

        $this->makeHandler()->__invoke($message);

        $toSurgeonA = array_values(array_filter($this->dispatched, fn ($m) => $m instanceof SendBillingEmailMessage && $m->to === 'surgeonA@test.com'));
        $toSurgeonB = array_values(array_filter($this->dispatched, fn ($m) => $m instanceof SendBillingEmailMessage && $m->to === 'surgeonB@test.com'));

        self::assertCount(1, $toSurgeonA, 'Surgeon A gets exactly one recap covering their 2 missions');
        self::assertCount(2, $toSurgeonA[0]->context['missions']);
        self::assertCount(1, $toSurgeonB, 'Surgeon B gets exactly one recap covering their 1 mission');
        self::assertCount(1, $toSurgeonB[0]->context['missions']);
    }

    public function test_instrumentist_absence_creates_one_in_app_notification_per_mission(): void
    {
        $instr = $this->registerUser('instr@test.com');

        $message = new AbsenceMissionsReactedMessage(
            absenceId: 1, absentUserId: $instr->getId(), absentUserRole: 'INSTRUMENTIST', actorId: 99,
            missions: [$this->missionRow(['missionId' => 10]), $this->missionRow(['missionId' => 11])],
            occurredAt: new \DateTimeImmutable(),
        );

        $this->makeHandler()->__invoke($message);

        self::assertCount(2, $this->persisted, 'In-app notifications may stay one per mission, per the spec');
    }

    // ── Surgeon absence ───────────────────────────────────────────────────────

    public function test_surgeon_absence_sends_one_email_per_distinct_affected_instrumentist(): void
    {
        $instrA = $this->registerUser('instrA@test.com');
        $instrB = $this->registerUser('instrB@test.com');

        $message = new AbsenceMissionsReactedMessage(
            absenceId: 1, absentUserId: 50, absentUserRole: 'SURGEON', actorId: 99,
            missions: [
                $this->missionRow(['missionId' => 20, 'changeType' => 'CANCELLED', 'instrumentistId' => $instrA->getId()]),
                $this->missionRow(['missionId' => 21, 'changeType' => 'CANCELLED', 'instrumentistId' => $instrB->getId()]),
            ],
            occurredAt: new \DateTimeImmutable(),
        );

        $this->makeHandler()->__invoke($message);

        $toA = array_values(array_filter($this->dispatched, fn ($m) => $m instanceof SendBillingEmailMessage && $m->to === 'instrA@test.com'));
        $toB = array_values(array_filter($this->dispatched, fn ($m) => $m instanceof SendBillingEmailMessage && $m->to === 'instrB@test.com'));
        self::assertCount(1, $toA);
        self::assertCount(1, $toB);
    }

    public function test_surgeon_absence_over_a_mission_with_no_instrumentist_sends_no_email(): void
    {
        // A previously-OPEN mission cancelled by surgeon absence has no instrumentist to notify.
        $message = new AbsenceMissionsReactedMessage(
            absenceId: 1, absentUserId: 50, absentUserRole: 'SURGEON', actorId: 99,
            missions: [$this->missionRow(['missionId' => 20, 'changeType' => 'CANCELLED', 'instrumentistId' => null])],
            occurredAt: new \DateTimeImmutable(),
        );

        $this->makeHandler()->__invoke($message);

        $emails = array_filter($this->dispatched, fn ($m) => $m instanceof SendBillingEmailMessage);
        self::assertEmpty($emails, 'No instrumentist recipient exists for a mission that was already OPEN when cancelled');
    }

    // ── Preferences / edge cases ──────────────────────────────────────────────

    public function test_email_channel_disabled_skips_the_email_but_not_the_in_app_notification(): void
    {
        $this->preferenceResolver = $this->createMock(NotificationPreferenceResolver::class);
        $this->preferenceResolver->method('resolve')
            ->willReturn(new NotificationChannels(inApp: true, email: false, push: false));

        $instr = $this->registerUser('instr@test.com');
        $message = new AbsenceMissionsReactedMessage(
            absenceId: 1, absentUserId: $instr->getId(), absentUserRole: 'INSTRUMENTIST', actorId: 99,
            missions: [$this->missionRow(['missionId' => 10])],
            occurredAt: new \DateTimeImmutable(),
        );

        $this->makeHandler()->__invoke($message);

        self::assertEmpty(array_filter($this->dispatched, fn ($m) => $m instanceof SendBillingEmailMessage));
        self::assertCount(1, $this->persisted, 'In-app notification must still be created independently of the email preference');
    }

    public function test_empty_missions_does_nothing(): void
    {
        $message = new AbsenceMissionsReactedMessage(
            absenceId: 1, absentUserId: 1, absentUserRole: 'INSTRUMENTIST', actorId: 99,
            missions: [],
            occurredAt: new \DateTimeImmutable(),
        );

        $this->makeHandler()->__invoke($message);

        self::assertEmpty($this->dispatched);
        self::assertEmpty($this->persisted);
    }

    public function test_recipient_not_found_is_skipped_without_throwing(): void
    {
        $message = new AbsenceMissionsReactedMessage(
            absenceId: 1, absentUserId: 999999, absentUserRole: 'INSTRUMENTIST', actorId: 99,
            missions: [$this->missionRow(['missionId' => 10])],
            occurredAt: new \DateTimeImmutable(),
        );

        $this->makeHandler()->__invoke($message);

        self::assertEmpty($this->dispatched);
    }
}
