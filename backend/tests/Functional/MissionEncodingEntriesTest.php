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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * EPIC Revue instrumentiste, Lot 3, commit 7 — modèle de lecture unifié de l'écran
 * d'encodage : GET /api/missions/{id}/encoding expose désormais `entries`, une liste
 * unique triée par orderIndex mêlant MissionIntervention (kind=INTERVENTION) et
 * MissionInterventionDraft (kind=DRAFT) encore utiles à l'instrumentiste, en plus du
 * champ `interventions` historique conservé pour compatibilité (voir
 * InterventionEncodingIntelligenceTest, non touché par ce commit).
 */
final class MissionEncodingEntriesTest extends WebTestCase
{
    private const PASSWORD = 'EntriesLot15!';
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private array $createdIds = [
        'missions' => [], 'users' => [], 'sites' => [], 'firms' => [], 'types' => [], 'requests' => [], 'items' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            // Voir InterventionTypeRequestIgnoreWorkflowTest (commit 6) : le kernel
            // reboote entre requêtes HTTP, l'identity map de $this->em peut porter des
            // objets périmés — clear() garantit que chaque find() de nettoyage relit
            // réellement l'état courant en base.
            $this->em->clear();
            foreach ($this->createdIds['missions'] as $missionId) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $missionId]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $missionId) {
                foreach ($this->em->getRepository(MaterialLine::class)->findBy(['mission' => $missionId]) as $line) {
                    $this->em->remove($line);
                }
                foreach ($this->em->getRepository(MaterialItemRequest::class)->findBy(['mission' => $missionId]) as $req) {
                    $this->em->remove($req);
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
            foreach ($this->createdIds['items'] as $id) {
                $e = $this->em->find(MaterialItem::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['types'] as $id) {
                $e = $this->em->find(InterventionType::class, $id);
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
        $u->setEmail('entries-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Entries');
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
        $h->setName('EntriesSite-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeFirm(): Firm
    {
        $f = new Firm();
        $f->setName('EntriesFirm-' . bin2hex(random_bytes(3)));
        $f->setActive(true);
        $this->em->persist($f);
        $this->em->flush();
        $this->createdIds['firms'][] = $f->getId();
        return $f;
    }

    private function makeType(): InterventionType
    {
        $t = new InterventionType();
        $t->setCode('ENT-' . bin2hex(random_bytes(4)));
        $t->setLabel('Type Entries ' . bin2hex(random_bytes(2)));
        $t->setActive(true);
        $this->em->persist($t);
        $this->em->flush();
        $this->createdIds['types'][] = $t->getId();
        return $t;
    }

    private function makeItem(Firm $firm): MaterialItem
    {
        $i = new MaterialItem();
        $i->setFirm($firm);
        $i->setLabel('Item-' . bin2hex(random_bytes(3)));
        $i->setUnit('pièce');
        $i->setReferenceCode(bin2hex(random_bytes(4)));
        $i->setActive(true);
        $this->em->persist($i);
        $this->em->flush();
        $this->createdIds['items'][] = $i->getId();
        return $i;
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

    private function createPendingRequest(KernelBrowser $client, Mission $mission, string $instrToken, string $label = 'Demande Entries'): int
    {
        $created = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $instrToken, [
            'label' => $label,
        ]);
        self::assertSame(Response::HTTP_CREATED, $created->getStatusCode(), $created->getContent());
        $requestId = json_decode($created->getContent(), true)['id'];
        $this->createdIds['requests'][] = $requestId;
        return $requestId;
    }

    private function draftIdForRequest(int $requestId): int
    {
        $req = $this->em->find(InterventionTypeRequest::class, $requestId);
        return $req->getDraft()->getId();
    }

    private function addMaterialLineToDraft(KernelBrowser $client, Mission $mission, string $instrToken, MaterialItem $item, int $draftId): Response
    {
        return $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-lines", $instrToken, [
            'itemId' => $item->getId(),
            'interventionDraftId' => $draftId,
            'quantity' => 1,
        ]);
    }

    private function addMaterialItemRequestToDraft(KernelBrowser $client, Mission $mission, string $instrToken, string $label, int $draftId): Response
    {
        return $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-item-requests", $instrToken, [
            'label' => $label,
            'interventionDraftId' => $draftId,
        ]);
    }

    private function makeRealIntervention(KernelBrowser $client, Mission $mission, string $instrToken, InterventionType $type, ?Firm $firm = null, int $orderIndex = 0): int
    {
        $body = ['interventionTypeId' => $type->getId(), 'orderIndex' => $orderIndex];
        if ($firm !== null) {
            $body['primaryFirmId'] = $firm->getId();
        }
        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $instrToken, $body);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        return json_decode($response->getContent(), true)['id'];
    }

    private function getEncoding(KernelBrowser $client, Mission $mission, string $token): array
    {
        $response = $this->request($client, 'GET', "/api/missions/{$mission->getId()}/encoding", $token);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        return json_decode($response->getContent(), true);
    }

    private function entryFor(array $body, string $kind, int $id): ?array
    {
        foreach ($body['entries'] as $entry) {
            if ($entry['kind'] === $kind && $entry['id'] === $id) {
                return $entry;
            }
        }
        return null;
    }

    // ── Composition de base ──────────────────────────────────────────

    public function test_mission_with_only_real_interventions(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $type = $this->makeType();
        $id0 = $this->makeRealIntervention($client, $mission, $instrToken, $type, orderIndex: 0);
        $id1 = $this->makeRealIntervention($client, $mission, $instrToken, $type, orderIndex: 1);

        $body = $this->getEncoding($client, $mission, $instrToken);

        self::assertCount(2, $body['entries']);
        self::assertSame(['INTERVENTION', 'INTERVENTION'], array_column($body['entries'], 'kind'));
        self::assertSame([$id0, $id1], array_column($body['entries'], 'id'));
        self::assertSame([0, 1], array_column($body['entries'], 'orderIndex'));
    }

    public function test_mission_with_only_an_open_draft(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);
        $draftId = $this->draftIdForRequest($requestId);

        $body = $this->getEncoding($client, $mission, $instrToken);

        self::assertCount(1, $body['entries']);
        $entry = $body['entries'][0];
        self::assertSame('DRAFT', $entry['kind']);
        self::assertSame($draftId, $entry['id']);
        self::assertSame($requestId, $entry['requestId']);
        self::assertSame('OPEN', $entry['status']);
        self::assertFalse($entry['readOnly']);
        self::assertSame([], $entry['materialLines']);
        self::assertSame([], $entry['materialItemRequests']);
    }

    public function test_mixed_interventions_and_drafts_with_interleaved_order(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $type = $this->makeType();

        $interventionId0 = $this->makeRealIntervention($client, $mission, $instrToken, $type, orderIndex: 0);
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);
        $draftId = $this->draftIdForRequest($requestId);
        // Le draft occupe la position suivante — allocation naturelle via
        // MissionEntryOrderAllocator (commit 2), jamais forcée manuellement ici.
        $interventionId2 = $this->makeRealIntervention($client, $mission, $instrToken, $type, orderIndex: 2);

        $body = $this->getEncoding($client, $mission, $instrToken);

        self::assertSame(
            [
                ['INTERVENTION', $interventionId0],
                ['DRAFT', $draftId],
                ['INTERVENTION', $interventionId2],
            ],
            array_map(static fn (array $e) => [$e['kind'], $e['id']], $body['entries']),
        );
        self::assertSame([0, 1, 2], array_column($body['entries'], 'orderIndex'));
    }

    // ── Matériel sur un draft OPEN ───────────────────────────────────

    public function test_open_draft_with_a_material_line(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $firm = $this->makeFirm();
        $item = $this->makeItem($firm);
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);
        $draftId = $this->draftIdForRequest($requestId);

        $added = $this->addMaterialLineToDraft($client, $mission, $instrToken, $item, $draftId);
        self::assertSame(Response::HTTP_CREATED, $added->getStatusCode(), $added->getContent());

        $body = $this->getEncoding($client, $mission, $instrToken);
        $entry = $this->entryFor($body, 'DRAFT', $draftId);
        self::assertNotNull($entry);
        self::assertCount(1, $entry['materialLines']);
        self::assertSame($item->getId(), $entry['materialLines'][0]['item']['id']);
        self::assertSame([], $entry['materialItemRequests']);
    }

    public function test_open_draft_with_a_material_item_request(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);
        $draftId = $this->draftIdForRequest($requestId);

        $added = $this->addMaterialItemRequestToDraft($client, $mission, $instrToken, 'Ancre manquante', $draftId);
        self::assertSame(Response::HTTP_CREATED, $added->getStatusCode(), $added->getContent());

        $body = $this->getEncoding($client, $mission, $instrToken);
        $entry = $this->entryFor($body, 'DRAFT', $draftId);
        self::assertNotNull($entry);
        self::assertSame([], $entry['materialLines']);
        self::assertCount(1, $entry['materialItemRequests']);
        self::assertSame('Ancre manquante', $entry['materialItemRequests'][0]['label']);
    }

    public function test_open_draft_with_a_mix_of_both_material_types(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $firm = $this->makeFirm();
        $item = $this->makeItem($firm);
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);
        $draftId = $this->draftIdForRequest($requestId);

        $this->addMaterialLineToDraft($client, $mission, $instrToken, $item, $draftId);
        $this->addMaterialItemRequestToDraft($client, $mission, $instrToken, 'Ancre manquante', $draftId);

        $body = $this->getEncoding($client, $mission, $instrToken);
        $entry = $this->entryFor($body, 'DRAFT', $draftId);
        self::assertCount(1, $entry['materialLines']);
        self::assertCount(1, $entry['materialItemRequests']);
    }

    public function test_material_never_appears_twice_across_entries(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $firm = $this->makeFirm();
        $item = $this->makeItem($firm);
        $type = $this->makeType();

        $interventionId = $this->makeRealIntervention($client, $mission, $instrToken, $type, orderIndex: 0);
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);
        $draftId = $this->draftIdForRequest($requestId);
        $this->addMaterialLineToDraft($client, $mission, $instrToken, $item, $draftId);

        $body = $this->getEncoding($client, $mission, $instrToken);

        $seenLineIds = [];
        foreach ($body['entries'] as $entry) {
            foreach ($entry['materialLines'] as $line) {
                $seenLineIds[] = $line['id'];
            }
        }
        self::assertCount(1, $seenLineIds, 'the same MaterialLine must never appear under two different entries');

        // Et l'intervention réelle, elle, ne porte aucun matériel emprunté au draft.
        $interventionEntry = $this->entryFor($body, 'INTERVENTION', $interventionId);
        self::assertSame([], $interventionEntry['materialLines']);
    }

    // ── États terminaux du draft ──────────────────────────────────────

    public function test_converted_draft_is_not_shown_as_a_duplicate(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);
        $draftId = $this->draftIdForRequest($requestId);
        $type = $this->makeType();

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);
        $resolve = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/resolve", $managerToken, [
            'interventionTypeId' => $type->getId(),
        ]);
        self::assertSame(Response::HTTP_OK, $resolve->getStatusCode(), $resolve->getContent());
        $interventionId = json_decode($resolve->getContent(), true)['missionInterventionId'];

        $body = $this->getEncoding($client, $mission, $instrToken);

        self::assertCount(1, $body['entries'], 'CONVERTED draft must not add a second row next to the real intervention');
        self::assertSame('INTERVENTION', $body['entries'][0]['kind']);
        self::assertSame($interventionId, $body['entries'][0]['id']);
        self::assertNull($this->entryFor($body, 'DRAFT', $draftId));
    }

    public function test_material_reassigned_draft_is_not_shown_as_an_empty_duplicate(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $type = $this->makeType();
        $targetId = $this->makeRealIntervention($client, $mission, $instrToken, $type, orderIndex: 0);

        $firm = $this->makeFirm();
        $item = $this->makeItem($firm);
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);
        $draftId = $this->draftIdForRequest($requestId);
        $this->addMaterialLineToDraft($client, $mission, $instrToken, $item, $draftId);

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);
        $ignore = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/ignore", $managerToken, [
            'strategy' => 'REASSIGN',
            'missionInterventionId' => $targetId,
        ]);
        self::assertSame(Response::HTTP_OK, $ignore->getStatusCode(), $ignore->getContent());

        $body = $this->getEncoding($client, $mission, $instrToken);

        self::assertCount(1, $body['entries'], 'MATERIAL_REASSIGNED draft must not appear as an empty leftover row');
        $targetEntry = $this->entryFor($body, 'INTERVENTION', $targetId);
        self::assertNotNull($targetEntry);
        self::assertCount(1, $targetEntry['materialLines'], 'the material now belongs to the real target intervention');
        self::assertNull($this->entryFor($body, 'DRAFT', $draftId));
    }

    public function test_kept_as_history_draft_visible_read_only_when_it_holds_history(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $firm = $this->makeFirm();
        $item = $this->makeItem($firm);
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);
        $draftId = $this->draftIdForRequest($requestId);
        $this->addMaterialLineToDraft($client, $mission, $instrToken, $item, $draftId);

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);
        $ignore = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/ignore", $managerToken, [
            'strategy' => 'KEEP_AS_HISTORY',
        ]);
        self::assertSame(Response::HTTP_OK, $ignore->getStatusCode(), $ignore->getContent());

        $body = $this->getEncoding($client, $mission, $instrToken);

        $entry = $this->entryFor($body, 'DRAFT', $draftId);
        self::assertNotNull($entry, 'a KEPT_AS_HISTORY draft holding material must remain visible');
        self::assertSame('KEPT_AS_HISTORY', $entry['status']);
        self::assertTrue($entry['readOnly']);
        self::assertCount(1, $entry['materialLines']);
    }

    public function test_kept_as_history_draft_without_material_is_hidden(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);
        $draftId = $this->draftIdForRequest($requestId);

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);
        $ignore = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/ignore", $managerToken, []);
        self::assertSame(Response::HTTP_OK, $ignore->getStatusCode(), $ignore->getContent());
        self::assertSame('KEPT_AS_HISTORY', json_decode($ignore->getContent(), true)['draftStatus']);

        $body = $this->getEncoding($client, $mission, $instrToken);

        self::assertCount(0, $body['entries'], 'an empty KEPT_AS_HISTORY draft has nothing worth showing');
    }

    // ── Firme demandée : snapshot ─────────────────────────────────────

    public function test_requested_firm_snapshot_survives_the_firm_being_deleted(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $firm = $this->makeFirm();
        $firmId = $firm->getId();
        $firmName = $firm->getName();

        $created = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $instrToken, [
            'label' => 'Demande avec firme',
            'requestedFirmId' => $firmId,
        ]);
        self::assertSame(Response::HTTP_CREATED, $created->getStatusCode(), $created->getContent());
        $requestId = json_decode($created->getContent(), true)['id'];
        $this->createdIds['requests'][] = $requestId;
        $draftId = $this->draftIdForRequest($requestId);

        // Suppression réelle en base (onDelete: SET NULL sur MissionInterventionDraft::$requestedFirm).
        $this->em->getConnection()->executeStatement('DELETE FROM firm WHERE id = ?', [$firmId]);
        array_splice($this->createdIds['firms'], array_search($firmId, $this->createdIds['firms'], true), 1);
        $this->em->clear();

        $body = $this->getEncoding($client, $mission, $instrToken);

        $entry = $this->entryFor($body, 'DRAFT', $draftId);
        self::assertNotNull($entry);
        self::assertNull($entry['firm'], 'the FK itself is nulled out by the deletion');
        self::assertSame($firmName, $entry['requestedFirmNameSnapshot'], 'the snapshot name must remain readable even after the firm row is gone');
    }

    public function test_no_requested_firm(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);
        $draftId = $this->draftIdForRequest($requestId);

        $body = $this->getEncoding($client, $mission, $instrToken);

        $entry = $this->entryFor($body, 'DRAFT', $draftId);
        self::assertNull($entry['firm']);
        self::assertNull($entry['requestedFirmNameSnapshot']);
    }

    // ── Compatibilité de l'ancien contrat ──────────────────────────────

    public function test_legacy_interventions_field_is_preserved_alongside_entries(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $type = $this->makeType();
        $interventionId = $this->makeRealIntervention($client, $mission, $instrToken, $type, orderIndex: 0);
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);

        $body = $this->getEncoding($client, $mission, $instrToken);

        self::assertArrayHasKey('interventions', $body, 'legacy field must still be present');
        self::assertCount(1, $body['interventions'], 'legacy field must never include drafts');
        self::assertSame($interventionId, $body['interventions'][0]['id']);
        self::assertArrayHasKey('entries', $body);
        self::assertCount(2, $body['entries'], 'the unified field, unlike the legacy one, includes the OPEN draft too');
    }

    // ── Autorisations ──────────────────────────────────────────────────

    public function test_read_authorization_is_unchanged_unrelated_instrumentist_forbidden(): void
    {
        [$client, $mission] = $this->bootMissionScenario();
        $unrelated = $this->createUser('ROLE_INSTRUMENTIST');
        $unrelatedToken = $this->login($client, $unrelated);

        $response = $this->request($client, 'GET', "/api/missions/{$mission->getId()}/encoding", $unrelatedToken);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_read_authorization_is_unchanged_manager_allowed(): void
    {
        [$client, $mission] = $this->bootMissionScenario();
        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);

        $body = $this->getEncoding($client, $mission, $managerToken);

        self::assertArrayHasKey('entries', $body);
    }

    // ── Ordre déterministe en cas d'égalité anormale ────────────────────

    public function test_deterministic_secondary_order_on_duplicate_order_index(): void
    {
        [$client, $mission, $instrToken, $instr] = $this->bootMissionScenario();

        // orderIndex dupliqué construit directement (jamais atteignable via
        // MissionEntryOrderAllocator en usage normal) pour prouver que le tri secondaire
        // est déterministe plutôt que de dépendre de l'ordre d'itération PHP.
        $type = $this->makeType();
        $intervention = new MissionIntervention();
        $intervention->setMission($mission)->setCode($type->getCode())->setLabel($type->getLabel())
            ->setInterventionType($type)->setOrderIndex(5);
        $this->em->persist($intervention);
        $this->em->flush();
        $interventionId = $intervention->getId();

        $req = new InterventionTypeRequest();
        $req->setMission($mission)->setLabel('Demande égalité index')->setCreatedBy($instr);
        $this->em->persist($req);
        $this->em->flush();
        $this->createdIds['requests'][] = $req->getId();

        $draft = new MissionInterventionDraft();
        $draft->setMission($mission)->setInterventionTypeRequest($req)->setLabel('Demande égalité index')
            ->setOrderIndex(5)->setStatus(MissionInterventionDraft::STATUS_OPEN)->setCreatedBy($instr);
        $this->em->persist($draft);
        $this->em->flush();
        $req->setDraft($draft);
        $this->em->flush();
        $draftId = $draft->getId();

        $body = $this->getEncoding($client, $mission, $instrToken);

        self::assertCount(2, $body['entries']);
        self::assertSame(5, $body['entries'][0]['orderIndex']);
        self::assertSame(5, $body['entries'][1]['orderIndex']);
        // Tri secondaire documenté : kind (chaîne) puis id — "DRAFT" < "INTERVENTION"
        // alphabétiquement, donc le draft apparaît en premier à orderIndex égal.
        self::assertSame(['DRAFT', 'INTERVENTION'], array_column($body['entries'], 'kind'));
        self::assertSame([$draftId, $interventionId], array_column($body['entries'], 'id'));
    }
}
