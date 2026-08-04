<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\Hospital;
use App\Entity\InterventionType;
use App\Entity\InterventionTypeRequest;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use App\Message\CatalogueRequestProcessedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * EPIC Revue instrumentiste, Lot 3, commit 6 — matrice complète HTTP de
 * POST /api/intervention-type-requests/{id}/ignore : les deux stratégies
 * (KEEP_AS_HISTORY/REASSIGN), le refus 422 sans stratégie quand du matériel existe, les
 * codes d'erreur stables et les autorisations. Le cas "sans matériel, sans stratégie" est
 * déjà couvert par InterventionControllerLot5Test::test_manager_can_ignore_a_request.
 */
final class InterventionTypeRequestIgnoreWorkflowTest extends WebTestCase
{
    private const PASSWORD = 'IgnoreWF15!';
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private array $createdIds = [
        'missions' => [], 'users' => [], 'sites' => [], 'types' => [], 'requests' => [], 'firms' => [], 'items' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            // Efface l'identity map avant tout find() de nettoyage : une entité comme
            // MissionIntervention créée dans ce test via `new Mission()` (jamais
            // re-requêtée depuis la DB) laisse sa collection interventions() en mémoire
            // (une ArrayCollection vide figée à la construction, jamais synchronisée côté
            // inverse par Doctrine) — sans clear(), find() renverrait cet objet mis en
            // cache tel quel plutôt que de recharger l'état réel depuis la base.
            $this->em->clear();
            foreach ($this->createdIds['missions'] as $missionId) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $missionId]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            $this->em->flush();
            // Retire tout MaterialLine des missions traquées, qu'il soit encore attaché
            // au draft (KEEP_AS_HISTORY) ou déjà repointé sur une MissionIntervention
            // (REASSIGN) — getDraft()->getMaterialLines() seul manquerait ce second cas.
            foreach ($this->createdIds['missions'] as $missionId) {
                foreach ($this->em->getRepository(MaterialLine::class)->findBy(['mission' => $missionId]) as $line) {
                    $this->em->remove($line);
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
            foreach ($this->createdIds['types'] as $id) {
                $e = $this->em->find(InterventionType::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            foreach ($this->createdIds['items'] as $id) {
                $e = $this->em->find(MaterialItem::class, $id);
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
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('ignorewf-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('IgnoreWF');
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
        $h->setName('IgnoreWFSite-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeFirm(): Firm
    {
        $f = new Firm();
        $f->setName('IgnoreWFFirm-' . bin2hex(random_bytes(3)));
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

    private function makeType(): InterventionType
    {
        $t = new InterventionType();
        $t->setCode('IWF-' . bin2hex(random_bytes(4)));
        $t->setLabel('Type IgnoreWF ' . bin2hex(random_bytes(2)));
        $t->setActive(true);
        $this->em->persist($t);
        $this->em->flush();
        $this->createdIds['types'][] = $t->getId();
        return $t;
    }

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

    private function createPendingRequest(KernelBrowser $client, Mission $mission, string $instrToken, string $label = 'Demande IgnoreWF'): int
    {
        $created = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $instrToken, [
            'label' => $label,
        ]);
        self::assertSame(Response::HTTP_CREATED, $created->getStatusCode(), $created->getContent());
        $requestId = json_decode($created->getContent(), true)['id'];
        $this->createdIds['requests'][] = $requestId;
        return $requestId;
    }

    private function makeRealIntervention(Mission $mission, InterventionType $type): MissionIntervention
    {
        $i = new MissionIntervention();
        $i->setMission($mission)->setInterventionType($type)->setCode($type->getCode())->setLabel($type->getLabel())->setOrderIndex(0);
        $this->em->persist($i);
        $this->em->flush();
        return $i;
    }

    // ── KEEP_AS_HISTORY ─────────────────────────────────────────────

    public function test_keep_as_history_with_material_freezes_it_on_the_draft(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);

        // $req/getMission()/getInstrumentist() sont re-résolus via $this->em plutôt que
        // les variables $mission/$instr capturées avant les appels HTTP précédents : le
        // client de test reboote le kernel entre requêtes (comportement par défaut de
        // KernelBrowser), ce qui peut fermer/désenregistrer l'EntityManager d'origine —
        // find() garantit une entité gérée par l'EM courant.
        $req = $this->em->find(InterventionTypeRequest::class, $requestId);
        $draft = $req->getDraft();
        $freshMission = $req->getMission();
        $item = $this->makeItem($this->makeFirm());
        $line = new MaterialLine();
        $line->setMission($freshMission)->setItem($item)->setInterventionDraft($draft)->setCreatedBy($freshMission->getInstrumentist());
        $this->em->persist($line);
        $this->em->flush();
        $lineId = $line->getId();

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);

        $response = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/ignore", $managerToken, [
            'strategy' => 'KEEP_AS_HISTORY',
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertSame('IGNORED', $body['status']);
        self::assertSame('KEPT_AS_HISTORY', $body['draftStatus']);
        self::assertNull($body['missionInterventionId']);

        $this->em->clear();
        $reloadedLine = $this->em->find(MaterialLine::class, $lineId);
        self::assertSame($draft->getId(), $reloadedLine->getInterventionDraft()?->getId(), 'material must stay on the draft, never deleted or moved');
    }

    // ── REASSIGN ────────────────────────────────────────────────────

    public function test_reassign_with_material_repoints_it_to_the_target(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);
        $type = $this->makeType();

        // $req->getMission() plutôt que $mission directement — voir commentaire de
        // test_keep_as_history_with_material_freezes_it_on_the_draft() : le kernel
        // reboote entre requêtes HTTP, $mission peut être détachée entre-temps.
        $req = $this->em->find(InterventionTypeRequest::class, $requestId);
        $freshMission = $req->getMission();
        $target = $this->makeRealIntervention($freshMission, $type);
        $item = $this->makeItem($this->makeFirm());
        $line = new MaterialLine();
        $line->setMission($freshMission)->setItem($item)->setInterventionDraft($req->getDraft())->setCreatedBy($freshMission->getInstrumentist());
        $this->em->persist($line);
        $this->em->flush();
        $lineId = $line->getId();

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);

        $response = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/ignore", $managerToken, [
            'strategy' => 'REASSIGN',
            'missionInterventionId' => $target->getId(),
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertSame('IGNORED', $body['status']);
        self::assertSame('MATERIAL_REASSIGNED', $body['draftStatus']);
        self::assertSame($target->getId(), $body['missionInterventionId']);

        $this->em->clear();
        $reloadedLine = $this->em->find(MaterialLine::class, $lineId);
        self::assertSame($target->getId(), $reloadedLine->getMissionIntervention()?->getId());
        self::assertNull($reloadedLine->getInterventionDraft());
    }

    public function test_reassign_target_from_another_mission_returns_404(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);
        $type = $this->makeType();

        $otherSite = $this->makeSite();
        $otherSurgeon = $this->createUser('ROLE_SURGEON');
        $otherInstr = $this->createUser('ROLE_INSTRUMENTIST');
        $otherMission = $this->makeEncodableMission($otherSurgeon, $otherInstr, $otherSite);
        $foreignTarget = $this->makeRealIntervention($otherMission, $type);

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);

        $response = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/ignore", $managerToken, [
            'strategy' => 'REASSIGN',
            'missionInterventionId' => $foreignTarget->getId(),
        ]);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('MATERIAL_ATTACHMENT_TARGET_NOT_FOUND', json_decode($response->getContent(), true)['error']['code']);
    }

    public function test_reassign_with_nonexistent_target_returns_404(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);

        $response = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/ignore", $managerToken, [
            'strategy' => 'REASSIGN',
            'missionInterventionId' => 999999999,
        ]);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function test_reassign_without_target_id_returns_422(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);

        $response = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/ignore", $managerToken, [
            'strategy' => 'REASSIGN',
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    // ── Erreurs / autorisations ─────────────────────────────────────

    public function test_missing_strategy_with_material_returns_422_with_stable_code(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);

        $req = $this->em->find(InterventionTypeRequest::class, $requestId);
        $freshMission = $req->getMission();
        $item = $this->makeItem($this->makeFirm());
        $line = new MaterialLine();
        $line->setMission($freshMission)->setItem($item)->setInterventionDraft($req->getDraft())->setCreatedBy($freshMission->getInstrumentist());
        $this->em->persist($line);
        $this->em->flush();

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);

        $response = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/ignore", $managerToken, []);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('MISSING_IGNORE_STRATEGY', json_decode($response->getContent(), true)['error']['code']);
    }

    public function test_invalid_strategy_value_returns_422(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);

        $response = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/ignore", $managerToken, [
            'strategy' => 'NOT_A_REAL_STRATEGY',
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function test_legacy_request_without_draft_returns_409(): void
    {
        [$client, $mission] = $this->bootMissionScenario();
        $instr = $mission->getInstrumentist();

        $req = new InterventionTypeRequest();
        $req->setMission($mission)->setLabel('Demande héritée sans draft')->setCreatedBy($instr);
        $this->em->persist($req);
        $this->em->flush();
        $this->createdIds['requests'][] = $req->getId();

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);

        $response = $this->request($client, 'POST', "/api/intervention-type-requests/{$req->getId()}/ignore", $managerToken, []);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('INTERVENTION_TYPE_REQUEST_WITHOUT_DRAFT', json_decode($response->getContent(), true)['error']['code']);
    }

    public function test_second_ignore_attempt_returns_409(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);

        $first = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/ignore", $managerToken, []);
        self::assertSame(Response::HTTP_OK, $first->getStatusCode(), $first->getContent());

        $second = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/ignore", $managerToken, []);
        self::assertSame(Response::HTTP_CONFLICT, $second->getStatusCode());
        self::assertSame('DRAFT_ALREADY_RESOLVED', json_decode($second->getContent(), true)['error']['code']);
    }

    public function test_admin_is_authorized_to_ignore(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);

        $admin = $this->createUser('ROLE_ADMIN');
        $adminToken = $this->login($client, $admin);

        $response = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/ignore", $adminToken, []);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
    }

    public function test_instrumentist_cannot_ignore(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);

        $response = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/ignore", $instrToken, []);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    // ── Notification (D-093) ─────────────────────────────────────────────────

    public function test_ignore_dispatches_catalogue_request_processed_message_not_accepted(): void
    {
        [$client, $mission, $instrToken, $instr] = $this->bootMissionScenario();
        // disableReboot() : sans lui, le client reboote le kernel à chaque requête et le
        // transport capturé ci-dessous devient obsolète (voir MissionPublishControllerTest,
        // même précaution).
        $client->disableReboot();
        $requestId = $this->createPendingRequest($client, $mission, $instrToken, 'PTG proposée');

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $response = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/ignore", $managerToken, []);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());

        $sent = array_values(array_filter($transport->getSent(), static fn ($e) => $e->getMessage() instanceof CatalogueRequestProcessedMessage));
        self::assertCount(1, $sent);
        /** @var CatalogueRequestProcessedMessage $message */
        $message = $sent[0]->getMessage();
        self::assertFalse($message->accepted);
        self::assertSame('INTERVENTION_TYPE', $message->kind->value);
        self::assertSame($instr->getId(), $message->recipientUserId);
    }
}
