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
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot 5 (D-068) — MissionIntervention rattachée au référentiel InterventionType +
 * primaryFirm facultative ; InterventionTypeRequest ("demande de nouveau type") quand
 * aucun type actif ne correspond, au lieu d'un fallback texte accepté comme catalogué
 * (précédent direct : MaterialItemRequest).
 */
final class InterventionControllerLot5Test extends WebTestCase
{
    private const PASSWORD = 'Lot5Test15!';
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private array $createdIds = [
        'missions' => [], 'users' => [], 'sites' => [], 'firms' => [], 'types' => [], 'requests' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            // EPIC Revue instrumentiste, Lot 3 — POST intervention-type-requests crée
            // désormais aussi un MissionInterventionDraft (FK vers la demande, jamais
            // supprimé automatiquement — voir MissionInterventionDraft, "jamais
            // supprimée") et un AuditEvent (FK vers la mission) : les deux doivent être
            // retirés avant la demande/la mission elles-mêmes, sinon violation de
            // contrainte FK.
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
        $u->setEmail('lot5-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Lot5');
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
        $h->setName('Lot5Site-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeFirm(bool $active = true): Firm
    {
        $f = new Firm();
        $f->setName('Lot5Firm-' . bin2hex(random_bytes(3)));
        $f->setActive($active);
        $this->em->persist($f);
        $this->em->flush();
        $this->createdIds['firms'][] = $f->getId();
        return $f;
    }

    private function makeType(bool $active = true): InterventionType
    {
        $t = new InterventionType();
        $t->setCode('LOT5-' . bin2hex(random_bytes(4)));
        $t->setLabel('Type Lot5 ' . bin2hex(random_bytes(2)));
        $t->setActive($active);
        $this->em->persist($t);
        $this->em->flush();
        $this->createdIds['types'][] = $t->getId();
        return $t;
    }

    /** Mission ASSIGNED, démarrée (startAt passé), instrumentiste = $instr — encodage autorisé. */
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

    // ── Création ──────────────────────────────────────────────────────

    public function test_create_with_type_and_primary_firm_succeeds(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $type = $this->makeType();
        $firm = $this->makeFirm();

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(),
            'primaryFirmId' => $firm->getId(),
            'orderIndex' => 0,
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $id = json_decode($response->getContent(), true)['id'];

        $this->em->clear();
        $intervention = $this->em->find(MissionIntervention::class, $id);
        self::assertNotNull($intervention);
        self::assertSame($type->getId(), $intervention->getInterventionType()?->getId());
        self::assertSame($firm->getId(), $intervention->getPrimaryFirm()?->getId());
        // code/label = instantané dérivé du type, jamais fourni par le client
        self::assertSame($type->getCode(), $intervention->getCode());
        self::assertSame($type->getLabel(), $intervention->getLabel());
    }

    public function test_create_with_type_and_no_primary_firm_succeeds(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $type = $this->makeType();

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(),
            'orderIndex' => 0,
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
    }

    public function test_create_without_intervention_type_id_is_rejected(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'orderIndex' => 0,
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function test_create_with_nonexistent_intervention_type_returns_404_with_stable_code(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => 999999999,
            'orderIndex' => 0,
        ]);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertSame('INTERVENTION_TYPE_NOT_FOUND', $body['error']['code']);
    }

    public function test_create_with_inactive_intervention_type_returns_422_with_stable_code(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $type = $this->makeType(active: false);

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(),
            'orderIndex' => 0,
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertSame('INTERVENTION_TYPE_INACTIVE', $body['error']['code']);
    }

    public function test_create_with_nonexistent_primary_firm_returns_404_with_stable_code(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $type = $this->makeType();

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(),
            'primaryFirmId' => 999999999,
            'orderIndex' => 0,
        ]);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('PRIMARY_FIRM_NOT_FOUND', json_decode($response->getContent(), true)['error']['code']);
    }

    public function test_create_with_inactive_primary_firm_returns_422_with_stable_code(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $type = $this->makeType();
        $firm = $this->makeFirm(active: false);

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(),
            'primaryFirmId' => $firm->getId(),
            'orderIndex' => 0,
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('PRIMARY_FIRM_INACTIVE', json_decode($response->getContent(), true)['error']['code']);
    }

    // ── Mise à jour ───────────────────────────────────────────────────

    public function test_update_intervention_type_re_derives_code_label_snapshot(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $typeA = $this->makeType();
        $typeB = $this->makeType();

        $created = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $typeA->getId(), 'orderIndex' => 0,
        ]);
        $id = json_decode($created->getContent(), true)['id'];

        $response = $this->request($client, 'PATCH', "/api/missions/{$mission->getId()}/interventions/{$id}", $token, [
            'interventionTypeId' => $typeB->getId(),
        ]);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), $response->getContent());
        $this->em->clear();
        $intervention = $this->em->find(MissionIntervention::class, $id);
        self::assertSame($typeB->getId(), $intervention->getInterventionType()?->getId());
        self::assertSame($typeB->getCode(), $intervention->getCode());
        self::assertSame($typeB->getLabel(), $intervention->getLabel());
    }

    public function test_update_can_set_then_remove_primary_firm(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $type = $this->makeType();
        $firm = $this->makeFirm();

        $created = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(), 'orderIndex' => 0,
        ]);
        $id = json_decode($created->getContent(), true)['id'];

        $set = $this->request($client, 'PATCH', "/api/missions/{$mission->getId()}/interventions/{$id}", $token, [
            'primaryFirmId' => $firm->getId(),
        ]);
        self::assertSame(Response::HTTP_NO_CONTENT, $set->getStatusCode());
        $this->em->clear();
        self::assertSame($firm->getId(), $this->em->find(MissionIntervention::class, $id)->getPrimaryFirm()?->getId());

        // Retrait explicite : primaryFirmId présent mais null.
        $remove = $this->request($client, 'PATCH', "/api/missions/{$mission->getId()}/interventions/{$id}", $token, [
            'primaryFirmId' => null,
        ]);
        self::assertSame(Response::HTTP_NO_CONTENT, $remove->getStatusCode());
        $this->em->clear();
        self::assertNull($this->em->find(MissionIntervention::class, $id)->getPrimaryFirm());
    }

    public function test_update_with_primary_firm_key_absent_leaves_it_unchanged(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $type = $this->makeType();
        $firm = $this->makeFirm();

        $created = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(), 'primaryFirmId' => $firm->getId(), 'orderIndex' => 0,
        ]);
        $id = json_decode($created->getContent(), true)['id'];

        // orderIndex seul, primaryFirmId absent du body -> ne doit rien changer sur la firme.
        $response = $this->request($client, 'PATCH', "/api/missions/{$mission->getId()}/interventions/{$id}", $token, [
            'orderIndex' => 3,
        ]);
        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        $this->em->clear();
        $intervention = $this->em->find(MissionIntervention::class, $id);
        self::assertSame($firm->getId(), $intervention->getPrimaryFirm()?->getId());
        self::assertSame(3, $intervention->getOrderIndex());
    }

    public function test_intervention_from_another_mission_is_not_found(): void
    {
        [$client, $missionA, $token, $instr] = $this->bootMissionScenario();
        $site = $this->makeSite();
        $surgeonB = $this->createUser('ROLE_SURGEON');
        $missionB = $this->makeEncodableMission($surgeonB, $instr, $site);
        $type = $this->makeType();

        $created = $this->request($client, 'POST', "/api/missions/{$missionB->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(), 'orderIndex' => 0,
        ]);
        $id = json_decode($created->getContent(), true)['id'];

        // Tentative d'update via l'URL de missionA (mauvaise mission) — doit échouer.
        $response = $this->request($client, 'PATCH', "/api/missions/{$missionA->getId()}/interventions/{$id}", $token, [
            'orderIndex' => 1,
        ]);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    // ── Historisation ─────────────────────────────────────────────────

    public function test_intervention_snapshot_stable_after_type_renamed_and_deactivated(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $type = $this->makeType();
        $originalCode = $type->getCode();
        $originalLabel = $type->getLabel();

        $created = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(), 'orderIndex' => 0,
        ]);
        $id = json_decode($created->getContent(), true)['id'];

        // Le manager renomme et désactive le type ailleurs dans la configuration.
        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);
        $this->request($client, 'PATCH', "/api/intervention-types/{$type->getId()}", $managerToken, [
            'label' => 'Nouveau libellé complètement différent', 'active' => false,
        ]);

        $this->em->clear();
        $intervention = $this->em->find(MissionIntervention::class, $id);
        self::assertSame($originalCode, $intervention->getCode(), 'le code historique ne doit jamais changer');
        self::assertSame($originalLabel, $intervention->getLabel(), 'le libellé historique ne doit jamais changer');
        self::assertSame($type->getId(), $intervention->getInterventionType()?->getId(), 'la relation reste pointée sur le même type');
    }

    public function test_encoding_read_response_exposes_snapshot_and_live_type_reference(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $type = $this->makeType();

        $created = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(), 'orderIndex' => 0,
        ]);
        self::assertSame(Response::HTTP_CREATED, $created->getStatusCode());

        $response = $this->request($client, 'GET', "/api/missions/{$mission->getId()}/encoding", $token);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);

        self::assertCount(1, $body['interventions']);
        $itv = $body['interventions'][0];
        self::assertSame($type->getCode(), $itv['code']);
        self::assertSame($type->getLabel(), $itv['label']);
        self::assertSame($type->getId(), $itv['interventionType']['id']);
        self::assertNull($itv['primaryFirm']);

        // Le catalogue expose les types actifs pour le picker.
        self::assertNotEmpty($body['catalog']['interventionTypes']);
    }

    // ── Demande de nouveau type (Lot 5, D-068) ─────────────────────────

    public function test_instrumentist_can_submit_intervention_type_request(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();

        $response = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $token, [
            'label' => 'Prothèse épaule inversée',
            'suggestedCode' => 'PTE-INV',
            'comment' => 'Pas encore dans le catalogue',
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $id = json_decode($response->getContent(), true)['id'];
        $this->createdIds['requests'][] = $id;

        $req = $this->em->find(InterventionTypeRequest::class, $id);
        self::assertSame(InterventionTypeRequest::STATUS_PENDING, $req->getStatus());

        // Apparaît dans la réponse d'encodage (mission-level, pas rattachée à une intervention).
        $encoding = $this->request($client, 'GET', "/api/missions/{$mission->getId()}/encoding", $token);
        $body = json_decode($encoding->getContent(), true);
        self::assertCount(1, $body['interventionTypeRequests']);
        self::assertSame('Prothèse épaule inversée', $body['interventionTypeRequests'][0]['label']);
    }

    public function test_manager_resolving_request_creates_the_real_intervention(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();

        $created = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $token, [
            'label' => 'Ligamentoplastie', 'suggestedCode' => 'LIG',
        ]);
        $requestId = json_decode($created->getContent(), true)['id'];
        $this->createdIds['requests'][] = $requestId;

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);
        $type = $this->makeType();
        $firm = $this->makeFirm();

        $resolve = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/resolve", $managerToken, [
            'interventionTypeId' => $type->getId(),
            'firmId' => $firm->getId(),
        ]);

        // EPIC Revue instrumentiste, Lot 3, commit 5 — nouveau contrat de réponse
        // (requestId/draftId/missionInterventionId/status/draftStatus/orderIndex),
        // resolve() passe désormais par MissionInterventionDraftService.
        self::assertSame(Response::HTTP_OK, $resolve->getStatusCode(), $resolve->getContent());
        $body = json_decode($resolve->getContent(), true);
        self::assertSame($requestId, $body['requestId']);
        self::assertSame('RESOLVED', $body['status']);
        self::assertSame('CONVERTED', $body['draftStatus']);
        $interventionId = $body['missionInterventionId'];

        $this->em->clear();
        $intervention = $this->em->find(MissionIntervention::class, $interventionId);
        self::assertNotNull($intervention);
        self::assertSame($mission->getId(), $intervention->getMission()->getId());
        self::assertSame($type->getId(), $intervention->getInterventionType()?->getId());
        self::assertSame($firm->getId(), $intervention->getPrimaryFirm()?->getId());

        $req = $this->em->find(InterventionTypeRequest::class, $requestId);
        self::assertSame($body['draftId'], $req->getDraft()->getId());
        self::assertSame($intervention->getId(), $req->getDraft()->getResolvedMissionIntervention()?->getId());
    }

    public function test_manager_can_ignore_a_request(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();

        $created = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $token, [
            'label' => 'Demande à ignorer',
        ]);
        $requestId = json_decode($created->getContent(), true)['id'];
        $this->createdIds['requests'][] = $requestId;

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);

        $response = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/ignore", $managerToken);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertSame('IGNORED', $body['status']);
        // EPIC Revue instrumentiste, Lot 3, commit 6 — sans matériel et sans stratégie
        // fournie, ignore() applique implicitement KEEP_AS_HISTORY sur le draft.
        self::assertSame('KEPT_AS_HISTORY', $body['draftStatus']);
        self::assertNull($body['missionInterventionId']);
    }

    public function test_instrumentist_cannot_resolve_or_list_requests(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();

        $created = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $token, [
            'label' => 'Demande',
        ]);
        $requestId = json_decode($created->getContent(), true)['id'];
        $this->createdIds['requests'][] = $requestId;

        self::assertSame(Response::HTTP_FORBIDDEN, $this->request($client, 'GET', '/api/intervention-type-requests', $token)->getStatusCode());
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/resolve", $token, ['interventionTypeId' => 1])->getStatusCode()
        );
    }

    // ── Historical legacy row (mission #529) ───────────────────────────

    public function test_pre_lot5_legacy_row_is_untouched_and_has_no_type(): void
    {
        $this->boot();
        $legacy = $this->em->getRepository(MissionIntervention::class)->findOneBy(['code' => 'LCA']);

        if ($legacy === null) {
            self::markTestSkipped('Ligne historique mission #529 absente de cet environnement de test.');
        }

        self::assertSame('csd', $legacy->getLabel());
        self::assertNull($legacy->getInterventionType(), 'la ligne pré-Lot 5 non mappée reste sans type — aucun backfill silencieux.');
        self::assertNull($legacy->getPrimaryFirm());
    }
}
