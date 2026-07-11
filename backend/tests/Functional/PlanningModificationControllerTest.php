<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningVersionStatus;
use App\Message\SendBillingEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Functional HTTP tests for Planning V2 Modification mode's batch-apply endpoint —
 * POST /api/planning/versions/{id}/apply-modifications. Real EntityManager throughout
 * (never mocked — D-042): asserts actual Mission mutations land in the DB, not just that
 * a service method was called.
 */
final class PlanningModificationControllerTest extends WebTestCase
{
    private const PASSWORD = 'Modification16A!';

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
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $id]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            $this->em->flush();

            // Include any mission created BY the endpoint itself (not tracked at setup time).
            if (!empty($this->createdVersionIds)) {
                $extra = $this->em->createQueryBuilder()
                    ->select('m')->from(Mission::class, 'm')
                    ->where('m.planningVersion IN (:versions)')
                    ->setParameter('versions', $this->createdVersionIds)
                    ->getQuery()->getResult();
                foreach ($extra as $m) {
                    foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $m->getId()]) as $evt) {
                        $this->em->remove($evt);
                    }
                    $this->em->remove($m);
                }
                $this->em->flush();
            }

            foreach ($this->createdMissionIds as $id) {
                $e = $this->em->find(Mission::class, $id);
                if ($e !== null) { $this->em->remove($e); }
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
        // Without this, KernelBrowser reboots (fresh container) on every request() call —
        // any InMemoryTransport reference captured beforehand would point at a discarded
        // container and always report zero sent messages, independent of app behavior.
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $u = new User();
        $u->setEmail('mod16a-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
        $h->setName('Mod16A-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdSiteIds[] = $h->getId();
        return $h;
    }

    private function makeVersion(Hospital $site, User $manager): PlanningVersion
    {
        $v = new PlanningVersion();
        $v->setSite($site);
        $v->setPeriodStart(new \DateTimeImmutable('2026-09-01'));
        $v->setPeriodEnd(new \DateTimeImmutable('2026-09-30'));
        $v->setVersionNumber(1);
        $v->setStatus(PlanningVersionStatus::ACTIVE);
        $v->setGeneratedBy($manager);
        $this->em->persist($v);
        $this->em->flush();
        $this->createdVersionIds[] = $v->getId();
        return $v;
    }

    private function makeMission(PlanningVersion $version, Hospital $site, User $surgeon, User $createdBy, MissionStatus $status, ?User $instrumentist = null): Mission
    {
        $m = new Mission();
        $m->setPlanningVersion($version);
        $m->setType(MissionType::BLOCK);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($createdBy);
        $m->setStartAt(new \DateTimeImmutable('2026-09-15 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-09-15 13:00:00'));
        $m->setStatus($status);
        if ($instrumentist !== null) {
            $m->setInstrumentist($instrumentist);
        }
        $this->em->persist($m);
        $this->em->flush();
        $this->createdMissionIds[] = $m->getId();
        return $m;
    }

    private function postJson(KernelBrowser $client, string $token, string $uri, array $body): Response
    {
        $client->request('POST', $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode($body),
        );
        return $client->getResponse();
    }

    private function lineFor(Mission $m, array $overrides = []): array
    {
        return array_merge([
            'date'                     => $m->getStartAt()->format('Y-m-d'),
            'postId'                   => $m->getId(),
            'surgeonId'                => $m->getSurgeon()?->getId(),
            'surgeonName'              => '',
            'missionType'              => $m->getType()->value,
            'startTime'                => $m->getStartAt()->format('H:i'),
            'endTime'                  => $m->getEndAt()->format('H:i'),
            'siteId'                   => $m->getSite()?->getId(),
            'siteName'                 => '',
            'instrumentistId'          => $m->getInstrumentist()?->getId(),
            'instrumentistName'        => null,
            'status'                   => $m->getInstrumentist() !== null ? 'COVERED' : 'UNCOVERED',
            'existingMissionId'        => $m->getId(),
            'existingInstrumentistId'  => $m->getInstrumentist()?->getId(),
            'existingInstrumentistName'=> null,
            'freedFrom'                => false,
        ], $overrides);
    }

    // ── Reassignment ──────────────────────────────────────────────────────────

    public function test_reassign_line_updates_instrumentist_in_db(): void
    {
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $token    = $this->login($client, $manager);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $instr1   = $this->createUser('ROLE_INSTRUMENTIST');
        $instr2   = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();
        $version  = $this->makeVersion($site, $manager);
        $mission  = $this->makeMission($version, $site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr1);

        $response = $this->postJson(
            $client, $token,
            '/api/planning/versions/' . $version->getId() . '/apply-modifications',
            ['lines' => [$this->lineFor($mission, ['instrumentistId' => $instr2->getId()])]],
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertSame(1, $body['updated'] ?? null, json_encode($body));

        $this->em->clear();
        $reloaded = $this->em->find(Mission::class, $mission->getId());
        self::assertSame($instr2->getId(), $reloaded->getInstrumentist()?->getId());
    }

    // ── Cancellation ──────────────────────────────────────────────────────────

    public function test_cancel_line_on_open_mission_transitions_to_cancelled(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $version = $this->makeVersion($site, $manager);
        $mission = $this->makeMission($version, $site, $surgeon, $manager, MissionStatus::OPEN);

        $response = $this->postJson(
            $client, $token,
            '/api/planning/versions/' . $version->getId() . '/apply-modifications',
            ['lines' => [$this->lineFor($mission, ['status' => 'SKIPPED'])]],
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertSame(1, $body['cancelled'] ?? null, json_encode($body));

        $this->em->clear();
        $reloaded = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::CANCELLED, $reloaded->getStatus());
    }

    // ── Creation ──────────────────────────────────────────────────────────────

    public function test_new_line_without_existing_mission_id_creates_mission_linked_to_version(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $version = $this->makeVersion($site, $manager);

        $newLine = [
            'date' => '2026-09-18', 'postId' => -1, 'surgeonId' => $surgeon->getId(), 'surgeonName' => '',
            'missionType' => 'BLOCK', 'startTime' => '08:00', 'endTime' => '13:00',
            'siteId' => $site->getId(), 'siteName' => '', 'instrumentistId' => $instr->getId(),
            'instrumentistName' => null, 'status' => 'COVERED', 'existingMissionId' => null,
            'existingInstrumentistId' => null, 'existingInstrumentistName' => null, 'freedFrom' => false,
        ];

        $response = $this->postJson(
            $client, $token,
            '/api/planning/versions/' . $version->getId() . '/apply-modifications',
            ['lines' => [$newLine]],
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertSame(1, $body['created'] ?? null, json_encode($body));

        $this->em->clear();
        $created = $this->em->createQueryBuilder()
            ->select('m')->from(Mission::class, 'm')
            ->where('m.planningVersion = :v')->setParameter('v', $version->getId())
            ->getQuery()->getResult();
        self::assertCount(1, $created);
        self::assertSame(MissionStatus::ASSIGNED, $created[0]->getStatus());
        self::assertSame($instr->getId(), $created[0]->getInstrumentist()?->getId());
    }

    public function test_new_line_marked_skipped_is_not_created(): void
    {
        // A draft line the user removed client-side before submitting (frontend now strips it
        // from the batch entirely — see GeneratePlanningTab::handleCancelMission) must never
        // create a Mission even if a stale/buggy client still sends it with status SKIPPED.
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $version = $this->makeVersion($site, $manager);

        $withdrawnLine = [
            'date' => '2026-09-18', 'postId' => -1, 'surgeonId' => $surgeon->getId(), 'surgeonName' => '',
            'missionType' => 'BLOCK', 'startTime' => '08:00', 'endTime' => '13:00',
            'siteId' => $site->getId(), 'siteName' => '', 'instrumentistId' => null,
            'instrumentistName' => null, 'status' => 'SKIPPED', 'existingMissionId' => null,
            'existingInstrumentistId' => null, 'existingInstrumentistName' => null, 'freedFrom' => false,
        ];

        $response = $this->postJson(
            $client, $token,
            '/api/planning/versions/' . $version->getId() . '/apply-modifications',
            ['lines' => [$withdrawnLine]],
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertSame(1, $body['unchanged'] ?? null, json_encode($body));
        self::assertSame(0, $body['created'] ?? null, json_encode($body));

        $this->em->clear();
        $created = $this->em->createQueryBuilder()
            ->select('m')->from(Mission::class, 'm')
            ->where('m.planningVersion = :v')->setParameter('v', $version->getId())
            ->getQuery()->getResult();
        self::assertCount(0, $created, 'A withdrawn draft line must never create a Mission.');
    }

    // ── No-op ─────────────────────────────────────────────────────────────────

    public function test_unchanged_line_is_reported_as_unchanged_and_writes_no_audit_event(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $version = $this->makeVersion($site, $manager);
        $mission = $this->makeMission($version, $site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr);

        $response = $this->postJson(
            $client, $token,
            '/api/planning/versions/' . $version->getId() . '/apply-modifications',
            ['lines' => [$this->lineFor($mission)]], // identical to current state
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertSame(1, $body['unchanged'] ?? null, json_encode($body));

        $events = $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $mission->getId()]);
        self::assertEmpty($events, 'No audit event should be written for a genuine no-op line.');
    }

    // ── Authorization ─────────────────────────────────────────────────────────

    public function test_apply_modifications_returns_403_for_instrumentist(): void
    {
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $token    = $this->login($client, $instr);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $site     = $this->makeSite();
        $version  = $this->makeVersion($site, $manager);

        $response = $this->postJson(
            $client, $token,
            '/api/planning/versions/' . $version->getId() . '/apply-modifications',
            ['lines' => []],
        );

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    // ── Notification targeting — real diff, real dispatch, captured on the in-memory
    // async transport (when@test: async -> in-memory://). Proves the full chain from HTTP
    // request to SendBillingEmailMessage dispatch, independent of any Mailpit/worker state.

    /** @return SendBillingEmailMessage[] */
    private function drainSentMessages(InMemoryTransport $transport): array
    {
        return array_map(
            static fn ($envelope) => $envelope->getMessage(),
            $transport->getSent(),
        );
    }

    private function emailsTo(array $messages, string $email): array
    {
        return array_values(array_filter(
            $messages,
            fn (SendBillingEmailMessage $m) => $m->to === $email,
        ));
    }

    public function test_reassignment_notifies_old_instrumentist_new_instrumentist_and_surgeon_only(): void
    {
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $token    = $this->login($client, $manager);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $oldInstr = $this->createUser('ROLE_INSTRUMENTIST');
        $newInstr = $this->createUser('ROLE_INSTRUMENTIST');
        $unrelatedInstr = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();
        $version  = $this->makeVersion($site, $manager);
        $mission  = $this->makeMission($version, $site, $surgeon, $manager, MissionStatus::ASSIGNED, $oldInstr);
        // Unrelated mission, untouched by this redeploy — its instrumentist must receive nothing.
        $this->makeMission($version, $site, $surgeon, $manager, MissionStatus::ASSIGNED, $unrelatedInstr);

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $response = $this->postJson(
            $client, $token,
            '/api/planning/versions/' . $version->getId() . '/apply-modifications',
            ['lines' => [$this->lineFor($mission, ['instrumentistId' => $newInstr->getId()])]],
        );
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());

        $sent = $this->drainSentMessages($transport);
        self::assertCount(3, $sent, 'Exactly 3 recipients: old instrumentist, new instrumentist, surgeon — no one else.');

        $toOld = $this->emailsTo($sent, $oldInstr->getEmail());
        $toNew = $this->emailsTo($sent, $newInstr->getEmail());
        $toSurgeon = $this->emailsTo($sent, $surgeon->getEmail());
        $toUnrelated = $this->emailsTo($sent, $unrelatedInstr->getEmail());

        self::assertCount(1, $toOld, 'Old instrumentist must be informed the mission was taken from them.');
        self::assertCount(1, $toNew, 'New instrumentist must be informed the mission was assigned to them.');
        self::assertCount(1, $toSurgeon, 'Surgeon must be informed their intervention\'s instrumentist changed.');
        self::assertCount(0, $toUnrelated, 'Instrumentist with an untouched mission in the same version must receive nothing.');

        // One consolidated email per recipient, not one per mutated field.
        self::assertStringContainsString($oldInstr->getFirstname() ?? '', $toOld[0]->context['instrumentist']->getFirstname() ?? '');
    }

    public function test_schedule_change_notifies_surgeon_and_instrumentist_only(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $otherSurgeon = $this->createUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $version = $this->makeVersion($site, $manager);
        $mission = $this->makeMission($version, $site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr);
        // Untouched mission for an unrelated surgeon in the same version.
        $this->makeMission($version, $site, $otherSurgeon, $manager, MissionStatus::OPEN);

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $response = $this->postJson(
            $client, $token,
            '/api/planning/versions/' . $version->getId() . '/apply-modifications',
            ['lines' => [$this->lineFor($mission, ['startTime' => '09:00', 'endTime' => '14:00'])]],
        );
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());

        $sent = $this->drainSentMessages($transport);
        self::assertCount(2, $sent, 'Only the surgeon and the instrumentist of the rescheduled mission.');
        self::assertCount(1, $this->emailsTo($sent, $surgeon->getEmail()));
        self::assertCount(1, $this->emailsTo($sent, $instr->getEmail()));
        self::assertCount(0, $this->emailsTo($sent, $otherSurgeon->getEmail()), 'Unrelated surgeon with an untouched OPEN mission must receive nothing.');
    }

    public function test_new_mission_notifies_only_its_own_surgeon_and_instrumentist(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $unrelatedSurgeon = $this->createUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $version = $this->makeVersion($site, $manager);
        // Pre-existing untouched mission — its surgeon must receive nothing.
        $this->makeMission($version, $site, $unrelatedSurgeon, $manager, MissionStatus::ASSIGNED, $this->createUser('ROLE_INSTRUMENTIST'));

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $newLine = [
            'date' => '2026-09-20', 'postId' => -1, 'surgeonId' => $surgeon->getId(), 'surgeonName' => '',
            'missionType' => 'BLOCK', 'startTime' => '08:00', 'endTime' => '13:00',
            'siteId' => $site->getId(), 'siteName' => '', 'instrumentistId' => $instr->getId(),
            'instrumentistName' => null, 'status' => 'COVERED', 'existingMissionId' => null,
            'existingInstrumentistId' => null, 'existingInstrumentistName' => null, 'freedFrom' => false,
        ];

        $response = $this->postJson(
            $client, $token,
            '/api/planning/versions/' . $version->getId() . '/apply-modifications',
            ['lines' => [$newLine]],
        );
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());

        $sent = $this->drainSentMessages($transport);
        self::assertCount(2, $sent, 'Only the new mission\'s own surgeon and instrumentist.');
        self::assertCount(1, $this->emailsTo($sent, $surgeon->getEmail()));
        self::assertCount(1, $this->emailsTo($sent, $instr->getEmail()));
        self::assertCount(0, $this->emailsTo($sent, $unrelatedSurgeon->getEmail()));
    }

    public function test_cancellation_of_assigned_mission_notifies_surgeon_and_instrumentist_only(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $unrelatedInstr = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $version = $this->makeVersion($site, $manager);
        $mission = $this->makeMission($version, $site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr);
        $this->makeMission($version, $site, $surgeon, $manager, MissionStatus::ASSIGNED, $unrelatedInstr);

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $response = $this->postJson(
            $client, $token,
            '/api/planning/versions/' . $version->getId() . '/apply-modifications',
            ['lines' => [$this->lineFor($mission, ['status' => 'SKIPPED'])]],
        );
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertSame(1, $body['cancelled'] ?? null, json_encode($body));

        $this->em->clear();
        $reloaded = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::CANCELLED, $reloaded->getStatus());

        $sent = $this->drainSentMessages($transport);
        $toSurgeon = $this->emailsTo($sent, $surgeon->getEmail());
        $toInstr   = $this->emailsTo($sent, $instr->getEmail());
        $toUnrelated = $this->emailsTo($sent, $unrelatedInstr->getEmail());

        self::assertCount(1, $toSurgeon, 'Surgeon of the cancelled mission must be notified.');
        self::assertCount(1, $toInstr, 'Instrumentist who was on the cancelled mission must be notified.');
        self::assertCount(0, $toUnrelated, 'Instrumentist on the OTHER, untouched mission must receive nothing.');

        // Document what the diff mechanism actually reports for a cancellation: the composite
        // matching key (site+surgeon+type+date+roundedStart) is unchanged by cancel() — only
        // status and instrumentist change, and status is never compared (PlanningDiffService::
        // detectChanges() only looks at schedule/instrumentist/surgeon/site). A cancellation
        // therefore surfaces as a "modified" entry (instrumentist -> null), not a "removed"
        // entry — there is currently no diff-level distinction between "released back to the
        // open pool" and "cancelled". Both recipients are still correctly notified; the content
        // is just framed as an instrumentist change rather than a dedicated "cancelled" line.
        $instrContext = $toInstr[0]->context;
        self::assertNotEmpty($instrContext['modified'], 'Cancellation currently surfaces via the modified[] section, not removed[] — see comment above.');
    }
}
