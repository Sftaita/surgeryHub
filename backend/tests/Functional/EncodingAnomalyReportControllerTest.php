<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\EncodingAnomalyReport;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot 6 (D-100) — EncodingAnomalyReport : création chirurgien (self-scopée, mission
 * éligible, un seul OPEN à la fois), liste self-scopée + manager, résolution
 * manager/admin-only en V1 (jamais couplée à une correction d'encodage, §17),
 * concurrence (double résolution).
 */
final class EncodingAnomalyReportControllerTest extends WebTestCase
{
    private const PASSWORD = 'Lot6Test15!';
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private array $createdIds = ['missions' => [], 'users' => [], 'sites' => [], 'reports' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->clear();
            if (!empty($this->createdIds['missions']) || !empty($this->createdIds['users'])) {
                $qb = $this->em->createQueryBuilder()->select('a')->from(AuditEvent::class, 'a');
                $conditions = [];
                if (!empty($this->createdIds['missions'])) {
                    $conditions[] = 'a.mission IN (:missionIds)';
                    $qb->setParameter('missionIds', $this->createdIds['missions']);
                }
                if (!empty($this->createdIds['users'])) {
                    $conditions[] = 'a.actor IN (:userIds)';
                    $qb->setParameter('userIds', $this->createdIds['users']);
                }
                $qb->where(implode(' OR ', $conditions));
                foreach ($qb->getQuery()->getResult() as $evt) {
                    $this->em->remove($evt);
                }
                $this->em->flush();
            }
            foreach ($this->createdIds['reports'] as $id) {
                $e = $this->em->find(EncodingAnomalyReport::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $id) {
                $e = $this->em->find(Mission::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['users'] as $id) {
                $e = $this->em->find(User::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            foreach ($this->createdIds['sites'] as $id) {
                $e = $this->em->find(Hospital::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        // Sans disableReboot(), chaque requête HTTP reboote le kernel → nouveau
        // container → l'InMemoryTransport capturé par les assertions de dispatch
        // devient obsolète (voir SurgeonMissionRequestControllerTest, même besoin).
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('anomaly-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Anomaly');
        $u->setLastname('Test');
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
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

    private function request(KernelBrowser $client, string $method, string $uri, ?string $token = null, ?array $body = null): Response
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $client->request($method, $uri, server: $server, content: $body !== null ? json_encode($body) : null);
        return $client->getResponse();
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('AnomalySite-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeMission(User $surgeon, User $instr, Hospital $site, MissionStatus $status): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setSurgeon($surgeon);
        $m->setInstrumentist($instr);
        $m->setSite($site);
        $m->setCreatedBy($surgeon);
        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
        $m->setStartAt($now->modify('-2 hours'));
        $m->setEndAt($now->modify('+1 hour'));
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    private function bootSurgeonMissionInstr(MissionStatus $status = MissionStatus::VALIDATED): array
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, $status);
        $token = $this->login($client, $surgeon);
        return [$client, $surgeon, $mission, $token];
    }

    private function createReport(KernelBrowser $client, string $token, int $missionId, string $type = EncodingAnomalyReport::TYPE_MATERIAL_INCORRECT, string $comment = 'Matériel manquant sur la fiche.'): int
    {
        $response = $this->request($client, 'POST', "/api/missions/{$missionId}/encoding-anomaly-reports", $token, [
            'type' => $type,
            'comment' => $comment,
        ]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $id = json_decode($response->getContent(), true)['id'];
        $this->createdIds['reports'][] = $id;
        return $id;
    }

    // ── §25 Création ─────────────────────────────────────────────────────────

    public function test_surgeon_can_create_report_for_own_mission(): void
    {
        [$client, $surgeon, $mission, $token] = $this->bootSurgeonMissionInstr();

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/encoding-anomaly-reports", $token, [
            'type' => EncodingAnomalyReport::TYPE_HOURS_INCORRECT,
            'comment' => 'Les heures encodées ne correspondent pas à la réalité.',
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->createdIds['reports'][] = $body['id'];
        self::assertSame('OPEN', $body['status']);
        self::assertSame(EncodingAnomalyReport::TYPE_HOURS_INCORRECT, $body['type']);
        self::assertSame($surgeon->getId(), $body['reporter']['id']);
        self::assertSame($mission->getId(), $body['missionId']);
        self::assertNull($body['resolvedBy']);

        $audits = $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $mission->getId()]);
        $types = array_map(static fn (AuditEvent $a) => $a->getEventType()->value, $audits);
        self::assertContains('ENCODING_ANOMALY_REPORTED', $types);
    }

    public function test_create_dispatches_created_message_to_managers(): void
    {
        [$client, , $mission, $token] = $this->bootSurgeonMissionInstr();
        $manager = $this->createUser('ROLE_MANAGER');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $this->createReport($client, $token, $mission->getId());

        $sent = array_values(array_filter(
            $transport->getSent(),
            static fn ($e) => $e->getMessage() instanceof \App\Message\EncodingAnomalyReportCreatedMessage,
        ));
        self::assertCount(1, $sent);
        self::assertContains($manager->getId(), $sent[0]->getMessage()->recipientUserIds);
    }

    public function test_surgeon_cannot_create_report_for_another_surgeons_mission(): void
    {
        [$client, , $mission] = $this->bootSurgeonMissionInstr();
        $attacker = $this->createUser('ROLE_SURGEON');
        $token = $this->login($client, $attacker);

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/encoding-anomaly-reports", $token, [
            'type' => EncodingAnomalyReport::TYPE_OTHER,
            'comment' => 'Tentative illégitime.',
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_instrumentist_cannot_create_report(): void
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::VALIDATED);
        $token = $this->login($client, $instr);

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/encoding-anomaly-reports", $token, [
            'type' => EncodingAnomalyReport::TYPE_OTHER,
            'comment' => 'Ne devrait jamais passer.',
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_invalid_type_is_rejected(): void
    {
        [$client, , $mission, $token] = $this->bootSurgeonMissionInstr();

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/encoding-anomaly-reports", $token, [
            'type' => 'NOT_A_TYPE',
            'comment' => 'Peu importe.',
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function test_empty_comment_is_rejected(): void
    {
        [$client, , $mission, $token] = $this->bootSurgeonMissionInstr();

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/encoding-anomaly-reports", $token, [
            'type' => EncodingAnomalyReport::TYPE_OTHER,
            'comment' => '   ',
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function test_ineligible_mission_status_is_rejected(): void
    {
        [$client, , $mission, $token] = $this->bootSurgeonMissionInstr(MissionStatus::OPEN);

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/encoding-anomaly-reports", $token, [
            'type' => EncodingAnomalyReport::TYPE_OTHER,
            'comment' => 'Mission sans instrumentiste réellement assigné.',
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function test_duplicate_open_report_is_conflict(): void
    {
        [$client, , $mission, $token] = $this->bootSurgeonMissionInstr();
        $this->createReport($client, $token, $mission->getId());

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/encoding-anomaly-reports", $token, [
            'type' => EncodingAnomalyReport::TYPE_OTHER,
            'comment' => 'Un second problème, alors que le premier est encore ouvert.',
        ]);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    public function test_new_report_possible_after_previous_one_resolved(): void
    {
        [$client, , $mission, $token] = $this->bootSurgeonMissionInstr();
        $firstId = $this->createReport($client, $token, $mission->getId());

        $manager = $this->createUser('ROLE_MANAGER');
        $tokenM = $this->login($client, $manager);
        $resolve = $this->request($client, 'POST', "/api/encoding-anomaly-reports/{$firstId}/resolve", $tokenM, [
            'resolutionComment' => 'Corrigé côté encodage.',
        ]);
        self::assertSame(Response::HTTP_OK, $resolve->getStatusCode(), $resolve->getContent());

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/encoding-anomaly-reports", $token, [
            'type' => EncodingAnomalyReport::TYPE_OTHER,
            'comment' => 'Un nouveau problème découvert plus tard.',
        ]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $this->createdIds['reports'][] = json_decode($response->getContent(), true)['id'];
    }

    // ── §25 Liste ────────────────────────────────────────────────────────────

    public function test_list_visible_to_owning_surgeon(): void
    {
        [$client, , $mission, $token] = $this->bootSurgeonMissionInstr();
        $this->createReport($client, $token, $mission->getId());

        $response = $this->request($client, 'GET', "/api/missions/{$mission->getId()}/encoding-anomaly-reports", $token);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertCount(1, $body);
    }

    public function test_list_forbidden_to_another_surgeon(): void
    {
        [$client, , $mission, $token] = $this->bootSurgeonMissionInstr();
        $this->createReport($client, $token, $mission->getId());

        $otherSurgeon = $this->createUser('ROLE_SURGEON');
        $tokenOther = $this->login($client, $otherSurgeon);

        $response = $this->request($client, 'GET', "/api/missions/{$mission->getId()}/encoding-anomaly-reports", $tokenOther);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_list_forbidden_to_unrelated_instrumentist(): void
    {
        [$client, , $mission, $token] = $this->bootSurgeonMissionInstr();
        $this->createReport($client, $token, $mission->getId());

        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $tokenI = $this->login($client, $instr);

        $response = $this->request($client, 'GET', "/api/missions/{$mission->getId()}/encoding-anomaly-reports", $tokenI);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_manager_can_list_reports_for_any_mission(): void
    {
        [$client, , $mission, $token] = $this->bootSurgeonMissionInstr();
        $this->createReport($client, $token, $mission->getId());

        $manager = $this->createUser('ROLE_MANAGER');
        $tokenM = $this->login($client, $manager);

        $response = $this->request($client, 'GET', "/api/missions/{$mission->getId()}/encoding-anomaly-reports", $tokenM);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertCount(1, $body);
    }

    // ── §25 Résolution ───────────────────────────────────────────────────────

    public function test_manager_can_resolve_report(): void
    {
        [$client, $surgeon, $mission, $token] = $this->bootSurgeonMissionInstr();
        $reportId = $this->createReport($client, $token, $mission->getId());

        $manager = $this->createUser('ROLE_MANAGER');
        $tokenM = $this->login($client, $manager);

        $response = $this->request($client, 'POST', "/api/encoding-anomaly-reports/{$reportId}/resolve", $tokenM, [
            'resolutionComment' => 'Matériel réencodé correctement.',
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertSame('RESOLVED', $body['status']);
        self::assertSame($manager->getId(), $body['resolvedBy']['id']);
        self::assertSame('Matériel réencodé correctement.', $body['resolutionComment']);
        self::assertNotNull($body['resolvedAt']);

        $audits = $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $mission->getId()]);
        $types = array_map(static fn (AuditEvent $a) => $a->getEventType()->value, $audits);
        self::assertContains('ENCODING_ANOMALY_RESOLVED', $types);
    }

    public function test_admin_can_resolve_report(): void
    {
        [$client, , $mission, $token] = $this->bootSurgeonMissionInstr();
        $reportId = $this->createReport($client, $token, $mission->getId());

        $admin = $this->createUser('ROLE_ADMIN');
        $tokenA = $this->login($client, $admin);

        $response = $this->request($client, 'POST', "/api/encoding-anomaly-reports/{$reportId}/resolve", $tokenA, [
            'resolutionComment' => 'Vérifié et corrigé.',
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
    }

    public function test_resolve_dispatches_resolved_message_to_surgeon(): void
    {
        [$client, $surgeon, $mission, $token] = $this->bootSurgeonMissionInstr();
        $reportId = $this->createReport($client, $token, $mission->getId());

        $manager = $this->createUser('ROLE_MANAGER');
        $tokenM = $this->login($client, $manager);

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $this->request($client, 'POST', "/api/encoding-anomaly-reports/{$reportId}/resolve", $tokenM, [
            'resolutionComment' => 'Corrigé.',
        ]);

        $sent = array_values(array_filter(
            $transport->getSent(),
            static fn ($e) => $e->getMessage() instanceof \App\Message\EncodingAnomalyReportResolvedMessage,
        ));
        self::assertCount(1, $sent);
        self::assertSame($surgeon->getId(), $sent[0]->getMessage()->surgeonId);
    }

    public function test_surgeon_cannot_resolve_own_report(): void
    {
        [$client, , $mission, $token] = $this->bootSurgeonMissionInstr();
        $reportId = $this->createReport($client, $token, $mission->getId());

        $response = $this->request($client, 'POST', "/api/encoding-anomaly-reports/{$reportId}/resolve", $token, [
            'resolutionComment' => 'Le chirurgien ne peut jamais résoudre lui-même en V1.',
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_instrumentist_cannot_resolve_report(): void
    {
        [$client, , $mission, $token] = $this->bootSurgeonMissionInstr();
        $reportId = $this->createReport($client, $token, $mission->getId());

        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $tokenI = $this->login($client, $instr);

        $response = $this->request($client, 'POST', "/api/encoding-anomaly-reports/{$reportId}/resolve", $tokenI, [
            'resolutionComment' => 'Aucune capacité de résolution instrumentiste en V1.',
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_resolve_requires_non_empty_resolution_comment(): void
    {
        [$client, , $mission, $token] = $this->bootSurgeonMissionInstr();
        $reportId = $this->createReport($client, $token, $mission->getId());

        $manager = $this->createUser('ROLE_MANAGER');
        $tokenM = $this->login($client, $manager);

        $response = $this->request($client, 'POST', "/api/encoding-anomaly-reports/{$reportId}/resolve", $tokenM, [
            'resolutionComment' => '   ',
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    // ── §25 Concurrence — double résolution ─────────────────────────────────

    public function test_double_resolve_second_call_returns_conflict(): void
    {
        [$client, , $mission, $token] = $this->bootSurgeonMissionInstr();
        $reportId = $this->createReport($client, $token, $mission->getId());

        $manager = $this->createUser('ROLE_MANAGER');
        $tokenM = $this->login($client, $manager);

        $first = $this->request($client, 'POST', "/api/encoding-anomaly-reports/{$reportId}/resolve", $tokenM, [
            'resolutionComment' => 'Première résolution.',
        ]);
        self::assertSame(Response::HTTP_OK, $first->getStatusCode(), $first->getContent());

        $second = $this->request($client, 'POST', "/api/encoding-anomaly-reports/{$reportId}/resolve", $tokenM, [
            'resolutionComment' => 'Seconde tentative, doit échouer.',
        ]);
        self::assertSame(Response::HTTP_CONFLICT, $second->getStatusCode());
    }

    // ── Correction jamais couplée automatiquement (§17) ─────────────────────

    /** Résoudre ne mute jamais MissionIntervention/MaterialLine — uniquement le signalement lui-même. */
    public function test_resolve_never_mutates_mission_encoding_data(): void
    {
        [$client, , $mission, $token] = $this->bootSurgeonMissionInstr();
        $reportId = $this->createReport($client, $token, $mission->getId());

        $interventionsCountBefore = (int) $this->em->createQuery(
            'SELECT COUNT(i.id) FROM App\Entity\MissionIntervention i WHERE i.mission = :m'
        )->setParameter('m', $mission)->getSingleScalarResult();

        $manager = $this->createUser('ROLE_MANAGER');
        $tokenM = $this->login($client, $manager);
        $this->request($client, 'POST', "/api/encoding-anomaly-reports/{$reportId}/resolve", $tokenM, [
            'resolutionComment' => 'Traité — la correction, si nécessaire, passe par les workflows existants.',
        ]);

        $interventionsCountAfter = (int) $this->em->createQuery(
            'SELECT COUNT(i.id) FROM App\Entity\MissionIntervention i WHERE i.mission = :m'
        )->setParameter('m', $mission)->getSingleScalarResult();
        self::assertSame($interventionsCountBefore, $interventionsCountAfter);
    }
}
