<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Message\MissionHoursReminderMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-136 — POST /api/missions/{id}/execution/remind ("Rappeler les heures") : autorisation par
 * MissionVoter::HOURS_REMIND, cohérence avec allowedActions[] ('remind_hours'), audit
 * synchrone, envoi asynchrone (message en file, jamais exécuté dans la requête), aucune
 * mutation de statut, relance d'encodage D-120 inchangée.
 */
final class MissionHoursReminderControllerTest extends WebTestCase
{
    private const PASSWORD = 'HoursRemind1!';

    private EntityManagerInterface $em;
    private array $createdMissionIds = [];
    private array $createdUserIds    = [];
    private array $createdSiteIds    = [];
    private array $createdExecutionIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdMissionIds as $missionId) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $missionId]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            // Le handler trace des OutboundNotification sur l'instrumentiste (FK recipient_user).
            foreach ($this->createdUserIds as $userId) {
                foreach ($this->em->getRepository(\App\Entity\OutboundNotification::class)->findBy(['recipientUser' => $userId]) as $n) {
                    $this->em->remove($n);
                }
            }
            foreach ($this->createdExecutionIds as $id) {
                $e = $this->em->find(MissionExecution::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();

            foreach ($this->createdMissionIds as $id) {
                $e = $this->em->find(Mission::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();

            foreach ($this->createdUserIds as $id) {
                $e = $this->em->find(User::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            foreach ($this->createdSiteIds as $id) {
                $e = $this->em->find(Hospital::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function boot(): KernelBrowser
    {
        $client   = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $u = new User();
        $u->setEmail('d133-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Test');
        $u->setLastname(ucfirst(strtolower(str_replace('ROLE_', '', $role))));
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->createdUserIds[] = $u->getId();
        return $u;
    }

    private function login(KernelBrowser $client, User $user): string
    {
        $client->request('POST', '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]),
        );
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, 'Login failed: ' . $client->getResponse()->getContent());
        return $data['token'];
    }

    /** Par défaut : mission terminée depuis 2 h, instrumentiste affecté, aucune heure réelle. */
    private function makeMission(MissionStatus $status, ?User $instrumentist, string $endAt = '-2 hours'): Mission
    {
        $site = new Hospital();
        $site->setName('D133-' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        $this->em->flush();
        $this->createdSiteIds[] = $site->getId();

        $surgeon = $this->createUser('ROLE_SURGEON');

        $m = new Mission();
        $m->setType(MissionType::BLOCK);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($surgeon);
        $m->setStartAt(new \DateTimeImmutable('-10 hours'));
        $m->setEndAt(new \DateTimeImmutable($endAt));
        $m->setStatus($status);
        if ($instrumentist !== null) {
            $m->setInstrumentist($instrumentist);
        }
        $this->em->persist($m);
        $this->em->flush();
        $this->createdMissionIds[] = $m->getId();
        return $m;
    }

    private function addRealDuration(Mission $mission, int $minutes): void
    {
        $execution = new MissionExecution();
        $execution->setMission($mission);
        $execution->setActualDurationMinutes($minutes);
        $this->em->persist($execution);
        $mission->setExecution($execution);
        $this->em->flush();
        $this->createdExecutionIds[] = $execution->getId();
    }

    private function post(KernelBrowser $client, string $token, string $uri): Response
    {
        $client->request('POST', $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        return $client->getResponse();
    }

    private function getMission(KernelBrowser $client, string $token, int $id): array
    {
        $client->request('GET', "/api/missions/{$id}", server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        return json_decode((string) $client->getResponse()->getContent(), true);
    }

    /** @return MissionHoursReminderMessage[] */
    private function queuedHoursReminders(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        return array_values(array_filter(
            array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent()),
            static fn ($msg) => $msg instanceof MissionHoursReminderMessage,
        ));
    }

    private function hoursReminderAuditCount(int $missionId): int
    {
        return count($this->em->getRepository(AuditEvent::class)->findBy([
            'mission'   => $missionId,
            'eventType' => AuditEventType::MISSION_HOURS_MANUAL_REMINDER_SENT,
        ]));
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_manager_reminds_hours_audits_and_queues_async_message_without_changing_status(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $mission = $this->makeMission(MissionStatus::ASSIGNED, $instr);
        $token   = $this->login($client, $manager);

        self::assertContains('remind_hours', $this->getMission($client, $token, $mission->getId())['allowedActions']);

        $response = $this->post($client, $token, "/api/missions/{$mission->getId()}/execution/remind");

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('ASSIGNED', $data['status'], 'un rappel ne mute jamais le statut');
        // La relance d'encodage D-120 reste proposée à côté : deux actions distinctes.
        self::assertContains('remind', $data['allowedActions']);

        self::assertSame(1, $this->hoursReminderAuditCount($mission->getId()));
        $queued = $this->queuedHoursReminders();
        self::assertCount(1, $queued, 'envoi asynchrone : message en file, pas exécuté dans la requête');
        self::assertSame($mission->getId(), $queued[0]->missionId);
        self::assertSame($instr->getId(), $queued[0]->instrumentistId);
    }

    public function test_queued_reminder_is_delivered_by_the_real_handler_with_traced_email_fallback(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $mission = $this->makeMission(MissionStatus::ASSIGNED, $instr);
        $token   = $this->login($client, $manager);

        self::assertSame(Response::HTTP_OK, $this->post($client, $token, "/api/missions/{$mission->getId()}/execution/remind")->getStatusCode());
        [$message] = $this->queuedHoursReminders();

        // Traitement par le vrai handler du conteneur (comme le worker messenger:consume).
        /** @var \App\MessageHandler\MissionHoursReminderMessageHandler $handler */
        $handler = static::getContainer()->get(\App\MessageHandler\MissionHoursReminderMessageHandler::class);
        $handler($message);

        // Aucun abonnement Push en test → Push tracé non livré, puis repli email tracé et lié.
        $this->em->clear();
        $notifications = $this->em->getRepository(\App\Entity\OutboundNotification::class)->findBy(
            ['recipientUser' => $instr->getId(), 'mission' => $mission->getId()],
            ['id' => 'ASC'],
        );
        self::assertCount(2, $notifications);
        self::assertSame(['MISSION_HOURS_REMINDER', 'MISSION_HOURS_REMINDER'], array_map(static fn ($n) => $n->getNotificationType(), $notifications));
        self::assertSame(\App\Enum\OutboundNotificationChannel::PUSH, $notifications[0]->getChannel());
        self::assertSame(\App\Enum\OutboundNotificationChannel::EMAIL, $notifications[1]->getChannel());
        self::assertSame($notifications[0]->getId(), $notifications[1]->getFallbackOf()?->getId());

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $emails = array_values(array_filter(
            array_map(static fn ($e) => $e->getMessage(), $transport->getSent()),
            static fn ($m) => $m instanceof \App\Message\SendTemplatedEmailMessage,
        ));
        self::assertCount(1, $emails);
        self::assertSame($instr->getEmail(), $emails[0]->to);
        self::assertSame('SurgicalHub — Heures réelles à renseigner', $emails[0]->subject);

        // Rendu réel du gabarit : message explicite sur les heures, aucune donnée patient.
        $html = static::getContainer()->get('twig')->render($emails[0]->htmlTemplate, $emails[0]->context);
        self::assertStringContainsString('heures réellement prestées', $html);
        self::assertStringContainsString("/app/i/missions/{$mission->getId()}", $html);
        self::assertStringNotContainsString('finaliser', $html, "ce n'est pas la relance d'encodage");
    }

    public function test_reminder_is_refused_and_not_offered_once_real_hours_exist(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $mission = $this->makeMission(MissionStatus::ASSIGNED, $instr);
        $this->addRealDuration($mission, 480);
        $token   = $this->login($client, $manager);

        self::assertNotContains('remind_hours', $this->getMission($client, $token, $mission->getId())['allowedActions']);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->post($client, $token, "/api/missions/{$mission->getId()}/execution/remind")->getStatusCode());
        self::assertSame(0, $this->hoursReminderAuditCount($mission->getId()));
        self::assertSame([], $this->queuedHoursReminders());
    }

    public function test_reminder_is_refused_before_end_without_instrumentist_or_once_submitted(): void
    {
        $client     = $this->boot();
        $manager    = $this->createUser('ROLE_MANAGER');
        $instr      = $this->createUser('ROLE_INSTRUMENTIST');
        $notEnded   = $this->makeMission(MissionStatus::ASSIGNED, $instr, '+2 hours');
        $unassigned = $this->makeMission(MissionStatus::OPEN, null);
        $submitted  = $this->makeMission(MissionStatus::SUBMITTED, $instr);
        $token      = $this->login($client, $manager);

        foreach ([$notEnded, $unassigned, $submitted] as $mission) {
            self::assertNotContains('remind_hours', $this->getMission($client, $token, $mission->getId())['allowedActions']);
            self::assertSame(Response::HTTP_FORBIDDEN, $this->post($client, $token, "/api/missions/{$mission->getId()}/execution/remind")->getStatusCode());
        }
        self::assertSame([], $this->queuedHoursReminders());
    }

    public function test_instrumentist_and_surgeon_cannot_remind_hours(): void
    {
        $client  = $this->boot();
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $mission = $this->makeMission(MissionStatus::ASSIGNED, $instr);

        $token = $this->login($client, $instr);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->post($client, $token, "/api/missions/{$mission->getId()}/execution/remind")->getStatusCode());

        $surgeon = $this->em->find(Mission::class, $mission->getId())->getSurgeon();
        $token = $this->login($client, $surgeon);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->post($client, $token, "/api/missions/{$mission->getId()}/execution/remind")->getStatusCode());

        self::assertSame(0, $this->hoursReminderAuditCount($mission->getId()));
    }

    public function test_unknown_mission_returns_404(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->post($client, $token, '/api/missions/999999999/execution/remind')->getStatusCode());
    }
}
