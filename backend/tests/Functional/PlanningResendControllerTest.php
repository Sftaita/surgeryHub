<?php

namespace App\Tests\Functional;

use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningVersionStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-090 (anomalie fonctionnelle 1) — "Renvoyer le planning par e-mail" à un utilisateur,
 * POST /api/planning/versions/{id}/resend/{userId}. Real EntityManager + real Messenger
 * InMemoryTransport (never mocked) — asserts the actual email dispatched and the actual
 * NotificationEvent row, not just that a service method was called.
 */
final class PlanningResendControllerTest extends WebTestCase
{
    private const PASSWORD = 'Resend90!';

    private EntityManagerInterface $em;
    private array $createdMissionIds = [];
    private array $createdUserIds    = [];
    private array $createdSiteIds    = [];
    private array $createdVersionIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdMissionIds as $id) {
                $e = $this->em->find(Mission::class, $id);
                if ($e !== null) {
                    foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $id]) as $evt) {
                        $this->em->remove($evt);
                    }
                    $this->em->remove($e);
                }
            }
            $this->em->flush();
            foreach ($this->createdUserIds as $id) {
                foreach ($this->em->getRepository(NotificationEvent::class)->findBy(['user' => $id]) as $n) {
                    $this->em->remove($n);
                }
            }
            $this->em->flush();
            foreach ($this->createdVersionIds as $id) {
                $e = $this->em->find(PlanningVersion::class, $id);
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
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role, ?string $email = null): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail($email ?? ('resend90-' . bin2hex(random_bytes(4)) . '@surgicalhub.test'));
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Test');
        $u->setLastname('User');
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

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Resend90-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdSiteIds[] = $h->getId();
        return $h;
    }

    private function makeVersion(Hospital $site, User $manager, PlanningVersionStatus $status): PlanningVersion
    {
        $v = new PlanningVersion();
        $v->setSite($site);
        $v->setPeriodStart(new \DateTimeImmutable('2026-09-01'));
        $v->setPeriodEnd(new \DateTimeImmutable('2026-09-30'));
        $v->setVersionNumber(random_int(1000, 999999));
        $v->setStatus($status);
        $v->setGeneratedBy($manager);
        $this->em->persist($v);
        $this->em->flush();
        $this->createdVersionIds[] = $v->getId();
        return $v;
    }

    private function makeMission(PlanningVersion $version, Hospital $site, User $surgeon, User $createdBy, MissionStatus $status, ?User $instrumentist): Mission
    {
        $tz = new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE);
        $m = new Mission();
        $m->setPlanningVersion($version);
        $m->setType(MissionType::BLOCK);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($createdBy);
        $m->setStartAt(new \DateTimeImmutable('2026-09-15T08:00:00', $tz));
        $m->setEndAt(new \DateTimeImmutable('2026-09-15T13:00:00', $tz));
        $m->setStatus($status);
        if ($instrumentist !== null) {
            $m->setInstrumentist($instrumentist);
        }
        $this->em->persist($m);
        $this->em->flush();
        $this->createdMissionIds[] = $m->getId();
        return $m;
    }

    private function postJson(KernelBrowser $client, string $token, string $uri): Response
    {
        $client->request('POST', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        return $client->getResponse();
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_resend_to_instrumentist_sends_email_and_records_notification_event(): void
    {
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $token    = $this->login($client, $manager);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();
        $version  = $this->makeVersion($site, $manager, PlanningVersionStatus::ACTIVE);
        $this->makeMission($version, $site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr);

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $response = $this->postJson($client, $token, "/api/planning/versions/{$version->getId()}/resend/{$instr->getId()}");

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(1, $body['missionCount']);
        self::assertSame($instr->getEmail(), $body['email']);

        $sentMessages = $transport->getSent();
        self::assertCount(1, $sentMessages, 'Exactly one email dispatched — the target only.');

        $this->em->clear();
        $events = $this->em->getRepository(NotificationEvent::class)->findBy(['user' => $instr->getId()]);
        self::assertCount(1, $events);
        self::assertSame('PLANNING_RESENT_MANUAL', $events[0]->getEventType());
        self::assertSame('SENT', $events[0]->getPayload()['status']);
    }

    public function test_resend_never_notifies_anyone_other_than_the_target(): void
    {
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $token    = $this->login($client, $manager);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $instr1   = $this->createUser('ROLE_INSTRUMENTIST');
        $instr2   = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();
        $version  = $this->makeVersion($site, $manager, PlanningVersionStatus::ACTIVE);
        $this->makeMission($version, $site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr1);
        $this->makeMission($version, $site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr2);

        $response = $this->postJson($client, $token, "/api/planning/versions/{$version->getId()}/resend/{$instr1->getId()}");
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(NotificationEvent::class)->findBy(['user' => $instr1->getId()]));
        self::assertCount(0, $this->em->getRepository(NotificationEvent::class)->findBy(['user' => $instr2->getId()]), 'Nobody else must be notified — never the surgeon, never other instrumentists.');
        self::assertCount(0, $this->em->getRepository(NotificationEvent::class)->findBy(['user' => $surgeon->getId()]));
    }

    public function test_resend_rejects_draft_version(): void
    {
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $token    = $this->login($client, $manager);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();
        $version  = $this->makeVersion($site, $manager, PlanningVersionStatus::DRAFT);
        $this->makeMission($version, $site, $surgeon, $manager, MissionStatus::DRAFT, $instr);

        $response = $this->postJson($client, $token, "/api/planning/versions/{$version->getId()}/resend/{$instr->getId()}");

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
    }

    public function test_resend_returns_403_for_instrumentist(): void
    {
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $token    = $this->login($client, $instr);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $site     = $this->makeSite();
        $version  = $this->makeVersion($site, $manager, PlanningVersionStatus::ACTIVE);
        $this->makeMission($version, $site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr);

        $response = $this->postJson($client, $token, "/api/planning/versions/{$version->getId()}/resend/{$instr->getId()}");

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_resend_to_surgeon_uses_surgeon_template_and_open_plus_assigned_missions(): void
    {
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $token    = $this->login($client, $manager);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();
        $version  = $this->makeVersion($site, $manager, PlanningVersionStatus::ACTIVE);
        $this->makeMission($version, $site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr);
        $this->makeMission($version, $site, $surgeon, $manager, MissionStatus::OPEN, null);

        $response = $this->postJson($client, $token, "/api/planning/versions/{$version->getId()}/resend/{$surgeon->getId()}");

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(2, $body['missionCount'], 'Surgeon resend must include OPEN and ASSIGNED — the same set as the initial deploy email.');
    }
}
