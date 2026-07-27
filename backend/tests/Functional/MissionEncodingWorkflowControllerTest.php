<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\Hospital;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionEncodingComment;
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
}
