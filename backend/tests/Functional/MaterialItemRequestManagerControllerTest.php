<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\Hospital;
use App\Entity\InterventionType;
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
use App\Message\CatalogueRequestCreatedMessage;
use App\Message\CatalogueRequestProcessedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-093 — première couverture fonctionnelle de MaterialItemRequestManagerController
 * (list/resolve/ignore), jusqu'ici totalement non testé (voir audit du 2026-08-04).
 * Couvre : (1) le trou RBAC corrigé (BillingVoter::MANAGE requis, comme son pendant
 * InterventionTypeRequestManagerController), (2) le bug d'orphelinage corrigé (une
 * MaterialLine créée pour une demande encore rattachée à un draft ouvert doit suivre
 * attachmentTarget(), pas seulement missionIntervention — sinon jamais reprise par
 * MissionInterventionDraftService::repointMaterial() plus tard), (3) le dispatch de
 * CatalogueRequestProcessedMessage sur resolve()/ignore().
 */
final class MaterialItemRequestManagerControllerTest extends WebTestCase
{
    private const PASSWORD = 'MatReqWF15!';
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private array $createdIds = [
        'missions' => [], 'users' => [], 'sites' => [], 'firms' => [], 'types' => [],
        'requests' => [], 'materialRequests' => [], 'materials' => [],
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
            foreach ($this->createdIds['materialRequests'] as $id) {
                $e = $this->em->find(MaterialItemRequest::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $id) {
                $m = $this->em->find(Mission::class, $id);
                if ($m !== null) {
                    foreach ($this->em->getRepository(MaterialLine::class)->findBy(['mission' => $m]) as $l) {
                        $this->em->remove($l);
                    }
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['requests'] as $id) {
                $req = $this->em->find(InterventionTypeRequest::class, $id);
                if ($req !== null && $req->getDraft() !== null) {
                    $this->em->remove($req->getDraft());
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['requests'] as $id) {
                $e = $this->em->find(InterventionTypeRequest::class, $id);
                if ($e !== null) { $this->em->remove($e); }
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
            foreach ($this->createdIds['materials'] as $id) {
                $e = $this->em->find(MaterialItem::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            foreach ($this->createdIds['types'] as $id) {
                $e = $this->em->find(InterventionType::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
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

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('matreqwf-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('MatReqWF');
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
        $h->setName('MatReqWFSite-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeFirm(): Firm
    {
        $f = new Firm();
        $f->setName('MatReqWFFirm-' . bin2hex(random_bytes(3)));
        $f->setActive(true);
        $this->em->persist($f);
        $this->em->flush();
        $this->createdIds['firms'][] = $f->getId();
        return $f;
    }

    private function makeType(): InterventionType
    {
        $t = new InterventionType();
        $t->setCode('MRWF-' . bin2hex(random_bytes(4)));
        $t->setLabel('Type MatReqWF ' . bin2hex(random_bytes(2)));
        $t->setActive(true);
        $this->em->persist($t);
        $this->em->flush();
        $this->createdIds['types'][] = $t->getId();
        return $t;
    }

    private function makeMaterialItem(Firm $firm): MaterialItem
    {
        // Toute entité créée avant un appel HTTP via $client->request() devient
        // détachée de ce $this->em (même principe que test_legacy_request_without_draft_
        // returns_409 dans InterventionTypeRequestResolveWorkflowTest) — jamais réutiliser
        // l'objet original après une requête, toujours re-résoudre par id.
        $firm = $this->em->find(Firm::class, $firm->getId()) ?? $firm;
        $mi = new MaterialItem();
        $mi->setFirm($firm);
        $mi->setLabel('MatReqWF Material ' . bin2hex(random_bytes(2)));
        $mi->setUnit('pièce');
        $mi->setReferenceCode('REF-' . bin2hex(random_bytes(2)));
        $mi->setIsImplant(false);
        $this->em->persist($mi);
        $this->em->flush();
        $this->createdIds['materials'][] = $mi->getId();
        return $mi;
    }

    private function makeMission(User $surgeon, User $instr, Hospital $site): Mission
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

    private function makeRealIntervention(Mission $mission, InterventionType $type): MissionIntervention {
        $i = new MissionIntervention();
        $i->setMission($mission)
            ->setInterventionType($type)
            ->setCode($type->getCode())
            ->setLabel($type->getLabel())
            ->setOrderIndex(1);
        $this->em->persist($i);
        $this->em->flush();
        return $i;
    }

    /** @return array{0: KernelBrowser, 1: Mission, 2: string, 3: User} */
    private function bootMissionScenario(): array
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site);
        $token = $this->login($client, $instr);
        return [$client, $mission, $token, $instr];
    }

    private function createMaterialRequestOnIntervention(KernelBrowser $client, Mission $mission, string $instrToken, MissionIntervention $intervention, string $label = 'Demande MatReqWF'): int
    {
        $created = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-item-requests", $instrToken, [
            'label' => $label,
            'missionInterventionId' => $intervention->getId(),
        ]);
        self::assertSame(Response::HTTP_CREATED, $created->getStatusCode(), $created->getContent());
        $id = json_decode($created->getContent(), true)['id'];
        $this->createdIds['materialRequests'][] = $id;
        return $id;
    }

    /** @return array{0: int, 1: int} [materialRequestId, draftId] */
    private function createMaterialRequestOnOpenDraft(KernelBrowser $client, Mission $mission, string $instrToken, string $label = 'Demande MatReqWF sur draft'): array
    {
        $draftCreated = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $instrToken, [
            'label' => 'Intervention MatReqWF ' . bin2hex(random_bytes(2)),
        ]);
        self::assertSame(Response::HTTP_CREATED, $draftCreated->getStatusCode(), $draftCreated->getContent());
        $draftData = json_decode($draftCreated->getContent(), true);
        $this->createdIds['requests'][] = $draftData['id'];
        $draftId = $draftData['draftId'];

        $created = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-item-requests", $instrToken, [
            'label' => $label,
            'interventionDraftId' => $draftId,
        ]);
        self::assertSame(Response::HTTP_CREATED, $created->getStatusCode(), $created->getContent());
        $id = json_decode($created->getContent(), true)['id'];
        $this->createdIds['materialRequests'][] = $id;
        return [$id, $draftId];
    }

    // ── Autorisations (le trou RBAC corrigé) ──────────────────────────────────

    public function test_instrumentist_cannot_resolve_material_request(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $intervention = $this->makeRealIntervention($mission, $type);
        $requestId = $this->createMaterialRequestOnIntervention($client, $mission, $instrToken, $intervention);
        $mi = $this->makeMaterialItem($firm);

        $response = $this->request($client, 'POST', "/api/material-item-requests/{$requestId}/resolve", $instrToken, [
            'materialItemId' => $mi->getId(),
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_instrumentist_cannot_ignore_material_request(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $type = $this->makeType();
        $intervention = $this->makeRealIntervention($mission, $type);
        $requestId = $this->createMaterialRequestOnIntervention($client, $mission, $instrToken, $intervention);

        $response = $this->request($client, 'POST', "/api/material-item-requests/{$requestId}/ignore", $instrToken);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_instrumentist_cannot_list_material_requests(): void
    {
        [$client, , $instrToken] = $this->bootMissionScenario();

        $response = $this->request($client, 'GET', '/api/material-item-requests', $instrToken);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_unauthenticated_resolve_is_refused(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $type = $this->makeType();
        $intervention = $this->makeRealIntervention($mission, $type);
        $requestId = $this->createMaterialRequestOnIntervention($client, $mission, $instrToken, $intervention);

        $response = $this->request($client, 'POST', "/api/material-item-requests/{$requestId}/resolve", null, ['materialItemId' => 1]);

        self::assertContains($response->getStatusCode(), [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN]);
    }

    public function test_manager_is_authorized_to_resolve(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $intervention = $this->makeRealIntervention($mission, $type);
        $requestId = $this->createMaterialRequestOnIntervention($client, $mission, $instrToken, $intervention);
        $mi = $this->makeMaterialItem($firm);

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);

        $response = $this->request($client, 'POST', "/api/material-item-requests/{$requestId}/resolve", $managerToken, [
            'materialItemId' => $mi->getId(),
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        self::assertSame('RESOLVED', json_decode($response->getContent(), true)['request']['status']);
    }

    // ── Le bug d'orphelinage corrigé ──────────────────────────────────────────

    public function test_resolving_on_a_real_intervention_attaches_the_material_line_to_it(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $intervention = $this->makeRealIntervention($mission, $type);
        $requestId = $this->createMaterialRequestOnIntervention($client, $mission, $instrToken, $intervention);
        $mi = $this->makeMaterialItem($firm);

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);
        $response = $this->request($client, 'POST', "/api/material-item-requests/{$requestId}/resolve", $managerToken, [
            'materialItemId' => $mi->getId(),
        ]);
        $lineId = json_decode($response->getContent(), true)['materialLine']['id'];

        $this->em->clear();
        $line = $this->em->find(MaterialLine::class, $lineId);
        self::assertNotNull($line);
        self::assertSame($intervention->getId(), $line->getMissionIntervention()?->getId());
        self::assertNull($line->getInterventionDraft());
    }

    public function test_resolving_while_still_attached_to_an_open_draft_attaches_the_material_line_to_the_draft_not_orphaned(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $firm = $this->makeFirm();
        [$requestId, $draftId] = $this->createMaterialRequestOnOpenDraft($client, $mission, $instrToken);
        $mi = $this->makeMaterialItem($firm);

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);
        $response = $this->request($client, 'POST', "/api/material-item-requests/{$requestId}/resolve", $managerToken, [
            'materialItemId' => $mi->getId(),
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $lineId = json_decode($response->getContent(), true)['materialLine']['id'];

        // Régression : avant le correctif, cette ligne n'avait NI missionIntervention NI
        // interventionDraft (orpheline) — elle doit désormais suivre le draft.
        $this->em->clear();
        $line = $this->em->find(MaterialLine::class, $lineId);
        self::assertNotNull($line);
        self::assertNull($line->getMissionIntervention());
        self::assertSame($draftId, $line->getInterventionDraft()?->getId());

        // Et quand le draft est ensuite résolu par le manager, cette ligne doit être
        // reprise (repointMaterial()) vers la nouvelle MissionIntervention réelle — la
        // preuve définitive qu'elle n'est plus orpheline.
        $type = $this->makeType();
        // InterventionTypeRequest::$draft est le côté inverse de la relation
        // (MissionInterventionDraft::$interventionTypeRequest est le côté propriétaire,
        // voir MissionInterventionDraft.php) — impossible à interroger directement via
        // findBy/findOneBy, on repart du draft pour retrouver sa demande.
        $draft = $this->em->find(MissionInterventionDraft::class, $draftId);
        $draftRequest = $draft->getInterventionTypeRequest();
        $resolveDraft = $this->request($client, 'POST', "/api/intervention-type-requests/{$draftRequest->getId()}/resolve", $managerToken, [
            'interventionTypeId' => $type->getId(),
        ]);
        self::assertSame(Response::HTTP_OK, $resolveDraft->getStatusCode(), $resolveDraft->getContent());
        $newInterventionId = json_decode($resolveDraft->getContent(), true)['missionInterventionId'];

        $this->em->clear();
        $line = $this->em->find(MaterialLine::class, $lineId);
        self::assertSame($newInterventionId, $line->getMissionIntervention()?->getId());
        self::assertNull($line->getInterventionDraft());
    }

    // ── Erreurs métier ────────────────────────────────────────────────────────

    public function test_missing_material_item_id_returns_422(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $type = $this->makeType();
        $intervention = $this->makeRealIntervention($mission, $type);
        $requestId = $this->createMaterialRequestOnIntervention($client, $mission, $instrToken, $intervention);

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);
        $response = $this->request($client, 'POST', "/api/material-item-requests/{$requestId}/resolve", $managerToken, []);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function test_nonexistent_material_item_returns_422(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $type = $this->makeType();
        $intervention = $this->makeRealIntervention($mission, $type);
        $requestId = $this->createMaterialRequestOnIntervention($client, $mission, $instrToken, $intervention);

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);
        $response = $this->request($client, 'POST', "/api/material-item-requests/{$requestId}/resolve", $managerToken, [
            'materialItemId' => 999999999,
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function test_second_resolution_attempt_returns_409(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $intervention = $this->makeRealIntervention($mission, $type);
        $requestId = $this->createMaterialRequestOnIntervention($client, $mission, $instrToken, $intervention);
        $mi = $this->makeMaterialItem($firm);

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);
        $first = $this->request($client, 'POST', "/api/material-item-requests/{$requestId}/resolve", $managerToken, ['materialItemId' => $mi->getId()]);
        self::assertSame(Response::HTTP_OK, $first->getStatusCode());

        $second = $this->request($client, 'POST', "/api/material-item-requests/{$requestId}/resolve", $managerToken, ['materialItemId' => $mi->getId()]);
        self::assertSame(Response::HTTP_CONFLICT, $second->getStatusCode());
    }

    public function test_ignore_transitions_to_ignored(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $type = $this->makeType();
        $intervention = $this->makeRealIntervention($mission, $type);
        $requestId = $this->createMaterialRequestOnIntervention($client, $mission, $instrToken, $intervention);

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);
        $response = $this->request($client, 'POST', "/api/material-item-requests/{$requestId}/ignore", $managerToken);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('IGNORED', json_decode($response->getContent(), true)['status']);
    }

    // ── Notification (D-093) ─────────────────────────────────────────────────

    public function test_resolve_dispatches_catalogue_request_processed_message_accepted(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $intervention = $this->makeRealIntervention($mission, $type);
        $requestId = $this->createMaterialRequestOnIntervention($client, $mission, $instrToken, $intervention, 'Vis titane');
        $mi = $this->makeMaterialItem($firm);

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $response = $this->request($client, 'POST', "/api/material-item-requests/{$requestId}/resolve", $managerToken, [
            'materialItemId' => $mi->getId(),
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());

        $sent = array_values(array_filter($transport->getSent(), static fn ($e) => $e->getMessage() instanceof CatalogueRequestProcessedMessage));
        self::assertCount(1, $sent);
        /** @var CatalogueRequestProcessedMessage $message */
        $message = $sent[0]->getMessage();
        self::assertTrue($message->accepted);
        self::assertSame('MATERIAL_ITEM', $message->kind->value);
        self::assertSame('Vis titane', $message->label);
        self::assertSame($mission->getId(), $message->missionId);
    }

    public function test_ignore_dispatches_catalogue_request_processed_message_not_accepted(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $type = $this->makeType();
        $intervention = $this->makeRealIntervention($mission, $type);
        $requestId = $this->createMaterialRequestOnIntervention($client, $mission, $instrToken, $intervention, 'Vis titane');

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $response = $this->request($client, 'POST', "/api/material-item-requests/{$requestId}/ignore", $managerToken);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $sent = array_values(array_filter($transport->getSent(), static fn ($e) => $e->getMessage() instanceof CatalogueRequestProcessedMessage));
        self::assertCount(1, $sent);
        /** @var CatalogueRequestProcessedMessage $message */
        $message = $sent[0]->getMessage();
        self::assertFalse($message->accepted);
    }

    /**
     * Follow-up D-093 (lot notifications catalogue) — la création d'une
     * MaterialItemRequest doit elle aussi annoncer aux managers qu'une proposition
     * attend leur traitement.
     */
    public function test_creating_a_material_request_dispatches_catalogue_request_created_message(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $type = $this->makeType();
        $intervention = $this->makeRealIntervention($mission, $type);

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $requestId = $this->createMaterialRequestOnIntervention($client, $mission, $instrToken, $intervention, 'Plaque de fixation');

        $sent = array_values(array_filter($transport->getSent(), static fn ($e) => $e->getMessage() instanceof CatalogueRequestCreatedMessage));
        self::assertCount(1, $sent);
        /** @var CatalogueRequestCreatedMessage $message */
        $message = $sent[0]->getMessage();
        self::assertSame('MATERIAL_ITEM', $message->kind->value);
        self::assertSame($requestId, $message->requestId);
        self::assertSame('Plaque de fixation', $message->label);
        self::assertSame($mission->getId(), $message->missionId);
    }
}
