<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\Hospital;
use App\Entity\InterventionType;
use App\Entity\InterventionTypeRequest;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionDraft;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * EPIC Revue instrumentiste, Lot 3, commit 3 — workflow HTTP de création atomique
 * InterventionTypeRequest + MissionInterventionDraft (POST .../intervention-type-requests),
 * et branchement réel de MissionEntryOrderAllocator sur POST .../interventions.
 * N'exerce ni resolve(), ni ignore(), ni le rattachement de matériel (hors périmètre de
 * ce commit) — voir InterventionControllerLot5Test pour ces flux, inchangés ici.
 */
final class MissionInterventionDraftCreationWorkflowTest extends WebTestCase
{
    private const PASSWORD = 'DraftWf15!';
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private array $createdIds = [
        'missions' => [], 'users' => [], 'sites' => [], 'firms' => [], 'types' => [], 'requests' => [], 'interventions' => [],
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

    // ── Fixtures (même style que InterventionControllerLot5Test) ───────

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
        $u->setEmail('draftwf-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('DraftWf');
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
        $h->setName('DraftWfSite-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeFirm(bool $active = true): Firm
    {
        $f = new Firm();
        $f->setName('DraftWfFirm-' . bin2hex(random_bytes(3)));
        $f->setActive($active);
        $this->em->persist($f);
        $this->em->flush();
        $this->createdIds['firms'][] = $f->getId();
        return $f;
    }

    private function makeType(): InterventionType
    {
        $t = new InterventionType();
        $t->setCode('DRAFTWF-' . bin2hex(random_bytes(4)));
        $t->setLabel('Type DraftWf');
        $t->setActive(true);
        $this->em->persist($t);
        $this->em->flush();
        $this->createdIds['types'][] = $t->getId();
        return $t;
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

    /** Mission ASSIGNED, PAS ENCORE démarrée (startAt futur) — encodage refusé pour l'instrumentiste. */
    private function makeNotYetStartedMission(User $surgeon, User $instr, Hospital $site): Mission
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
        $m->setStartAt($now->modify('+1 hour'));
        $m->setEndAt($now->modify('+3 hours'));
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

    private function trackCreatedRequest(Response $response): int
    {
        $id = json_decode($response->getContent(), true)['id'];
        $this->createdIds['requests'][] = $id;
        return $id;
    }

    // ── Création atomique demande + draft ───────────────────────────────

    public function test_creates_exactly_one_open_draft_with_the_associated_request(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $token, [
            'label' => 'Prothèse épaule inversée',
        ]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $requestId = $this->trackCreatedRequest($response);

        $this->em->clear();
        $req = $this->em->find(InterventionTypeRequest::class, $requestId);
        self::assertNotNull($req);
        self::assertSame(InterventionTypeRequest::STATUS_PENDING, $req->getStatus());

        $draft = $req->getDraft();
        self::assertNotNull($draft, 'InterventionTypeRequest must have exactly one draft');
        self::assertSame(MissionInterventionDraft::STATUS_OPEN, $draft->getStatus());

        $count = $this->em->getRepository(MissionInterventionDraft::class)->count(['interventionTypeRequest' => $requestId]);
        self::assertSame(1, $count);
    }

    /**
     * EPIC Revue instrumentiste, Lot 3, commit 8 — enrichissement additif de la réponse
     * (draftId/orderIndex/label/requestedFirm en plus de {id}) : le frontend en a besoin
     * pour normaliser son cache React Query après création sans second aller-retour.
     */
    public function test_creation_response_exposes_draft_fields_needed_by_the_frontend_cache(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $firm = $this->makeFirm();

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $token, [
            'label' => 'Prothèse épaule inversée',
            'requestedFirmId' => $firm->getId(),
        ]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->createdIds['requests'][] = $body['id'];

        $this->em->clear();
        $draft = $this->em->find(InterventionTypeRequest::class, $body['id'])->getDraft();

        self::assertSame($draft->getId(), $body['draftId']);
        self::assertSame($draft->getOrderIndex(), $body['orderIndex']);
        self::assertSame('Prothèse épaule inversée', $body['label']);
        self::assertSame($firm->getId(), $body['requestedFirm']['id']);
        self::assertSame($firm->getName(), $body['requestedFirm']['name']);
    }

    public function test_creation_response_requested_firm_is_null_when_none_given(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $token, [
            'label' => 'Sans firme',
        ]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->createdIds['requests'][] = $body['id'];

        self::assertNull($body['requestedFirm']);
    }

    public function test_label_is_frozen_as_a_snapshot_on_the_draft(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $token, [
            'label' => 'Ligamentoplastie du LCA',
        ]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $requestId = $this->trackCreatedRequest($response);

        $this->em->clear();
        $draft = $this->em->find(InterventionTypeRequest::class, $requestId)->getDraft();
        self::assertSame('Ligamentoplastie du LCA', $draft->getLabel());
    }

    public function test_requested_firm_name_is_frozen_as_a_snapshot(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $firm = $this->makeFirm();

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $token, [
            'label' => 'Prothèse',
            'requestedFirmId' => $firm->getId(),
        ]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $requestId = $this->trackCreatedRequest($response);

        $this->em->clear();
        $draft = $this->em->find(InterventionTypeRequest::class, $requestId)->getDraft();
        self::assertSame($firm->getId(), $draft->getRequestedFirm()->getId());
        self::assertSame($firm->getName(), $draft->getRequestedFirmNameSnapshot());
    }

    public function test_creation_without_requested_firm_succeeds(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $token, [
            'label' => 'Sans firme demandée',
        ]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $requestId = $this->trackCreatedRequest($response);

        $this->em->clear();
        $draft = $this->em->find(InterventionTypeRequest::class, $requestId)->getDraft();
        self::assertNull($draft->getRequestedFirm());
        self::assertNull($draft->getRequestedFirmNameSnapshot());
    }

    /**
     * Couvre à la fois "firme inexistante" et l'atomicité : la résolution de la firme
     * échoue avant que MissionInterventionDraftService::createForRequest() soit appelé
     * — aucune InterventionTypeRequest ne doit avoir été persistée pour cette mission.
     */
    public function test_creation_with_nonexistent_requested_firm_returns_404_and_persists_nothing(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $token, [
            'label' => 'Demande orpheline',
            'requestedFirmId' => 999999999,
        ]);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), $response->getContent());
        self::assertSame('PRIMARY_FIRM_NOT_FOUND', json_decode($response->getContent(), true)['error']['code']);

        $count = $this->em->getRepository(InterventionTypeRequest::class)->count(['mission' => $mission->getId()]);
        self::assertSame(0, $count);
    }

    public function test_creation_with_inactive_requested_firm_returns_422(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $inactiveFirm = $this->makeFirm(active: false);

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $token, [
            'label' => 'Demande',
            'requestedFirmId' => $inactiveFirm->getId(),
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), $response->getContent());
        self::assertSame('PRIMARY_FIRM_INACTIVE', json_decode($response->getContent(), true)['error']['code']);

        $count = $this->em->getRepository(InterventionTypeRequest::class)->count(['mission' => $mission->getId()]);
        self::assertSame(0, $count);
    }

    // ── Audit ────────────────────────────────────────────────────────

    public function test_audit_event_is_created_with_actor_and_identifiers(): void
    {
        [$client, $mission, $token, $instr] = $this->bootMissionScenario();
        $firm = $this->makeFirm();

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $token, [
            'label' => 'Prothèse épaule inversée',
            'requestedFirmId' => $firm->getId(),
        ]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $requestId = $this->trackCreatedRequest($response);

        $this->em->clear();
        $events = $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $mission->getId()]);
        self::assertCount(1, $events);
        $event = $events[0];

        self::assertSame(AuditEventType::MISSION_INTERVENTION_DRAFT_CREATED, $event->getEventType());
        self::assertSame($instr->getId(), $event->getActor()?->getId());
        self::assertSame($requestId, $event->getPayload()['interventionTypeRequestId']);
        self::assertSame($firm->getId(), $event->getPayload()['requestedFirmId']);
    }

    // ── MissionEntryOrderAllocator branché ──────────────────────────────

    public function test_draft_order_index_is_zero_on_an_empty_mission(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $token, [
            'label' => 'Première entrée',
        ]);
        $requestId = $this->trackCreatedRequest($response);

        $this->em->clear();
        self::assertSame(0, $this->em->find(InterventionTypeRequest::class, $requestId)->getDraft()->getOrderIndex());
    }

    public function test_real_interventions_and_drafts_share_the_same_order_sequence(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $type = $this->makeType();

        $interventionResponse = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(),
        ]);
        self::assertSame(Response::HTTP_CREATED, $interventionResponse->getStatusCode(), $interventionResponse->getContent());
        $interventionId = json_decode($interventionResponse->getContent(), true)['id'];
        $this->createdIds['interventions'][] = $interventionId;
        self::assertSame(0, json_decode($interventionResponse->getContent(), true)['orderIndex']);

        $draftResponse = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $token, [
            'label' => 'Deuxième entrée',
        ]);
        $requestId = $this->trackCreatedRequest($draftResponse);

        $this->em->clear();
        self::assertSame(1, $this->em->find(InterventionTypeRequest::class, $requestId)->getDraft()->getOrderIndex());
    }

    public function test_client_supplied_order_index_is_ignored_on_intervention_creation(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $type = $this->makeType();

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(),
            'orderIndex' => 999, // volontairement incohérent — doit être ignoré côté serveur
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->createdIds['interventions'][] = $body['id'];

        self::assertSame(0, $body['orderIndex'], 'server must allocate its own orderIndex, ignoring the client-supplied value');
    }

    /**
     * MAX(orderIndex)+1, jamais count() : après suppression d'une intervention laissant
     * un trou (0, 1 supprimée, reste 0), la prochaine allocation doit rester après le
     * plus grand index existant, jamais réutiliser un index déjà occupé historiquement
     * par un doublon accidentel avec un draft créé entre-temps.
     */
    public function test_no_collision_after_a_gap_in_existing_order_indexes(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $type = $this->makeType();

        $first = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(),
        ]);
        $firstId = json_decode($first->getContent(), true)['id'];

        $second = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(),
        ]);
        $secondId = json_decode($second->getContent(), true)['id'];
        self::assertSame(1, json_decode($second->getContent(), true)['orderIndex']);

        // Supprime la première (orderIndex=0) — un count() naïf recréerait un doublon d'index.
        $delete = $this->request($client, 'DELETE', "/api/missions/{$mission->getId()}/interventions/{$firstId}", $token);
        self::assertSame(Response::HTTP_NO_CONTENT, $delete->getStatusCode());

        $third = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(),
        ]);
        self::assertSame(Response::HTTP_CREATED, $third->getStatusCode(), $third->getContent());
        $thirdBody = json_decode($third->getContent(), true);
        $this->createdIds['interventions'][] = $secondId;
        $this->createdIds['interventions'][] = $thirdBody['id'];

        self::assertSame(2, $thirdBody['orderIndex'], 'must be MAX(existing=1)+1=2, never colliding with the still-present orderIndex=1');
    }

    // ── Droits et gardes ─────────────────────────────────────────────

    public function test_assigned_instrumentist_can_create_a_request(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $token, [
            'label' => 'Autorisé',
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $this->trackCreatedRequest($response);
    }

    public function test_instrumentist_not_assigned_to_the_mission_is_forbidden(): void
    {
        [$client, $mission] = $this->bootMissionScenario();
        $otherInstrumentist = $this->createUser('ROLE_INSTRUMENTIST');
        $otherToken = $this->login($client, $otherInstrumentist);

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $otherToken, [
            'label' => 'Refusé',
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        $count = $this->em->getRepository(InterventionTypeRequest::class)->count(['mission' => $mission->getId()]);
        self::assertSame(0, $count);
    }

    /**
     * La garde "pas d'encodage avant le début de la mission" existe à deux niveaux —
     * MissionVoter::canEditEncoding() (403, évalué en premier par
     * denyAccessUnlessGranted) ET MissionEncodingGuard::assertEncodingAllowed() (409,
     * jamais atteint ici pour un instrumentiste puisque le voter bloque avant). Les deux
     * mécanismes préexistants restent inchangés et actifs — ce test vérifie que la
     * création est bien refusée, quelle que soit la couche qui la bloque en premier.
     */
    public function test_encoding_guard_blocks_creation_before_mission_start(): void
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeNotYetStartedMission($surgeon, $instr, $site);
        $token = $this->login($client, $instr);

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $token, [
            'label' => 'Trop tôt',
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode(), $response->getContent());

        $count = $this->em->getRepository(InterventionTypeRequest::class)->count(['mission' => $mission->getId()]);
        self::assertSame(0, $count);
    }
}
