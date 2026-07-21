<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\Hospital;
use App\Entity\InterventionTypeRequest;
use App\Entity\MaterialItem;
use App\Entity\MaterialItemRequest;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionDraft;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * EPIC Revue instrumentiste, Lot 3, commit 4 — rattachement du matériel (MaterialLine
 * et MaterialItemRequest) à une cible réelle (MissionIntervention) ou provisoire
 * (MissionInterventionDraft), via MaterialAttachmentResolver. N'exerce ni resolve(), ni
 * ignore(), ni le repointage en masse (hors périmètre de ce commit) — les drafts
 * CONVERTED/MATERIAL_REASSIGNED/KEPT_AS_HISTORY sont construits directement en base
 * pour ce test, pas via le workflow manager réel (pas encore branché).
 */
final class MaterialAttachmentCreationWorkflowTest extends WebTestCase
{
    private const PASSWORD = 'MatAttach15!';
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private array $createdIds = [
        'missions' => [], 'users' => [], 'sites' => [], 'firms' => [], 'items' => [],
        'requests' => [], 'lines' => [], 'materialRequests' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdIds['missions'] as $missionId) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $missionId]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['lines'] as $id) {
                $e = $this->em->find(MaterialLine::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            foreach ($this->createdIds['materialRequests'] as $id) {
                $e = $this->em->find(MaterialItemRequest::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $id) {
                $m = $this->em->find(Mission::class, $id);
                if ($m !== null) {
                    foreach ($m->getMissionInterventionDrafts() as $d) { $this->em->remove($d); }
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $id) {
                $m = $this->em->find(Mission::class, $id);
                if ($m !== null) {
                    foreach ($m->getInterventionTypeRequests() as $r) { $this->em->remove($r); }
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $id) {
                $m = $this->em->find(Mission::class, $id);
                if ($m !== null) {
                    foreach ($m->getInterventions() as $i) { $this->em->remove($i); }
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $id) {
                $e = $this->em->find(Mission::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['items'] as $id) {
                $e = $this->em->find(MaterialItem::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            foreach ($this->createdIds['firms'] as $id) {
                $e = $this->em->find(Firm::class, $id);
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

    // ── Fixtures ─────────────────────────────────────────────────────

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('matattach-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
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
        $h->setName('MatAttachSite-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeFirm(): Firm
    {
        $f = new Firm();
        $f->setName('MatAttachFirm-' . bin2hex(random_bytes(3)));
        $f->setActive(true);
        $this->em->persist($f);
        $this->em->flush();
        $this->createdIds['firms'][] = $f->getId();
        return $f;
    }

    private function makeItem(Firm $firm): MaterialItem
    {
        $i = new MaterialItem();
        $i->setFirm($firm);
        $i->setLabel('Item-' . bin2hex(random_bytes(3)));
        $i->setUnit('pièce');
        $i->setReferenceCode(bin2hex(random_bytes(4)));
        $this->em->persist($i);
        $this->em->flush();
        $this->createdIds['items'][] = $i->getId();
        return $i;
    }

    /** Mission ASSIGNED, démarrée (startAt passé) — encodage autorisé pour $instr. */
    private function makeEncodableMission(User $surgeon, User $instr, Hospital $site): Mission
    {
        $m = new Mission();
        $m->setStatus(MissionStatus::ASSIGNED);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setSurgeon($surgeon);
        $m->setInstrumentist($instr);
        $m->setSite($site);
        $m->setCreatedBy($surgeon);
        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
        $m->setStartAt($now->modify('-1 hour'));
        $m->setEndAt($now->modify('+2 hours'));
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    /** @return array{0: KernelBrowser, 1: Mission, 2: string, 3: User} */
    private function bootMissionScenario(): array
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeEncodableMission($surgeon, $instr, $site);
        $token = $this->login($client, $instr);
        return [$client, $mission, $token, $instr];
    }

    private function addIntervention(Mission $mission): MissionIntervention
    {
        $i = new MissionIntervention();
        $i->setMission($mission)->setCode('GEN01')->setLabel('Test')->setOrderIndex(0);
        $this->em->persist($i);
        $this->em->flush();
        return $i;
    }

    private function addDraft(Mission $mission, User $author, string $status = MissionInterventionDraft::STATUS_OPEN, ?MissionIntervention $resolved = null): MissionInterventionDraft
    {
        $req = new InterventionTypeRequest();
        $req->setMission($mission)->setLabel('Prothèse test')->setCreatedBy($author);
        $this->em->persist($req);
        $this->em->flush();

        $draft = new MissionInterventionDraft();
        $draft
            ->setMission($mission)
            ->setInterventionTypeRequest($req)
            ->setLabel('Prothèse test')
            ->setOrderIndex(1)
            ->setStatus($status)
            ->setCreatedBy($author);
        if ($resolved !== null) {
            $draft->setResolvedMissionIntervention($resolved);
        }
        $this->em->persist($draft);
        $this->em->flush();
        return $draft;
    }

    private function errorCode(Response $response): ?string
    {
        return json_decode($response->getContent(), true)['error']['code'] ?? null;
    }

    // ═══════════════════════════════════════════════════════════════
    // MaterialLine — POST /api/missions/{id}/material-lines
    // ═══════════════════════════════════════════════════════════════

    public function test_material_line_created_without_any_target(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $item = $this->makeItem($this->makeFirm());

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-lines", $token, [
            'itemId' => $item->getId(), 'quantity' => '2',
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->createdIds['lines'][] = $body['id'];
        self::assertNull($body['missionInterventionId']);
        self::assertNull($body['interventionDraftId']);
    }

    public function test_material_line_created_on_a_real_intervention(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $intervention = $this->addIntervention($mission);
        $item = $this->makeItem($this->makeFirm());

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-lines", $token, [
            'itemId' => $item->getId(), 'quantity' => '1', 'missionInterventionId' => $intervention->getId(),
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->createdIds['lines'][] = $body['id'];
        self::assertSame($intervention->getId(), $body['missionInterventionId']);
        self::assertNull($body['interventionDraftId']);

        $this->em->clear();
        $line = $this->em->find(MaterialLine::class, $body['id']);
        self::assertNull($line->getInterventionDraft(), 'exactly one FK must be set');
    }

    public function test_material_line_created_on_an_open_draft(): void
    {
        [$client, $mission, $token, $instr] = $this->bootMissionScenario();
        $draft = $this->addDraft($mission, $instr);
        $item = $this->makeItem($this->makeFirm());

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-lines", $token, [
            'itemId' => $item->getId(), 'quantity' => '1', 'interventionDraftId' => $draft->getId(),
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->createdIds['lines'][] = $body['id'];
        self::assertNull($body['missionInterventionId']);
        self::assertSame($draft->getId(), $body['interventionDraftId']);

        $this->em->clear();
        $line = $this->em->find(MaterialLine::class, $body['id']);
        self::assertNull($line->getMissionIntervention(), 'exactly one FK must be set');
    }

    public function test_material_line_rejects_both_targets_simultaneously(): void
    {
        [$client, $mission, $token, $instr] = $this->bootMissionScenario();
        $intervention = $this->addIntervention($mission);
        $draft = $this->addDraft($mission, $instr);
        $item = $this->makeItem($this->makeFirm());

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-lines", $token, [
            'itemId' => $item->getId(), 'quantity' => '1',
            'missionInterventionId' => $intervention->getId(), 'interventionDraftId' => $draft->getId(),
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), $response->getContent());
        self::assertSame('CONFLICTING_MATERIAL_ATTACHMENT_INPUT', $this->errorCode($response));

        $count = $this->em->getRepository(MaterialLine::class)->count(['mission' => $mission->getId()]);
        self::assertSame(0, $count, 'no partial persistence on rejected input');
    }

    public function test_material_line_rejects_an_intervention_from_another_mission(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $otherMission = $this->makeEncodableMission($this->createUser('ROLE_SURGEON'), $this->createUser('ROLE_INSTRUMENTIST'), $this->makeSite());
        $foreignIntervention = $this->addIntervention($otherMission);
        $item = $this->makeItem($this->makeFirm());

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-lines", $token, [
            'itemId' => $item->getId(), 'quantity' => '1', 'missionInterventionId' => $foreignIntervention->getId(),
        ]);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), $response->getContent());
        self::assertSame('MATERIAL_ATTACHMENT_TARGET_NOT_FOUND', $this->errorCode($response));
        self::assertSame(0, $this->em->getRepository(MaterialLine::class)->count(['mission' => $mission->getId()]));
    }

    public function test_material_line_rejects_a_draft_from_another_mission(): void
    {
        [$client, $mission, $token, $instr] = $this->bootMissionScenario();
        $otherMission = $this->makeEncodableMission($this->createUser('ROLE_SURGEON'), $instr, $this->makeSite());
        $foreignDraft = $this->addDraft($otherMission, $instr);
        $item = $this->makeItem($this->makeFirm());

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-lines", $token, [
            'itemId' => $item->getId(), 'quantity' => '1', 'interventionDraftId' => $foreignDraft->getId(),
        ]);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), $response->getContent());
        self::assertSame('MATERIAL_ATTACHMENT_TARGET_NOT_FOUND', $this->errorCode($response));
        self::assertSame(0, $this->em->getRepository(MaterialLine::class)->count(['mission' => $mission->getId()]));
    }

    public function test_material_line_rejects_a_nonexistent_draft(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $item = $this->makeItem($this->makeFirm());

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-lines", $token, [
            'itemId' => $item->getId(), 'quantity' => '1', 'interventionDraftId' => 999999999,
        ]);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), $response->getContent());
        self::assertSame('MATERIAL_ATTACHMENT_TARGET_NOT_FOUND', $this->errorCode($response));
    }

    public function test_material_line_rejects_a_nonexistent_intervention(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $item = $this->makeItem($this->makeFirm());

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-lines", $token, [
            'itemId' => $item->getId(), 'quantity' => '1', 'missionInterventionId' => 999999999,
        ]);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), $response->getContent());
        self::assertSame('MATERIAL_ATTACHMENT_TARGET_NOT_FOUND', $this->errorCode($response));
    }

    public function test_material_line_on_converted_draft_is_silently_redirected(): void
    {
        [$client, $mission, $token, $instr] = $this->bootMissionScenario();
        $resolved = $this->addIntervention($mission);
        $draft = $this->addDraft($mission, $instr, MissionInterventionDraft::STATUS_CONVERTED, $resolved);
        $item = $this->makeItem($this->makeFirm());

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-lines", $token, [
            'itemId' => $item->getId(), 'quantity' => '1', 'interventionDraftId' => $draft->getId(),
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->createdIds['lines'][] = $body['id'];

        // La réponse montre l'intervention réelle finale, jamais le draft demandé.
        self::assertSame($resolved->getId(), $body['missionInterventionId']);
        self::assertNull($body['interventionDraftId']);
    }

    public function test_material_line_on_material_reassigned_draft_is_silently_redirected(): void
    {
        [$client, $mission, $token, $instr] = $this->bootMissionScenario();
        $resolved = $this->addIntervention($mission);
        $draft = $this->addDraft($mission, $instr, MissionInterventionDraft::STATUS_MATERIAL_REASSIGNED, $resolved);
        $item = $this->makeItem($this->makeFirm());

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-lines", $token, [
            'itemId' => $item->getId(), 'quantity' => '1', 'interventionDraftId' => $draft->getId(),
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->createdIds['lines'][] = $body['id'];
        self::assertSame($resolved->getId(), $body['missionInterventionId']);
        self::assertNull($body['interventionDraftId']);
    }

    public function test_material_line_on_kept_as_history_draft_is_rejected_with_409(): void
    {
        [$client, $mission, $token, $instr] = $this->bootMissionScenario();
        $draft = $this->addDraft($mission, $instr, MissionInterventionDraft::STATUS_KEPT_AS_HISTORY);
        $item = $this->makeItem($this->makeFirm());

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-lines", $token, [
            'itemId' => $item->getId(), 'quantity' => '1', 'interventionDraftId' => $draft->getId(),
        ]);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode(), $response->getContent());
        self::assertSame('MATERIAL_ATTACHMENT_TARGET_CLOSED', $this->errorCode($response));
        self::assertSame(0, $this->em->getRepository(MaterialLine::class)->count(['mission' => $mission->getId()]));
    }

    /**
     * Incohérence normalement inatteignable via le workflow réel (voir
     * MaterialAttachmentResolverTest) — vérifiée ici au niveau HTTP : produit un 500
     * explicite (LogicException non mappée), jamais une création partielle.
     */
    public function test_material_line_on_terminal_draft_without_resolved_intervention_fails_explicitly_and_persists_nothing(): void
    {
        [$client, $mission, $token, $instr] = $this->bootMissionScenario();
        $draft = $this->addDraft($mission, $instr, MissionInterventionDraft::STATUS_CONVERTED, null);
        $item = $this->makeItem($this->makeFirm());

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-lines", $token, [
            'itemId' => $item->getId(), 'quantity' => '1', 'interventionDraftId' => $draft->getId(),
        ]);

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode(), $response->getContent());
        self::assertSame(0, $this->em->getRepository(MaterialLine::class)->count(['mission' => $mission->getId()]));
    }

    public function test_material_line_creation_rolls_back_completely_on_nonexistent_item(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $intervention = $this->addIntervention($mission);

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-lines", $token, [
            'itemId' => 999999999, 'quantity' => '1', 'missionInterventionId' => $intervention->getId(),
        ]);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), $response->getContent());
        self::assertSame(0, $this->em->getRepository(MaterialLine::class)->count(['mission' => $mission->getId()]));
    }

    public function test_material_line_creation_still_requires_edit_encoding_permission(): void
    {
        [$client, $mission] = $this->bootMissionScenario();
        $otherInstrumentist = $this->createUser('ROLE_INSTRUMENTIST');
        $otherToken = $this->login($client, $otherInstrumentist);
        $item = $this->makeItem($this->makeFirm());

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-lines", $otherToken, [
            'itemId' => $item->getId(), 'quantity' => '1',
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame(0, $this->em->getRepository(MaterialLine::class)->count(['mission' => $mission->getId()]));
    }

    // ═══════════════════════════════════════════════════════════════
    // MaterialItemRequest — POST /api/missions/{id}/material-item-requests
    // ═══════════════════════════════════════════════════════════════

    public function test_material_item_request_created_without_any_target(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-item-requests", $token, [
            'label' => 'Ancre titane',
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->createdIds['materialRequests'][] = $body['id'];
        self::assertNull($body['missionInterventionId']);
        self::assertNull($body['interventionDraftId']);
    }

    public function test_material_item_request_created_on_a_real_intervention(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $intervention = $this->addIntervention($mission);

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-item-requests", $token, [
            'label' => 'Ancre titane', 'missionInterventionId' => $intervention->getId(),
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->createdIds['materialRequests'][] = $body['id'];
        self::assertSame($intervention->getId(), $body['missionInterventionId']);
        self::assertNull($body['interventionDraftId']);

        $this->em->clear();
        $req = $this->em->find(MaterialItemRequest::class, $body['id']);
        self::assertNull($req->getInterventionDraft(), 'exactly one FK must be set');
    }

    public function test_material_item_request_created_on_an_open_draft(): void
    {
        [$client, $mission, $token, $instr] = $this->bootMissionScenario();
        $draft = $this->addDraft($mission, $instr);

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-item-requests", $token, [
            'label' => 'Ancre titane', 'interventionDraftId' => $draft->getId(),
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->createdIds['materialRequests'][] = $body['id'];
        self::assertNull($body['missionInterventionId']);
        self::assertSame($draft->getId(), $body['interventionDraftId']);

        $this->em->clear();
        $req = $this->em->find(MaterialItemRequest::class, $body['id']);
        self::assertNull($req->getMissionIntervention(), 'exactly one FK must be set');
    }

    public function test_material_item_request_rejects_both_targets_simultaneously(): void
    {
        [$client, $mission, $token, $instr] = $this->bootMissionScenario();
        $intervention = $this->addIntervention($mission);
        $draft = $this->addDraft($mission, $instr);

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-item-requests", $token, [
            'label' => 'Ancre titane',
            'missionInterventionId' => $intervention->getId(), 'interventionDraftId' => $draft->getId(),
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), $response->getContent());
        self::assertSame('CONFLICTING_MATERIAL_ATTACHMENT_INPUT', $this->errorCode($response));
        self::assertSame(0, $this->em->getRepository(MaterialItemRequest::class)->count(['mission' => $mission->getId()]));
    }

    public function test_material_item_request_rejects_an_intervention_from_another_mission(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $otherMission = $this->makeEncodableMission($this->createUser('ROLE_SURGEON'), $this->createUser('ROLE_INSTRUMENTIST'), $this->makeSite());
        $foreignIntervention = $this->addIntervention($otherMission);

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-item-requests", $token, [
            'label' => 'Ancre titane', 'missionInterventionId' => $foreignIntervention->getId(),
        ]);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), $response->getContent());
        self::assertSame('MATERIAL_ATTACHMENT_TARGET_NOT_FOUND', $this->errorCode($response));
        self::assertSame(0, $this->em->getRepository(MaterialItemRequest::class)->count(['mission' => $mission->getId()]));
    }

    public function test_material_item_request_rejects_a_draft_from_another_mission(): void
    {
        [$client, $mission, $token, $instr] = $this->bootMissionScenario();
        $otherMission = $this->makeEncodableMission($this->createUser('ROLE_SURGEON'), $instr, $this->makeSite());
        $foreignDraft = $this->addDraft($otherMission, $instr);

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-item-requests", $token, [
            'label' => 'Ancre titane', 'interventionDraftId' => $foreignDraft->getId(),
        ]);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), $response->getContent());
        self::assertSame('MATERIAL_ATTACHMENT_TARGET_NOT_FOUND', $this->errorCode($response));
        self::assertSame(0, $this->em->getRepository(MaterialItemRequest::class)->count(['mission' => $mission->getId()]));
    }

    public function test_material_item_request_rejects_a_nonexistent_draft(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-item-requests", $token, [
            'label' => 'Ancre titane', 'interventionDraftId' => 999999999,
        ]);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), $response->getContent());
        self::assertSame('MATERIAL_ATTACHMENT_TARGET_NOT_FOUND', $this->errorCode($response));
    }

    public function test_material_item_request_rejects_a_nonexistent_intervention(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-item-requests", $token, [
            'label' => 'Ancre titane', 'missionInterventionId' => 999999999,
        ]);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), $response->getContent());
        self::assertSame('MATERIAL_ATTACHMENT_TARGET_NOT_FOUND', $this->errorCode($response));
    }

    public function test_material_item_request_on_converted_draft_is_silently_redirected(): void
    {
        [$client, $mission, $token, $instr] = $this->bootMissionScenario();
        $resolved = $this->addIntervention($mission);
        $draft = $this->addDraft($mission, $instr, MissionInterventionDraft::STATUS_CONVERTED, $resolved);

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-item-requests", $token, [
            'label' => 'Ancre titane', 'interventionDraftId' => $draft->getId(),
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->createdIds['materialRequests'][] = $body['id'];
        self::assertSame($resolved->getId(), $body['missionInterventionId']);
        self::assertNull($body['interventionDraftId']);
    }

    public function test_material_item_request_on_material_reassigned_draft_is_silently_redirected(): void
    {
        [$client, $mission, $token, $instr] = $this->bootMissionScenario();
        $resolved = $this->addIntervention($mission);
        $draft = $this->addDraft($mission, $instr, MissionInterventionDraft::STATUS_MATERIAL_REASSIGNED, $resolved);

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-item-requests", $token, [
            'label' => 'Ancre titane', 'interventionDraftId' => $draft->getId(),
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->createdIds['materialRequests'][] = $body['id'];
        self::assertSame($resolved->getId(), $body['missionInterventionId']);
        self::assertNull($body['interventionDraftId']);
    }

    public function test_material_item_request_on_kept_as_history_draft_is_rejected_with_409(): void
    {
        [$client, $mission, $token, $instr] = $this->bootMissionScenario();
        $draft = $this->addDraft($mission, $instr, MissionInterventionDraft::STATUS_KEPT_AS_HISTORY);

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-item-requests", $token, [
            'label' => 'Ancre titane', 'interventionDraftId' => $draft->getId(),
        ]);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode(), $response->getContent());
        self::assertSame('MATERIAL_ATTACHMENT_TARGET_CLOSED', $this->errorCode($response));
        self::assertSame(0, $this->em->getRepository(MaterialItemRequest::class)->count(['mission' => $mission->getId()]));
    }

    public function test_material_item_request_on_terminal_draft_without_resolved_intervention_fails_explicitly_and_persists_nothing(): void
    {
        [$client, $mission, $token, $instr] = $this->bootMissionScenario();
        $draft = $this->addDraft($mission, $instr, MissionInterventionDraft::STATUS_MATERIAL_REASSIGNED, null);

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-item-requests", $token, [
            'label' => 'Ancre titane', 'interventionDraftId' => $draft->getId(),
        ]);

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode(), $response->getContent());
        self::assertSame(0, $this->em->getRepository(MaterialItemRequest::class)->count(['mission' => $mission->getId()]));
    }

    public function test_material_item_request_creation_still_requires_edit_encoding_permission(): void
    {
        [$client, $mission] = $this->bootMissionScenario();
        $otherInstrumentist = $this->createUser('ROLE_INSTRUMENTIST');
        $otherToken = $this->login($client, $otherInstrumentist);

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-item-requests", $otherToken, [
            'label' => 'Ancre titane',
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame(0, $this->em->getRepository(MaterialItemRequest::class)->count(['mission' => $mission->getId()]));
    }

    public function test_material_item_request_creation_still_applies_encoding_guard(): void
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();

        // Mission ASSIGNED mais pas encore démarrée (startAt futur).
        $m = new Mission();
        $m->setStatus(MissionStatus::ASSIGNED);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setSurgeon($surgeon);
        $m->setInstrumentist($instr);
        $m->setSite($site);
        $m->setCreatedBy($surgeon);
        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
        $m->setStartAt($now->modify('+1 hour'));
        $m->setEndAt($now->modify('+3 hours'));
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();

        $token = $this->login($client, $instr);

        $response = $this->request($client, 'POST', "/api/missions/{$m->getId()}/material-item-requests", $token, [
            'label' => 'Trop tôt',
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode(), $response->getContent());
        self::assertSame(0, $this->em->getRepository(MaterialItemRequest::class)->count(['mission' => $m->getId()]));
    }
}
