<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\Hospital;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionEncodingComment;
use App\Entity\MissionExecution;
use App\Entity\NotificationEvent;
use App\Entity\OutboundNotification;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot 7 (D-070) — full HTTP coverage of the encoding workflow:
 * start / complete / validate / reject / reopen, locking, and audit trail.
 */
final class MissionEncodingWorkflowControllerTest extends WebTestCase
{
    private const PASSWORD = 'Encoding7!';

    private EntityManagerInterface $em;
    private array $createdMissionIds = [];
    private array $createdUserIds    = [];
    private array $createdSiteIds    = [];
    private array $createdFirmIds    = [];
    private array $createdMaterialItemIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdMissionIds as $missionId) {
                foreach ($this->em->getRepository(MissionEncodingComment::class)->findBy(['mission' => $missionId]) as $c) {
                    $this->em->remove($c);
                }
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $missionId]) as $evt) {
                    $this->em->remove($evt);
                }
                foreach ($this->em->getRepository(MaterialLine::class)->findBy(['mission' => $missionId]) as $line) {
                    $this->em->remove($line);
                }
                // D-143 — relance manuelle et soumission alimentent le centre de notifications ;
                // les heures réelles vivent dans MissionExecution.
                foreach ($this->em->getRepository(NotificationEvent::class)->findBy(['mission' => $missionId]) as $n) {
                    $this->em->remove($n);
                }
                foreach ($this->em->getRepository(MissionExecution::class)->findBy(['mission' => $missionId]) as $e) {
                    $this->em->remove($e);
                }
            }
            // D-120 — la relance manuelle trace une OutboundNotification (push et/ou repli
            // email) sur l'instrumentiste : FK recipient_user non nullable.
            foreach ($this->createdUserIds as $userId) {
                foreach ($this->em->getRepository(OutboundNotification::class)->findBy(['recipientUser' => $userId]) as $n) {
                    $this->em->remove($n);
                }
                foreach ($this->em->getRepository(NotificationEvent::class)->findBy(['user' => $userId]) as $n) {
                    $this->em->remove($n);
                }
            }
            $this->em->flush();

            foreach ($this->createdMissionIds as $id) {
                $e = $this->em->find(Mission::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();

            foreach ($this->createdMaterialItemIds as $id) {
                $e = $this->em->find(MaterialItem::class, $id);
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
            foreach ($this->createdFirmIds as $id) {
                $e = $this->em->find(Firm::class, $id);
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
        $u->setEmail('lot7-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
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
        $h->setName('Lot7-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdSiteIds[] = $h->getId();
        return $h;
    }

    /**
     * startAt defaults to one hour in the past so instrumentist encoding actions are allowed.
     * type defaults to BLOCK — MissionType::CONSULTATION is passed explicitly where the
     * "no material required" exemption is the point of the test.
     */
    private function makeMission(
        Hospital $site,
        User $surgeon,
        User $createdBy,
        MissionStatus $status,
        ?User $instrumentist = null,
        ?\DateTimeImmutable $startAt = null,
        MissionType $type = MissionType::BLOCK,
    ): Mission {
        $m = new Mission();
        $m->setType($type);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($createdBy);
        $m->setStartAt($startAt ?? new \DateTimeImmutable('-1 hour'));
        $m->setEndAt(new \DateTimeImmutable('+1 hour'));
        $m->setStatus($status);
        if ($instrumentist !== null) {
            $m->setInstrumentist($instrumentist);
        }
        $this->em->persist($m);
        $this->em->flush();
        $this->createdMissionIds[] = $m->getId();
        return $m;
    }

    /** D-143 — heures réelles complètes (obligatoires pour soumettre et valider). */
    private function giveActualHours(Mission $mission, ?string $start = '-1 hour', ?string $end = 'now'): MissionExecution
    {
        $execution = new MissionExecution();
        $execution->setMission($mission);
        $execution->setActualStartAt($start !== null ? new \DateTimeImmutable($start) : null);
        $execution->setActualEndAt($end !== null ? new \DateTimeImmutable($end) : null);
        $mission->setExecution($execution);
        $this->em->persist($execution);
        $this->em->flush();

        return $execution;
    }

    private function makeMaterialLine(Mission $mission, User $createdBy, string $quantity = '1.00'): MaterialLine
    {
        $firm = new Firm();
        $firm->setName('Lot7-Firm-' . bin2hex(random_bytes(3)));
        $this->em->persist($firm);

        $item = new MaterialItem();
        $item->setFirm($firm);
        $item->setReferenceCode('REF-' . bin2hex(random_bytes(3)));
        $item->setLabel('Vis titane');
        $item->setUnit('u');
        $this->em->persist($item);
        $this->em->flush();
        $this->createdFirmIds[] = $firm->getId();
        $this->createdMaterialItemIds[] = $item->getId();

        $line = new MaterialLine();
        $line->setMission($mission);
        $line->setItem($item);
        $line->setQuantity($quantity);
        $line->setCreatedBy($createdBy);
        $this->em->persist($line);
        $this->em->flush();

        return $line;
    }

    private function postJson(KernelBrowser $client, string $token, string $uri, array $body = []): Response
    {
        $client->request('POST', $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode($body),
        );
        return $client->getResponse();
    }

    private function getJson(KernelBrowser $client, string $token, string $uri): Response
    {
        $client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        return $client->getResponse();
    }

    private function auditEventTypesFor(int $missionId): array
    {
        $events = $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $missionId], ['id' => 'ASC']);
        return array_map(static fn (AuditEvent $e) => $e->getEventType()->value, $events);
    }

    // ── start ────────────────────────────────────────────────────────────────

    public function test_instrumentist_can_start_encoding_on_assigned_mission(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr);
        $token   = $this->login($client, $instr);

        $response = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/start");

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('ENCODING_IN_PROGRESS', $data['status']);
        self::assertContains(AuditEventType::MISSION_ENCODING_STARTED->value, $this->auditEventTypesFor($mission->getId()));
    }

    /**
     * MissionVoter::canEncodingStart() requires the exact same status set (ASSIGNED |
     * IN_PROGRESS) as MissionEncodingWorkflowService::start()'s own check — so an
     * out-of-status attempt is denied at the Voter layer (403) before the service's
     * ConflictHttpException can ever be reached through this HTTP path. The service check
     * remains defense-in-depth for any future/direct caller that bypasses the Voter.
     */
    public function test_start_encoding_rejected_when_mission_not_assigned_or_in_progress(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::SUBMITTED, $instr);
        $token   = $this->login($client, $instr);

        $response = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/start");

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_manager_cannot_start_encoding(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr);
        $token   = $this->login($client, $manager);

        $response = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/start");

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_unrelated_instrumentist_cannot_start_encoding(): void
    {
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $stranger = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();
        $mission  = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr);
        $token    = $this->login($client, $stranger);

        $response = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/start");

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    // ── complete ─────────────────────────────────────────────────────────────

    public function test_instrumentist_can_complete_encoding(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ENCODING_IN_PROGRESS, $instr);
        $this->giveActualHours($mission);
        $token   = $this->login($client, $instr);

        // Mission sans ligne de matériel (fixture minimale) : commentaire requis, voir
        // MissionEncodingWorkflowServiceTest pour la règle elle-même.
        $response = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/complete", [
            'comment' => 'Consultation sans matériel utilisé.',
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('SUBMITTED', $data['status']);
        self::assertSame('Consultation sans matériel utilisé.', $data['noMaterialComment']);
        self::assertTrue($data['submittedWithoutMaterial']);
        self::assertContains(AuditEventType::MISSION_ENCODING_COMPLETED->value, $this->auditEventTypesFor($mission->getId()));
    }

    /**
     * Point 5 de la revue pré-déploiement : noMaterialComment/submittedWithoutMaterial
     * n'existent que dans MissionDetailDto (le récapitulatif de mission, GET
     * /api/missions/{id}) — jamais dans le DTO d'encodage (GET .../encoding), qui reste
     * dédié au formulaire de saisie (interventions/matériel/catalogue).
     */
    public function test_no_material_comment_is_only_exposed_on_mission_detail_not_encoding_dto(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ENCODING_IN_PROGRESS, $instr);
        $this->giveActualHours($mission);
        $token   = $this->login($client, $instr);

        $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/complete", [
            'comment' => 'Consultation sans matériel utilisé.',
        ]);

        $detail = json_decode((string) $this->getJson($client, $token, "/api/missions/{$mission->getId()}")->getContent(), true);
        self::assertArrayHasKey('noMaterialComment', $detail);
        self::assertArrayHasKey('submittedWithoutMaterial', $detail);

        $encoding = json_decode((string) $this->getJson($client, $token, "/api/missions/{$mission->getId()}/encoding")->getContent(), true);
        self::assertArrayNotHasKey('noMaterialComment', $encoding);
        self::assertArrayNotHasKey('submittedWithoutMaterial', $encoding);
    }

    /**
     * Point 2 de la revue pré-déploiement (bout en bout HTTP) : soumission sans matériel
     * avec commentaire, correction (reject), ajout d'une ligne de matériel réelle, nouvelle
     * soumission sans commentaire. Le commentaire précédent reste en base (traçabilité) mais
     * submittedWithoutMaterial doit refléter la soumission courante — c'est ce flag, pas la
     * seule présence du commentaire, qui doit gouverner l'affichage manager.
     */
    public function test_resubmit_with_material_after_no_material_justification(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ENCODING_IN_PROGRESS, $instr);
        $this->giveActualHours($mission);
        $instrToken   = $this->login($client, $instr);
        $managerToken = $this->login($client, $manager);

        // 1. Soumission sans matériel, commentaire obligatoire.
        $first = $this->postJson($client, $instrToken, "/api/missions/{$mission->getId()}/encoding/complete", [
            'comment' => 'Consultation sans matériel utilisé.',
        ]);
        self::assertSame(Response::HTTP_OK, $first->getStatusCode());
        $firstData = json_decode((string) $first->getContent(), true);
        self::assertTrue($firstData['submittedWithoutMaterial']);

        // 2. Correction par le manager.
        self::assertSame(
            Response::HTTP_OK,
            $this->postJson($client, $managerToken, "/api/missions/{$mission->getId()}/encoding/reject", [
                'comment' => 'Merci de vérifier le matériel utilisé.',
            ])->getStatusCode(),
        );

        // 3. Ajout d'une ligne de matériel réelle. Le client HTTP reboote le kernel à
        //    chaque requête (WebTestCase) : $mission/$instr sont détachés de l'EM
        //    courant, on les recharge par id avant de les référencer dans une nouvelle
        //    entité gérée.
        $this->makeMaterialLine(
            $this->em->find(Mission::class, $mission->getId()),
            $this->em->find(User::class, $instr->getId()),
        );

        // 4. Nouvelle soumission, sans commentaire : ne doit pas être bloquée puisque du
        //    matériel est désormais présent.
        $second = $this->postJson($client, $instrToken, "/api/missions/{$mission->getId()}/encoding/complete");
        self::assertSame(Response::HTTP_OK, $second->getStatusCode());
        $secondData = json_decode((string) $second->getContent(), true);
        self::assertSame('SUBMITTED', $secondData['status']);
        self::assertFalse($secondData['submittedWithoutMaterial']);
        self::assertSame(
            'Consultation sans matériel utilisé.',
            $secondData['noMaterialComment'],
            'Le commentaire précédent doit rester en base pour traçabilité.',
        );
    }

    public function test_complete_encoding_without_material_and_without_comment_is_rejected(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ENCODING_IN_PROGRESS, $instr);
        $this->giveActualHours($mission);
        $token   = $this->login($client, $instr);

        $response = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/complete");

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    /**
     * Une mission CONSULTATION n'attend jamais de matériel — contrairement au test
     * précédent (mission BLOCK par défaut), la clôture sans matériel ni commentaire doit
     * réussir.
     */
    public function test_complete_consultation_encoding_without_material_and_without_comment_succeeds(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission(
            $site, $surgeon, $manager, MissionStatus::ENCODING_IN_PROGRESS, $instr,
            type: MissionType::CONSULTATION,
        );
        $this->giveActualHours($mission);
        $token   = $this->login($client, $instr);

        $response = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/complete");

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('SUBMITTED', $data['status']);
        self::assertTrue($data['submittedWithoutMaterial']);
        self::assertNull($data['noMaterialComment']);
    }

    // ── validate ─────────────────────────────────────────────────────────────

    public function test_manager_can_validate_submitted_mission(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::SUBMITTED, $instr);
        $this->giveActualHours($mission);
        $token   = $this->login($client, $manager);

        $response = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/validate");

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('VALIDATED', $data['status']);
        self::assertContains(AuditEventType::MISSION_ENCODING_VALIDATED->value, $this->auditEventTypesFor($mission->getId()));

        $reloaded = $this->em->find(Mission::class, $mission->getId());
        self::assertNotNull($reloaded->getEncodingLockedAt());
    }

    public function test_instrumentist_cannot_validate(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::SUBMITTED, $instr);
        $token   = $this->login($client, $instr);

        $response = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/validate");

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    // ── reject ───────────────────────────────────────────────────────────────

    public function test_manager_can_reject_with_comment_and_comment_is_persisted(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::SUBMITTED, $instr);
        $token   = $this->login($client, $manager);

        $response = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/reject", [
            'comment' => 'Firme incorrecte sur la ligne matériel n°3',
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('ENCODING_IN_PROGRESS', $data['status']);
        self::assertContains(AuditEventType::MISSION_ENCODING_REJECTED->value, $this->auditEventTypesFor($mission->getId()));

        $comments = $this->em->getRepository(MissionEncodingComment::class)->findBy(['mission' => $mission->getId()]);
        self::assertCount(1, $comments);
        self::assertSame('Firme incorrecte sur la ligne matériel n°3', $comments[0]->getComment());

        // The comment must be exposed and historized on GET .../encoding — never lost.
        $encodingResponse = $this->getJson($client, $token, "/api/missions/{$mission->getId()}/encoding");
        self::assertSame(Response::HTTP_OK, $encodingResponse->getStatusCode());
        $encodingData = json_decode((string) $encodingResponse->getContent(), true);
        self::assertCount(1, $encodingData['encodingComments']);
        self::assertSame('Firme incorrecte sur la ligne matériel n°3', $encodingData['encodingComments'][0]['comment']);
    }

    public function test_reject_without_comment_returns_422(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::SUBMITTED, $instr);
        $token   = $this->login($client, $manager);

        $response = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/reject", []);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function test_reject_rejected_when_mission_not_submitted(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr);
        $token   = $this->login($client, $manager);

        $response = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/reject", [
            'comment' => 'Peu importe',
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    // ── reopen ───────────────────────────────────────────────────────────────

    public function test_manager_can_reopen_validated_mission(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::VALIDATED, $instr);
        $mission->setEncodingLockedAt(new \DateTimeImmutable());
        $this->em->flush();
        $token = $this->login($client, $manager);

        $response = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/reopen", [
            'comment' => 'Merci de vérifier la quantité de vis',
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('ENCODING_IN_PROGRESS', $data['status']);
        self::assertContains(AuditEventType::MISSION_ENCODING_REOPENED->value, $this->auditEventTypesFor($mission->getId()));

        $reloaded = $this->em->find(Mission::class, $mission->getId());
        self::assertNull($reloaded->getEncodingLockedAt());
    }

    public function test_reopen_without_comment_is_allowed(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::VALIDATED, $instr);
        $token   = $this->login($client, $manager);

        $response = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/reopen", []);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function test_closed_mission_can_never_be_reopened(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::CLOSED, $instr);
        $token   = $this->login($client, $manager);

        $response = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/reopen", [
            'comment' => 'Tentative',
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_instrumentist_cannot_reopen(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::VALIDATED, $instr);
        $token   = $this->login($client, $instr);

        $response = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/reopen", []);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    // ── full lifecycle + locking ────────────────────────────────────────────

    public function test_full_lifecycle_produces_ordered_audit_trail(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr);
        $this->giveActualHours($mission);
        $instrToken  = $this->login($client, $instr);
        $managerToken = $this->login($client, $manager);

        // Mission sans ligne de matériel (fixture minimale) : commentaire requis à chaque
        // complete() — voir test_complete_encoding_without_material_and_without_comment_is_rejected.
        $completeBody = ['comment' => 'Consultation sans matériel utilisé.'];

        self::assertSame(Response::HTTP_OK, $this->postJson($client, $instrToken, "/api/missions/{$mission->getId()}/encoding/start")->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->postJson($client, $instrToken, "/api/missions/{$mission->getId()}/encoding/complete", $completeBody)->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->postJson($client, $managerToken, "/api/missions/{$mission->getId()}/encoding/reject", ['comment' => 'Matériel manquant'])->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->postJson($client, $instrToken, "/api/missions/{$mission->getId()}/encoding/complete", $completeBody)->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->postJson($client, $managerToken, "/api/missions/{$mission->getId()}/encoding/validate")->getStatusCode());

        self::assertSame(
            [
                AuditEventType::MISSION_ENCODING_STARTED->value,
                AuditEventType::MISSION_ENCODING_COMPLETED->value,
                AuditEventType::MISSION_ENCODING_REJECTED->value,
                AuditEventType::MISSION_ENCODING_COMPLETED->value,
                AuditEventType::MISSION_ENCODING_VALIDATED->value,
            ],
            $this->auditEventTypesFor($mission->getId()),
        );

        // Locked: no further transition works until reopen.
        $lockedStart = $this->postJson($client, $instrToken, "/api/missions/{$mission->getId()}/encoding/start");
        self::assertSame(Response::HTTP_FORBIDDEN, $lockedStart->getStatusCode());
    }

    /**
     * H5b (D-132, §22/§33) — the same chain read from the global history: each transition
     * once (AuditEvent mirrors), the correction between rejection and resubmission as ONE
     * readable material change (before → after), never a field-by-field autosave noise.
     */
    public function test_submit_reject_correct_validate_chain_is_readable_in_the_activity_history(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr);
        $line    = $this->makeMaterialLine($mission, $instr, '1.00');
        $this->giveActualHours($mission); // D-143 — heures réelles obligatoires à la soumission
        $id      = $mission->getId();
        $instrToken   = $this->login($client, $instr);
        $managerToken = $this->login($client, $manager);

        self::assertSame(Response::HTTP_OK, $this->postJson($client, $instrToken, "/api/missions/$id/encoding/start")->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->postJson($client, $instrToken, "/api/missions/$id/encoding/complete")->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->postJson($client, $managerToken, "/api/missions/$id/encoding/reject", ['comment' => 'Il y avait deux vis'])->getStatusCode());
        $client->request('PATCH', "/api/missions/$id/material-lines/{$line->getId()}",
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $instrToken],
            content: json_encode(['quantity' => '2.00']));
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertSame(Response::HTTP_OK, $this->postJson($client, $instrToken, "/api/missions/$id/encoding/complete")->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->postJson($client, $managerToken, "/api/missions/$id/encoding/validate")->getStatusCode());

        $events = static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAllAssociative(
            "SELECT event_type, summary, payload, domain FROM activity_event WHERE mission_id = ? AND domain = 'ENCODING' ORDER BY occurred_at, id", [$id]);
        self::assertSame([
            'MISSION_MATERIAL_LINE_ADDED', // the fixture's line, first encoded
            'MISSION_ENCODING_STARTED', 'MISSION_ENCODING_COMPLETED', 'MISSION_ENCODING_REJECTED',
            'MISSION_MATERIAL_LINE_UPDATED', 'MISSION_ENCODING_COMPLETED', 'MISSION_ENCODING_VALIDATED',
        ], array_column($events, 'event_type'));
        self::assertStringContainsString('« Vis titane »', $events[4]['summary']);
        self::assertEquals(['quantity'], array_column(json_decode($events[4]['payload'], true)['changes'], 'field'));
        $rejected = json_decode($events[3]['payload'], true);
        self::assertTrue($rejected['hasReason'] ?? false, 'the reason exists…');
        self::assertStringNotContainsString('deux vis', $events[3]['payload'] . $events[3]['summary'], '…but its free text never reaches the history');
    }

    // ── remind (D-120) ───────────────────────────────────────────────────────

    public function test_manager_can_remind_assigned_mission_without_touching_automatic_guard(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $manager->setFirstname('Marie')->setLastname('Manager');
        $this->em->flush();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr);
        $token   = $this->login($client, $manager);

        $response = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/remind");

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('ASSIGNED', $data['status'], 'une relance ne mute jamais le statut');
        self::assertNotNull($data['lastManualReminderAt']);
        self::assertSame('Marie Manager', $data['lastManualReminderByName']);
        self::assertContains($data['lastManualReminderChannel'], ['push', 'email'], 'D-143 — le manager sait par quel canal la relance est partie');
        self::assertNull($data['automaticReminderSentAt']);
        self::assertContains('remind', $data['allowedActions']);
        self::assertContains(AuditEventType::MISSION_ENCODING_MANUAL_REMINDER_SENT->value, $this->auditEventTypesFor($mission->getId()));

        $this->em->clear();
        $reloaded = $this->em->find(Mission::class, $mission->getId());
        self::assertNull($reloaded->getEncodingReminderSentAt(), 'le garde-fou du rappel automatique (D-083) reste intact');
        self::assertNotEmpty(
            $this->em->getRepository(OutboundNotification::class)->findBy(['recipientUser' => $instr->getId(), 'mission' => $mission->getId()]),
            "l'instrumentiste a bien été notifié",
        );
    }

    public function test_instrumentist_cannot_remind(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr);
        $token   = $this->login($client, $instr);

        $response = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/remind");

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_remind_is_denied_once_encoding_is_submitted_or_without_instrumentist(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $submitted = $this->makeMission($site, $surgeon, $manager, MissionStatus::SUBMITTED, $instr);
        $unassigned = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN);
        $token   = $this->login($client, $manager);

        self::assertSame(Response::HTTP_FORBIDDEN, $this->postJson($client, $token, "/api/missions/{$submitted->getId()}/encoding/remind")->getStatusCode());
        self::assertSame(Response::HTTP_FORBIDDEN, $this->postJson($client, $token, "/api/missions/{$unassigned->getId()}/encoding/remind")->getStatusCode());
        self::assertNotContains(AuditEventType::MISSION_ENCODING_MANUAL_REMINDER_SENT->value, $this->auditEventTypesFor($submitted->getId()));
    }

    // ── D-143 — horaires réels obligatoires ──────────────────────────────────

    /** Scénario 3 — soumission sans horaires : refus backend explicite, brouillon intact. */
    public function test_complete_without_actual_hours_is_refused_and_keeps_the_draft(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ENCODING_IN_PROGRESS, $instr);
        $line    = $this->makeMaterialLine($mission, $instr);
        $token   = $this->login($client, $instr);

        $response = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/complete");

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $error = json_decode((string) $response->getContent(), true)['error'];
        self::assertSame('ENCODING_INCOMPLETE', $error['code']);
        self::assertStringContainsString('heures prestées (début et fin)', $error['message']);
        self::assertSame(['ACTUAL_START_TIME', 'ACTUAL_END_TIME'], array_column($error['missing'], 'code'));
        self::assertSame(['hours', 'hours'], array_column($error['missing'], 'section'));

        $this->em->clear();
        $reloaded = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::ENCODING_IN_PROGRESS, $reloaded->getStatus(), 'seule la transition est refusée');
        self::assertNull($reloaded->getSubmittedAt());
        self::assertNotNull($this->em->find(MaterialLine::class, $line->getId()), 'le brouillon est conservé');
        // Scénario 6 — jamais de copie implicite du planifié dans le réel.
        self::assertNull($reloaded->getExecution(), 'aucune heure réelle inventée à partir du planning');
        self::assertNotContains(AuditEventType::MISSION_ENCODING_COMPLETED->value, $this->auditEventTypesFor($mission->getId()));
    }

    /** Scénario 4 — début seul : soumission refusée, seule la fin est signalée manquante. */
    public function test_complete_with_only_start_time_is_refused(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ENCODING_IN_PROGRESS, $instr);
        $this->giveActualHours($mission, '-1 hour', null);
        $this->makeMaterialLine($mission, $instr);
        $token   = $this->login($client, $instr);

        $response = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/complete");

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $error = json_decode((string) $response->getContent(), true)['error'];
        self::assertSame(['ACTUAL_END_TIME'], array_column($error['missing'], 'code'));
    }

    /** Une durée seule (saisie antérieure à D-143) ne remplace jamais début + fin. */
    public function test_complete_with_duration_only_is_refused(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ENCODING_IN_PROGRESS, $instr);
        $execution = $this->giveActualHours($mission, null, null);
        $execution->setActualDurationMinutes(300);
        $this->em->flush();
        $this->makeMaterialLine($mission, $instr);
        $token   = $this->login($client, $instr);

        $response = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/complete");

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    /** Scénario 5 — début et fin cohérents + autres exigences : soumission autorisée. */
    public function test_complete_with_coherent_hours_and_material_succeeds(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ENCODING_IN_PROGRESS, $instr);
        $this->giveActualHours($mission);
        $this->makeMaterialLine($mission, $instr);
        $token   = $this->login($client, $instr);

        $response = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/complete");

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('SUBMITTED', json_decode((string) $response->getContent(), true)['status']);
    }

    /** Une mission soumise avant D-143 sans heures réelles ne peut pas être validée sur le planifié. */
    public function test_manager_cannot_validate_a_submitted_mission_without_actual_hours(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::SUBMITTED, $instr);
        $token   = $this->login($client, $manager);

        $response = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/validate");

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('ENCODING_INCOMPLETE', json_decode((string) $response->getContent(), true)['error']['code']);
        $this->em->clear();
        self::assertSame(MissionStatus::SUBMITTED, $this->em->find(Mission::class, $mission->getId())->getStatus());

        // Le renvoi à l'instrumentiste reste possible (régularisation).
        $reject = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/reject", ['comment' => 'Merci de renseigner vos heures réelles.']);
        self::assertSame(Response::HTTP_OK, $reject->getStatusCode());
    }

    /** Scénario 21 — relance manuelle : historisée, statut et brouillon intacts, répétition bloquée. */
    public function test_manual_reminder_is_rate_limited_and_never_touches_the_encoding(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ENCODING_IN_PROGRESS, $instr);
        $line    = $this->makeMaterialLine($mission, $instr);
        $token   = $this->login($client, $manager);

        $first = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/remind");
        self::assertSame(Response::HTTP_OK, $first->getStatusCode(), (string) $first->getContent());

        $second = $this->postJson($client, $token, "/api/missions/{$mission->getId()}/encoding/remind");
        self::assertSame(Response::HTTP_CONFLICT, $second->getStatusCode());
        self::assertStringContainsString('vient déjà', json_decode((string) $second->getContent(), true)['error']['message']);

        $types = $this->auditEventTypesFor($mission->getId());
        self::assertCount(1, array_keys($types, AuditEventType::MISSION_ENCODING_MANUAL_REMINDER_SENT->value, true));

        $this->em->clear();
        $reloaded = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::ENCODING_IN_PROGRESS, $reloaded->getStatus());
        self::assertNotNull($this->em->find(MaterialLine::class, $line->getId()));
        self::assertCount(1, $this->em->getRepository(NotificationEvent::class)->findBy(['mission' => $mission->getId(), 'user' => $instr->getId(), 'eventType' => 'ENCODING_REMINDER']), 'la relance figure dans le centre de notifications');
    }

    // ── Intégration H1–H8 × D-143 — régularisation des missions historiques (phase 4) ──

    private function patchJson(KernelBrowser $client, string $token, string $uri, array $body): Response
    {
        $client->request('PATCH', $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode($body),
        );

        return $client->getResponse();
    }

    /**
     * Missions 435, 451 (SUBMITTED, aucune heure, commentaire libre) et 1224 (déclarée puis
     * soumise sans heure) : le manager ne peut pas les valider, il les renvoie à
     * l'instrumentiste ; celle-ci saisit ses heures réelles et soumet à nouveau ; la
     * validation passe. Ancien commentaire conservé, chaque étape auditée, aucun droit
     * élargi.
     */
    public function test_historical_submitted_mission_without_hours_is_regularised_through_the_authorised_circuit(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::SUBMITTED, $instr, new \DateTimeImmutable('-3 days'));
        $mission->setSubmittedAt(new \DateTimeImmutable('-2 days'));
        $mission->setSubmittedWithoutMaterial(true);
        $mission->setNoMaterialComment('SP 2 PTH Zimmer (assistante Zimmer) 5H prestation');
        $this->em->flush();
        $id = $mission->getId();
        $managerToken = $this->login($client, $manager);
        $instrToken   = $this->login($client, $instr);
        $surgeonToken = $this->login($client, $surgeon);

        // Avant régularisation : validation refusée (jamais sur le planifié).
        $validate = $this->postJson($client, $managerToken, "/api/missions/$id/encoding/validate");
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $validate->getStatusCode());
        // L'instrumentiste ne peut pas réécrire ses heures sur une mission soumise.
        self::assertSame(Response::HTTP_FORBIDDEN, $this->patchJson($client, $instrToken, "/api/missions/$id/execution", ['actualStartAt' => (new \DateTimeImmutable('-3 days'))->format(DATE_ATOM)])->getStatusCode());
        // Le chirurgien n'a aucun droit sur le circuit.
        self::assertSame(Response::HTTP_FORBIDDEN, $this->postJson($client, $surgeonToken, "/api/missions/$id/encoding/reject", ['comment' => 'x'])->getStatusCode());

        // 1. Renvoi à l'instrumentiste.
        self::assertSame(Response::HTTP_OK, $this->postJson($client, $managerToken, "/api/missions/$id/encoding/reject", ['comment' => 'Merci de renseigner vos heures réelles de début et de fin.'])->getStatusCode());

        // 2. Saisie des heures réelles par l'instrumentiste elle-même.
        $start = (new \DateTimeImmutable('-3 days'))->setTime(8, 0);
        $hours = $this->patchJson($client, $instrToken, "/api/missions/$id/execution", [
            'actualStartAt' => $start->format(DATE_ATOM),
            'actualEndAt'   => $start->modify('+5 hours 30 minutes')->format(DATE_ATOM),
            'breakMinutes'  => 30,
            'hoursSource'   => 'INSTRUMENTIST',
        ]);
        self::assertSame(Response::HTTP_OK, $hours->getStatusCode(), (string) $hours->getContent());
        self::assertSame(300, json_decode((string) $hours->getContent(), true)['actualDurationMinutes']);

        // 3. Nouvelle soumission puis validation.
        self::assertSame(Response::HTTP_OK, $this->postJson($client, $instrToken, "/api/missions/$id/encoding/complete", ['comment' => 'Heures réelles ajoutées.'])->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->postJson($client, $managerToken, "/api/missions/$id/encoding/validate")->getStatusCode());

        $this->em->clear();
        $reloaded = $this->em->find(Mission::class, $id);
        self::assertSame(MissionStatus::VALIDATED, $reloaded->getStatus());
        self::assertSame(300, $reloaded->getExecution()->getActualDurationMinutes());
        $comments = array_map(static fn ($c) => $c->getComment(), $this->em->getRepository(MissionEncodingComment::class)->findBy(['mission' => $id]));
        self::assertContains('Merci de renseigner vos heures réelles de début et de fin.', $comments, 'motif du renvoi historisé');
        $types = $this->auditEventTypesFor($id);
        foreach ([AuditEventType::MISSION_ENCODING_REJECTED, AuditEventType::MISSION_ENCODING_COMPLETED, AuditEventType::MISSION_ENCODING_VALIDATED] as $expected) {
            self::assertContains($expected->value, $types);
        }
    }

    /**
     * Missions 434 et 1225 (VALIDATED, verrouillées, aucune heure, aucun calcul financier) :
     * aucune valorisation possible tant que les heures manquent ; « Rouvrir » est autorisé
     * (aucun calcul verrouillé) puis le même circuit s'applique.
     */
    public function test_historical_validated_mission_without_hours_is_blocked_financially_then_reopened(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::VALIDATED, $instr, new \DateTimeImmutable('-30 days'));
        $mission->setSubmittedAt(new \DateTimeImmutable('-20 days'));
        $mission->setEncodingLockedAt(new \DateTimeImmutable('-10 days'));
        $this->em->flush();
        $id = $mission->getId();

        /** @var \App\Service\FinancialCalculationService $finance */
        $finance = static::getContainer()->get(\App\Service\FinancialCalculationService::class);
        $evaluation = $finance->evaluate($this->em->find(Mission::class, $id));
        self::assertFalse($evaluation->succeeds(), 'aucune valorisation sur le planifié');
        self::assertContains('MISSING_ACTUAL_HOURS', array_map(static fn ($a) => $a->code, $evaluation->anomalies));

        $instrToken   = $this->login($client, $instr);
        $managerToken = $this->login($client, $manager);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->postJson($client, $instrToken, "/api/missions/$id/encoding/reopen")->getStatusCode(), 'seul un manager rouvre');
        self::assertSame(Response::HTTP_OK, $this->postJson($client, $managerToken, "/api/missions/$id/encoding/reopen", ['comment' => 'Heures réelles manquantes (régularisation D-143).'])->getStatusCode());

        $this->em->clear();
        $reopened = $this->em->find(Mission::class, $id);
        self::assertSame(MissionStatus::ENCODING_IN_PROGRESS, $reopened->getStatus());
        self::assertNull($reopened->getEncodingLockedAt());
        self::assertNull($reopened->getExecution(), 'aucune heure inventée par la réouverture');
        self::assertContains(AuditEventType::MISSION_ENCODING_REOPENED->value, $this->auditEventTypesFor($id));
    }
}
