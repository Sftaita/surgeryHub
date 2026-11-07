<?php

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionAssignment;
use App\Entity\MissionIntervention;
use App\Entity\NotificationEvent;
use App\Entity\PlanningAlert;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\NotificationType;
use App\Enum\PlanningAlertType;
use App\Enum\SchedulePrecision;
use App\Message\AbsenceMissionsReactedMessage;
use App\Message\MissionLifecycleChangedMessage;
use App\MessageHandler\AbsenceMissionsReactedMessageHandler;
use App\MessageHandler\MissionLifecycleChangedMessageHandler;
use App\Service\AbsenceMissionProtectionPolicy;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * D-158 — a surgeon declares an absence (from their own space, POST /api/absences/mine)
 * AFTER the planning was published: the published missions of the period follow the same
 * engine as the manager path (AbsenceImpactCoordinator), are cancelled — never put back in
 * the OPEN pool — keep their history, and a mission already started or carrying encoded
 * activity is never cancelled blindly (left as is + SURGEON_ABSENCE alert for the manager).
 * Overlapping / repeated absences never cancel or notify twice.
 */
final class SurgeonAbsenceAfterPublicationFunctionalTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const PASSWORD = 'D157Test123!';

    private EntityManagerInterface $em;
    private array $createdIds = ['absences' => [], 'missions' => [], 'users' => [], 'sites' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $conn = $this->em->getConnection();
            if ($this->createdIds['users'] !== []) {
                $users = implode(',', array_map('intval', $this->createdIds['users']));
                $conn->executeStatement("DELETE FROM notification_dispatch WHERE recipient_user_id IN ($users)");
                $conn->executeStatement("DELETE a FROM outbound_notification_attempt a JOIN outbound_notification o ON o.id = a.notification_id WHERE o.recipient_user_id IN ($users)");
                $conn->executeStatement("DELETE FROM outbound_notification WHERE recipient_user_id IN ($users)");
                $conn->executeStatement("DELETE FROM notification_event WHERE user_id IN ($users)");
            }
            if ($this->createdIds['missions'] !== []) {
                $missions = implode(',', array_map('intval', $this->createdIds['missions']));
                $conn->executeStatement("DELETE FROM planning_alert WHERE mission_id IN ($missions)");
                $conn->executeStatement("DELETE FROM audit_event WHERE mission_id IN ($missions)");
                $conn->executeStatement("DELETE FROM mission_intervention WHERE mission_id IN ($missions)");
                $conn->executeStatement("DELETE FROM mission_assignment WHERE mission_id IN ($missions)");
                $conn->executeStatement("DELETE FROM mission_communication WHERE mission_id IN ($missions)");
                $conn->executeStatement("DELETE FROM mission WHERE id IN ($missions)");
            }
            if ($this->createdIds['absences'] !== []) {
                $absences = implode(',', array_map('intval', $this->createdIds['absences']));
                $conn->executeStatement("DELETE FROM absence WHERE id IN ($absences)");
            }
            if ($this->createdIds['users'] !== []) {
                $users = implode(',', array_map('intval', $this->createdIds['users']));
                $conn->executeStatement("DELETE FROM audit_event WHERE actor_id IN ($users)");
                $conn->executeStatement("DELETE FROM user WHERE id IN ($users)");
            }
            if ($this->createdIds['sites'] !== []) {
                $sites = implode(',', array_map('intval', $this->createdIds['sites']));
                $conn->executeStatement("DELETE FROM hospital WHERE id IN ($sites)");
            }
        }
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function makeUser(string $role): User
    {
        $hasher = static::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('d157-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $user->setFirstname('D157');
        $user->setLastname(strtolower(substr($role, 5)) . bin2hex(random_bytes(2)));
        $user->setRoles([$role]);
        $user->setActive(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();
        $this->createdIds['users'][] = $user->getId();

        return $user;
    }

    private function login(KernelBrowser $client, User $user): string
    {
        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, (string) $client->getResponse()->getContent());

        return $data['token'];
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('D157 Site ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();

        return $h;
    }

    /** Always in the future (never a literal date that ends up in the past). */
    private static function d(int $offset): string
    {
        return (new \DateTimeImmutable('+60 days'))->modify("+{$offset} days")->format('Y-m-d');
    }

    private function makeMission(User $surgeon, ?User $instrumentist, Hospital $site, MissionStatus $status, string $day, string $start = '08:00', string $end = '13:00'): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setType(MissionType::BLOCK);
        $m->setSurgeon($surgeon);
        $m->setInstrumentist($instrumentist);
        $m->setSite($site);
        $m->setStartAt(new \DateTimeImmutable("{$day} {$start}:00"));
        $m->setEndAt(new \DateTimeImmutable("{$day} {$end}:00"));
        $m->setCreatedBy($surgeon);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();

        return $m;
    }

    /** POST /api/absences/mine as the surgeon — returns the decoded body. */
    private function declareOwnAbsence(KernelBrowser $client, string $token, string $from, string $to): array
    {
        $client->request('POST', '/api/absences/mine', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'], content: json_encode([
            'dateStart' => $from, 'dateEnd' => $to,
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->createdIds['absences'][] = $body['id'];

        return $body;
    }

    private function reload(Mission $m): Mission
    {
        return $this->em->find(Mission::class, $m->getId());
    }

    /** @return AuditEvent[] */
    private function auditEvents(Mission $m, AuditEventType $type): array
    {
        return $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $m, 'eventType' => $type]);
    }

    /** @return PlanningAlert[] */
    private function surgeonAbsenceAlerts(Mission $m): array
    {
        return $this->em->getRepository(PlanningAlert::class)->findBy(['mission' => $m, 'type' => PlanningAlertType::SURGEON_ABSENCE]);
    }

    private function asyncTransport(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.async');
    }

    /** @return AbsenceMissionsReactedMessage[] */
    private function reactedMessages(): array
    {
        return array_values(array_filter(
            array_map(static fn ($e) => $e->getMessage(), $this->asyncTransport()->getSent()),
            static fn ($m) => $m instanceof AbsenceMissionsReactedMessage,
        ));
    }

    /** Runs the queued absence recap / lifecycle messages through their real handlers, as a worker would. */
    private function drainAsync(): void
    {
        $envelopes = $this->asyncTransport()->getSent();
        $this->asyncTransport()->reset();
        foreach ($envelopes as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof AbsenceMissionsReactedMessage) {
                static::getContainer()->get(AbsenceMissionsReactedMessageHandler::class)->__invoke($message);
            } elseif ($message instanceof MissionLifecycleChangedMessage) {
                static::getContainer()->get(MissionLifecycleChangedMessageHandler::class)->__invoke($message);
            }
        }
    }

    private function notificationCount(User $user, NotificationType $type): int
    {
        return count($this->em->getRepository(NotificationEvent::class)->findBy(['user' => $user, 'eventType' => $type->value]));
    }

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        return $client;
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_one_day_self_absence_cancels_published_assigned_mission_frees_instrumentist_and_keeps_history(): void
    {
        $client  = $this->boot();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, self::d(0));
        $token   = $this->login($client, $surgeon);

        $body = $this->declareOwnAbsence($client, $token, self::d(0), self::d(0));
        self::assertSame(1, $body['missionsImpactedCount']);

        $this->em->clear();
        $reloaded = $this->reload($mission);
        self::assertSame(MissionStatus::CANCELLED, $reloaded->getStatus(), 'Cancelled — never put back in the OPEN pool');
        self::assertNull($reloaded->getInstrumentist(), 'Instrumentist freed');

        $cancelAudit = $this->auditEvents($reloaded, AuditEventType::MISSION_CANCELLED_POST_DEPLOY);
        self::assertCount(1, $cancelAudit);
        self::assertSame($body['id'], $cancelAudit[0]->getPayload()['causedByAbsenceId'], 'Traceability: the cancelling absence is recorded');
        self::assertSame('ASSIGNED', $cancelAudit[0]->getPayload()['previousStatus']);
        self::assertSame($instr->getId(), $cancelAudit[0]->getPayload()['fromInstrumentistId']);

        self::assertCount(0, $this->surgeonAbsenceAlerts($reloaded), 'Handled automatically — no stale manager alert');

        self::assertCount(1, $this->reactedMessages());
        $this->drainAsync();
        $this->em->clear();
        self::assertSame(1, $this->notificationCount($this->em->find(User::class, $instr->getId()), NotificationType::ABSENCE_MISSION_CANCELLED));
    }

    #[WithoutErrorHandler]
    public function test_multi_day_self_absence_cancels_open_and_assigned_missions_of_every_day_only_inside_the_period(): void
    {
        $client  = $this->boot();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $day1 = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, self::d(0));
        $day2 = $this->makeMission($surgeon, null, $site, MissionStatus::OPEN, self::d(1));
        $day3 = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, self::d(2), '13:00', '18:00');
        $after = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, self::d(3));
        $draft = $this->makeMission($surgeon, $instr, $site, MissionStatus::DRAFT, self::d(1), '13:00', '18:00');
        $token = $this->login($client, $surgeon);

        $this->declareOwnAbsence($client, $token, self::d(0), self::d(2));

        $this->em->clear();
        foreach ([$day1, $day2, $day3] as $m) {
            self::assertSame(MissionStatus::CANCELLED, $this->reload($m)->getStatus());
        }
        self::assertSame(MissionStatus::ASSIGNED, $this->reload($after)->getStatus(), 'Outside the period: untouched');
        self::assertSame(MissionStatus::DRAFT, $this->reload($draft)->getStatus(), 'Unpublished DRAFT: never cancelled here');

        $reacted = $this->reactedMessages();
        self::assertCount(1, $reacted, 'One grouped recap for the whole absence');
        self::assertCount(3, $reacted[0]->missions);
    }

    #[WithoutErrorHandler]
    public function test_overlapping_and_repeated_absences_never_cancel_or_notify_twice(): void
    {
        $client  = $this->boot();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, self::d(1));
        $token   = $this->login($client, $surgeon);

        $this->declareOwnAbsence($client, $token, self::d(0), self::d(2));
        $this->drainAsync();
        // A second, overlapping period, then the exact same period declared again.
        $this->declareOwnAbsence($client, $token, self::d(1), self::d(4));
        $this->declareOwnAbsence($client, $token, self::d(0), self::d(2));

        self::assertCount(0, $this->reactedMessages(), 'Nothing left to cancel: no second recap');
        $this->drainAsync();

        $this->em->clear();
        $reloaded = $this->reload($mission);
        self::assertSame(MissionStatus::CANCELLED, $reloaded->getStatus());
        self::assertCount(1, $this->auditEvents($reloaded, AuditEventType::MISSION_CANCELLED_POST_DEPLOY), 'Exactly one cancellation');
        self::assertCount(0, $this->surgeonAbsenceAlerts($reloaded));
        self::assertSame(1, $this->notificationCount($this->em->find(User::class, $instr->getId()), NotificationType::ABSENCE_MISSION_CANCELLED), 'Instrumentist told once');
    }

    #[WithoutErrorHandler]
    public function test_mission_already_started_is_never_cancelled_and_is_signalled_to_the_manager(): void
    {
        $day = self::d(0);
        self::mockTime("{$day} 10:00:00"); // the 08:00–13:00 mission is in progress

        $client  = $this->boot();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $started = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, $day);
        $later   = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, $day, '14:00', '18:00');
        $token   = $this->login($client, $surgeon);

        $this->declareOwnAbsence($client, $token, $day, $day);

        $this->em->clear();
        $reloadedStarted = $this->reload($started);
        self::assertSame(MissionStatus::ASSIGNED, $reloadedStarted->getStatus(), 'Already started: never cancelled blindly');
        self::assertSame($instr->getId(), $reloadedStarted->getInstrumentist()?->getId());
        self::assertCount(0, $this->auditEvents($reloadedStarted, AuditEventType::MISSION_CANCELLED_POST_DEPLOY));
        $alerts = $this->surgeonAbsenceAlerts($reloadedStarted);
        self::assertCount(1, $alerts, 'Signalled to the manager');
        self::assertSame(AbsenceMissionProtectionPolicy::BLOCKED_MISSION_STARTED, $alerts[0]->getSnapshotJson()['autoActionBlockedReason']);

        self::assertSame(MissionStatus::CANCELLED, $this->reload($later)->getStatus(), 'The still-to-come mission of the same day is cancelled');

        $reacted = $this->reactedMessages();
        self::assertCount(1, $reacted);
        self::assertSame([$later->getId()], array_map(static fn (array $m) => $m['missionId'], $reacted[0]->missions), 'The started mission is never announced as cancelled');
    }

    #[WithoutErrorHandler]
    public function test_future_mission_with_encoded_activity_is_never_cancelled_and_is_signalled_to_the_manager(): void
    {
        $client  = $this->boot();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, self::d(0));
        $intervention = (new MissionIntervention())->setMission($mission)->setCode('D157')->setLabel('Intervention D157');
        $this->em->persist($intervention);
        $this->em->flush();
        $token = $this->login($client, $surgeon);

        $this->declareOwnAbsence($client, $token, self::d(0), self::d(0));

        $this->em->clear();
        $reloaded = $this->reload($mission);
        self::assertSame(MissionStatus::ASSIGNED, $reloaded->getStatus());
        self::assertCount(1, $reloaded->getInterventions(), 'Encoding untouched');
        $alerts = $this->surgeonAbsenceAlerts($reloaded);
        self::assertCount(1, $alerts);
        self::assertSame(AbsenceMissionProtectionPolicy::BLOCKED_ACTIVITY_ENCODED, $alerts[0]->getSnapshotJson()['autoActionBlockedReason']);
        self::assertCount(0, $this->reactedMessages());

        // The manager's alert list exposes why (rendered by AlertCard).
        $manager = $this->makeUser('ROLE_MANAGER');
        $client->request('GET', '/api/planning/alerts?status=OPEN&limit=100', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->login($client, $manager)]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $items = array_values(array_filter(
            json_decode((string) $client->getResponse()->getContent(), true)['items'] ?? [],
            static fn (array $a) => ($a['mission']['id'] ?? null) === $mission->getId(),
        ));
        self::assertCount(1, $items);
        self::assertSame('ACTIVITY_ENCODED', $items[0]['autoActionBlockedReason']);
    }

    #[WithoutErrorHandler]
    public function test_manager_surgeon_planning_exposes_the_cancelled_status(): void
    {
        $client  = $this->boot();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $cancelled = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, self::d(0));
        $kept      = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, self::d(3));
        $this->declareOwnAbsence($client, $this->login($client, $surgeon), self::d(0), self::d(0));

        $manager = $this->makeUser('ROLE_MANAGER');
        $client->request('GET', sprintf('/api/surgeons/%d/planning?from=%sT00:00:00&to=%sT23:59:59', $surgeon->getId(), self::d(0), self::d(3)), server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->login($client, $manager)]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $byId = [];
        foreach (json_decode((string) $client->getResponse()->getContent(), true) as $event) {
            $byId[$event['id']] = $event['status'];
        }
        self::assertSame('CANCELLED', $byId[$cancelled->getId()] ?? null);
        self::assertSame('ASSIGNED', $byId[$kept->getId()] ?? null);
    }
}
