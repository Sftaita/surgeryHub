<?php

namespace App\Tests\Functional;

use App\Entity\ChoiceOption;
use App\Entity\FinancialCalculation;
use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\Hospital;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\MissionIntervention;
use App\Entity\RequiredChoiceGroup;
use App\Entity\User;
use App\Enum\FinancialCalculationStatus;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-146 bis — fiabilisation des demandes de matériel et d'intervention, de bout en bout
 * via l'API réelle (jamais les services appelés en direct) : firme obligatoire et
 * conservée, quantité exacte, résolution atomique, doublons, invariants communs avec
 * l'encodage classique (délégué, système choisi, exclusivité), missions verrouillées,
 * soumission/validation incomplètes, stratégie d'ignorance explicite, aucun calcul
 * financier déclenché.
 *
 * Tous les noms créés (firmes, types, matériels) sont préfixés « D146T- » : le nettoyage
 * les retrouve même quand c'est l'API (résolution) qui les a créés.
 */
final class CatalogueRequestFiabilisationTest extends WebTestCase
{
    private const PASSWORD = 'D146Fiab15!';
    private const TZ = 'Europe/Brussels';
    private const PREFIX = 'D146T-';

    private EntityManagerInterface $em;
    private KernelBrowser $client;
    /** @var array{missions: list<int>, users: list<int>, sites: list<int>} */
    private array $created = ['missions' => [], 'users' => [], 'sites' => []];

    private User $manager;
    private User $instr;
    private User $surgeon;
    private Hospital $site;
    private string $managerToken;
    private string $instrToken;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->manager = $this->createUser('ROLE_MANAGER');
        $this->instr = $this->createUser('ROLE_INSTRUMENTIST');
        $this->surgeon = $this->createUser('ROLE_SURGEON');
        $this->site = new Hospital();
        $this->site->setName(self::PREFIX . 'Site-' . bin2hex(random_bytes(3)));
        $this->em->persist($this->site);
        $this->em->flush();
        $this->created['sites'][] = $this->site->getId();

        $this->managerToken = $this->login($this->manager);
        $this->instrToken = $this->login($this->instr);
    }

    protected function tearDown(): void
    {
        $conn = $this->conn();
        $missions = $this->created['missions'] ?: [0];
        $users = $this->created['users'] ?: [0];
        $sites = $this->created['sites'] ?: [0];
        $like = self::PREFIX . '%';

        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $firmIds = $conn->fetchFirstColumn('SELECT id FROM firm WHERE name LIKE ?', [$like]) ?: [0];
            $typeIds = $conn->fetchFirstColumn('SELECT id FROM intervention_type WHERE code LIKE ?', [$like]) ?: [0];
            $itemIds = $conn->fetchFirstColumn('SELECT id FROM material_item WHERE firm_id IN (?)', [$firmIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER]) ?: [0];
            $offeringIds = $conn->fetchFirstColumn('SELECT id FROM firm_service_offering WHERE firm_id IN (?)', [$firmIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER]) ?: [0];
            $groupIds = $conn->fetchFirstColumn('SELECT id FROM required_choice_group WHERE firm_service_offering_id IN (?)', [$offeringIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER]) ?: [0];
            $calcIds = $conn->fetchFirstColumn('SELECT id FROM financial_calculation WHERE mission_id IN (?)', [$missions], [\Doctrine\DBAL\ArrayParameterType::INTEGER]) ?: [0];

            $ints = [\Doctrine\DBAL\ArrayParameterType::INTEGER];
            foreach ([
                ['financial_calculation_line', 'financial_calculation_id', $calcIds],
                ['financial_calculation', 'mission_id', $missions],
                ['audit_event', 'mission_id', $missions],
                ['notification_event', 'mission_id', $missions],
                ['outbound_notification', 'mission_id', $missions],
                ['notification_dispatch', 'mission_id', $missions],
                ['material_line', 'mission_id', $missions],
                ['material_item_request', 'mission_id', $missions],
                ['mission_intervention_draft', 'mission_id', $missions],
                ['intervention_type_request', 'mission_id', $missions],
                ['mission_intervention', 'mission_id', $missions],
                ['mission_execution', 'mission_id', $missions],
                ['mission_encoding_comment', 'mission_id', $missions],
                ['mission', 'id', $missions],
                ['choice_option', 'required_choice_group_id', $groupIds],
                ['required_choice_group', 'id', $groupIds],
                ['pricing_rule', 'firm_id', $firmIds],
                ['firm_service_offering', 'id', $offeringIds],
                ['material_item', 'id', $itemIds],
                ['intervention_type', 'id', $typeIds],
                ['firm', 'id', $firmIds],
                ['outbound_notification', 'recipient_user_id', $users],
                ['notification_event', 'user_id', $users],
                ['user', 'id', $users],
                ['hospital', 'id', $sites],
            ] as [$table, $column, $ids]) {
                $conn->executeStatement(sprintf('DELETE FROM `%s` WHERE `%s` IN (?)', $table, $column), [$ids], $ints);
            }
        } finally {
            $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function conn(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    /** EntityManager du conteneur courant, vidé : jamais une entité périmée après une requête HTTP. */
    private function em(): EntityManagerInterface
    {
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        if (!$this->em->isOpen()) {
            // Une résolution refusée ferme l'EntityManager de la requête (wrapInTransaction).
            static::getContainer()->get('doctrine')->resetManager();
            $this->em = static::getContainer()->get(EntityManagerInterface::class);
        }
        $this->em->clear();
        return $this->em;
    }

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('d146t-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('D146');
        $u->setLastname('Test');
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->created['users'][] = $u->getId();
        return $u;
    }

    private function login(User $user): string
    {
        $this->client->request('POST', '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]),
        );
        $data = json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, 'Login failed: ' . $this->client->getResponse()->getContent());
        return $data['token'];
    }

    /** @return array{0: int, 1: array<string, mixed>} [status, json] */
    private function call(string $method, string $uri, ?string $token, ?array $body = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $this->client->request($method, $uri, server: $server, content: $body !== null ? json_encode($body) : null);
        $response = $this->client->getResponse();

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true) ?? []];
    }

    private function errorCode(array $json): ?string
    {
        return $json['error']['code'] ?? $json['code'] ?? null;
    }

    private function mission(MissionStatus $status = MissionStatus::ASSIGNED): Mission
    {
        $em = $this->em();
        $m = new Mission();
        $m->setStatus($status);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setSurgeon($em->find(User::class, $this->surgeon->getId()));
        $m->setInstrumentist($em->find(User::class, $this->instr->getId()));
        $m->setSite($em->find(Hospital::class, $this->site->getId()));
        $m->setCreatedBy($em->find(User::class, $this->manager->getId()));
        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
        $m->setStartAt($now->modify('-3 hours'));
        $m->setEndAt($now->modify('-1 hour'));
        $em->persist($m);
        $em->flush();
        $this->created['missions'][] = $m->getId();
        return $m;
    }

    private function giveHours(int $missionId): void
    {
        $em = $this->em();
        $mission = $em->find(Mission::class, $missionId);
        $execution = new MissionExecution();
        $execution->setMission($mission);
        $execution->setActualStartAt(new \DateTimeImmutable('-3 hours'));
        $execution->setActualEndAt(new \DateTimeImmutable('-1 hour'));
        $mission->setExecution($execution);
        $em->persist($execution);
        $em->flush();
    }

    private function setMissionState(int $missionId, MissionStatus $status, bool $encodingLocked = false, bool $invoiced = false): void
    {
        $em = $this->em();
        $m = $em->find(Mission::class, $missionId);
        $m->setStatus($status);
        if ($encodingLocked) {
            $m->setEncodingLockedAt(new \DateTimeImmutable());
        }
        if ($invoiced) {
            $m->setInvoiceGeneratedAt(new \DateTimeImmutable());
        }
        $em->flush();
    }

    private function firm(string $name = 'Firm'): Firm
    {
        $em = $this->em();
        $f = new Firm();
        $f->setName(self::PREFIX . $name . '-' . bin2hex(random_bytes(3)));
        $f->setActive(true);
        $em->persist($f);
        $em->flush();
        return $f;
    }

    private function type(string $label = 'Type'): InterventionType
    {
        $em = $this->em();
        $t = new InterventionType();
        $t->setCode(self::PREFIX . bin2hex(random_bytes(4)));
        $t->setLabel(self::PREFIX . $label . ' ' . bin2hex(random_bytes(2)));
        $t->setActive(true);
        $em->persist($t);
        $em->flush();
        return $t;
    }

    private function item(int $firmId, string $label = 'Vis', ?string $ref = null): MaterialItem
    {
        $em = $this->em();
        $mi = new MaterialItem();
        $mi->setFirm($em->find(Firm::class, $firmId));
        $mi->setLabel(self::PREFIX . $label . ' ' . bin2hex(random_bytes(2)));
        $mi->setUnit('pièce');
        $mi->setReferenceCode($ref ?? 'REF-' . bin2hex(random_bytes(3)));
        $mi->setIsImplant(false);
        $em->persist($mi);
        $em->flush();
        return $mi;
    }

    private function offering(int $firmId, int $typeId, bool $representativeRelevant = false): FirmServiceOffering
    {
        $em = $this->em();
        $o = new FirmServiceOffering();
        $o->setFirm($em->find(Firm::class, $firmId));
        $o->setInterventionType($em->find(InterventionType::class, $typeId));
        $o->setActive(true);
        $o->setRepresentativePresenceRelevant($representativeRelevant);
        $o->setRepresentativeSuppressesInterventionFee($representativeRelevant);
        $em->persist($o);
        $em->flush();
        return $o;
    }

    /**
     * Groupe de choix obligatoire (type Globus TLIF-1/TLIF-2) sur la prestation ; chaque
     * option est liée à un matériel (exclusivité D-111).
     *
     * @param array<string, ?int> $optionsToItemIds label => materialItemId
     * @return array<string, int> label => choiceOptionId
     */
    private function choiceGroup(int $offeringId, array $optionsToItemIds): array
    {
        $em = $this->em();
        $group = new RequiredChoiceGroup();
        $group->setOffering($em->find(FirmServiceOffering::class, $offeringId));
        $group->setQuestion('Quel système a été utilisé ?');
        $group->setActive(true);
        $em->persist($group);
        $ids = [];
        $order = 0;
        $options = [];
        foreach ($optionsToItemIds as $label => $itemId) {
            $o = new ChoiceOption();
            $o->setGroup($group);
            $o->setLabel($label);
            $o->setMaterialItem($itemId !== null ? $em->find(MaterialItem::class, $itemId) : null);
            $o->setDisplayOrder($order++);
            $o->setActive(true);
            $em->persist($o);
            $options[$label] = $o;
        }
        $em->flush();
        foreach ($options as $label => $o) {
            $ids[$label] = $o->getId();
        }
        return $ids;
    }

    /** Intervention réelle créée via l'API instrumentiste (firme obligatoire). */
    private function addIntervention(int $missionId, int $typeId, int $firmId, array $extra = []): int
    {
        [$status, $json] = $this->call('POST', "/api/missions/{$missionId}/interventions", $this->instrToken,
            ['interventionTypeId' => $typeId, 'primaryFirmId' => $firmId] + $extra);
        self::assertSame(201, $status, json_encode($json));
        return $json['id'];
    }

    /** Intervention « historique » sans firme (antérieure à D-146 bis), insérée directement. */
    private function insertLegacyIntervention(int $missionId, int $typeId, ?int $firmId = null): int
    {
        $em = $this->em();
        $type = $em->find(InterventionType::class, $typeId);
        $i = new MissionIntervention();
        $i->setMission($em->find(Mission::class, $missionId))
            ->setInterventionType($type)
            ->setCode($type->getCode())
            ->setLabel($type->getLabel())
            ->setOrderIndex(9);
        if ($firmId !== null) {
            $i->setPrimaryFirm($em->find(Firm::class, $firmId));
        }
        $em->persist($i);
        $em->flush();
        return $i->getId();
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function materialRequest(int $missionId, array $body): array
    {
        return $this->call('POST', "/api/missions/{$missionId}/material-item-requests", $this->instrToken, $body);
    }

    private function materialRequestOk(int $missionId, array $body): int
    {
        [$status, $json] = $this->materialRequest($missionId, $body + ['label' => self::PREFIX . 'Ancre', 'quantity' => '1']);
        self::assertSame(201, $status, json_encode($json));
        return $json['id'];
    }

    /** @return array{0: int, 1: int} [requestId, draftId] */
    private function interventionRequestOk(int $missionId, array $body): array
    {
        [$status, $json] = $this->call('POST', "/api/missions/{$missionId}/intervention-type-requests", $this->instrToken,
            $body + ['label' => self::PREFIX . 'Intervention demandée']);
        self::assertSame(201, $status, json_encode($json));
        return [$json['id'], $json['draftId']];
    }

    private function listedMaterialRequest(int $requestId, string $status = 'PENDING'): array
    {
        [$code, $json] = $this->call('GET', '/api/material-item-requests?status=' . $status, $this->managerToken);
        self::assertSame(200, $code, json_encode($json));
        foreach ($json['items'] ?? $json as $row) {
            if (($row['id'] ?? null) === $requestId) {
                return $row;
            }
        }
        self::fail("Demande matériel #{$requestId} absente de la liste manager.");
    }

    private function listedInterventionRequest(int $requestId, string $status = 'PENDING'): array
    {
        [$code, $json] = $this->call('GET', '/api/intervention-type-requests?status=' . $status, $this->managerToken);
        self::assertSame(200, $code, json_encode($json));
        foreach ($json['items'] ?? $json as $row) {
            if (($row['id'] ?? null) === $requestId) {
                return $row;
            }
        }
        self::fail("Demande d'intervention #{$requestId} absente de la liste manager.");
    }

    private function encodingEntries(int $missionId): array
    {
        [$status, $json] = $this->call('GET', "/api/missions/{$missionId}/encoding", $this->instrToken);
        self::assertSame(200, $status, json_encode($json));
        return $json;
    }

    private function scalar(string $sql, array $params = []): int
    {
        return (int) $this->conn()->fetchOne($sql, $params);
    }

    // ── 1–8 : demande de matériel ─────────────────────────────────────────────

    /** 1. Firme existante choisie par l'instrumentiste : conservée, relue après rechargement. */
    public function test_01_material_request_with_existing_firm_is_kept(): void
    {
        $m = $this->mission();
        $firm = $this->firm('Arthrex');
        $interventionId = $this->addIntervention($m->getId(), $this->type()->getId(), $firm->getId());

        $id = $this->materialRequestOk($m->getId(), [
            'label' => self::PREFIX . 'Ultrabutton', 'referenceCode' => 'AR-123', 'firmId' => $firm->getId(),
            'quantity' => '1', 'missionInterventionId' => $interventionId, 'comment' => 'posé côté droit',
        ]);

        $row = $this->listedMaterialRequest($id);
        self::assertSame($firm->getId(), $row['proposedFirm']['id']);
        self::assertNull($row['proposedFirmName']);
        self::assertSame('1.00', $row['quantity']);
        self::assertSame('AR-123', $row['referenceCode']);
        self::assertSame('posé côté droit', $row['comment']);
        self::assertSame($interventionId, $row['target']['id']);

        // Rechargement côté instrumentiste (écran d'encodage).
        $encoding = $this->encodingEntries($m->getId());
        $requests = array_merge(...array_map(static fn ($e) => $e['materialItemRequests'] ?? [], $encoding['entries']));
        self::assertSame($firm->getId(), $requests[0]['proposedFirm']['id']);
        self::assertSame('1.00', $requests[0]['quantity']);
    }

    /** 2. Firme inconnue : proposée en texte libre, jamais créée automatiquement. */
    public function test_02_material_request_with_unknown_firm_is_kept_without_creating_it(): void
    {
        $m = $this->mission();
        $interventionId = $this->addIntervention($m->getId(), $this->type()->getId(), $this->firm()->getId());
        $name = self::PREFIX . 'Inconnue ' . bin2hex(random_bytes(2));

        $id = $this->materialRequestOk($m->getId(), [
            'label' => self::PREFIX . 'Ancre', 'proposedFirmName' => "  {$name}  ", 'quantity' => '1', 'missionInterventionId' => $interventionId,
        ]);

        $row = $this->listedMaterialRequest($id);
        self::assertNull($row['proposedFirm']);
        self::assertSame($name, $row['proposedFirmName']);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM firm WHERE name = ?', [$name]));
    }

    /** 3. Firme corrigée par le manager : le matériel créé porte la firme validée, la demande garde la proposition. */
    public function test_03_manager_corrects_the_firm(): void
    {
        $m = $this->mission();
        $proposed = $this->firm('SN');
        $actual = $this->firm('Arthrex');
        $interventionId = $this->addIntervention($m->getId(), $this->type()->getId(), $proposed->getId());
        $id = $this->materialRequestOk($m->getId(), ['label' => self::PREFIX . 'Ancre', 'firmId' => $proposed->getId(), 'quantity' => '1', 'missionInterventionId' => $interventionId]);

        [$status, $json] = $this->call('POST', "/api/material-item-requests/{$id}/resolve", $this->managerToken, [
            'newItem' => ['label' => self::PREFIX . 'Ancre corrigée', 'referenceCode' => 'AR-777', 'firmId' => $actual->getId()],
        ]);
        self::assertSame(200, $status, json_encode($json));
        self::assertTrue($json['createdItem']);
        self::assertFalse($json['createdFirm']);

        $itemFirm = $this->scalar('SELECT mi.firm_id FROM material_line l JOIN material_item mi ON mi.id = l.item_id WHERE l.id = ?', [$json['materialLine']['id']]);
        self::assertSame($actual->getId(), $itemFirm);
        // La proposition de l'instrumentiste reste tracée, l'intervention garde sa firme.
        self::assertSame($proposed->getId(), $this->scalar('SELECT proposed_firm_id FROM material_item_request WHERE id = ?', [$id]));
        self::assertSame($proposed->getId(), $this->scalar('SELECT primary_firm_id FROM mission_intervention WHERE id = ?', [$interventionId]));
        $payload = json_decode((string) $this->conn()->fetchOne("SELECT payload FROM audit_event WHERE mission_id = ? AND event_type = 'MATERIAL_ITEM_REQUEST_RESOLVED'", [$m->getId()]), true);
        self::assertSame($proposed->getId(), $payload['proposedFirmId']);
        self::assertSame($actual->getId(), $payload['firmId']);
    }

    /** 4. Nouvelle firme créée à la résolution ; nouveau matériel UNSPECIFIED, sans aucun tarif. */
    public function test_04_new_firm_and_new_item_without_tariff(): void
    {
        $m = $this->mission();
        $interventionId = $this->addIntervention($m->getId(), $this->type()->getId(), $this->firm()->getId());
        $newFirm = self::PREFIX . 'Nouvelle ' . bin2hex(random_bytes(2));
        $id = $this->materialRequestOk($m->getId(), ['proposedFirmName' => $newFirm, 'quantity' => '1', 'missionInterventionId' => $interventionId]);

        self::assertSame($newFirm, $this->listedMaterialRequest($id)['proposedFirmName']);

        [$status, $json] = $this->call('POST', "/api/material-item-requests/{$id}/resolve", $this->managerToken, [
            'newItem' => ['label' => self::PREFIX . 'Agrafe', 'referenceCode' => 'NF-1', 'newFirmName' => $newFirm],
        ]);
        self::assertSame(200, $status, json_encode($json));
        self::assertTrue($json['createdFirm']);
        self::assertTrue($json['createdItem']);

        $itemId = $this->scalar('SELECT item_id FROM material_line WHERE id = ?', [$json['materialLine']['id']]);
        self::assertSame('UNSPECIFIED', $this->conn()->fetchOne('SELECT billing_status FROM material_item WHERE id = ?', [$itemId]));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM pricing_rule WHERE material_item_id = ?', [$itemId]));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM firm WHERE name = ?', [$newFirm]));

        // Firme déjà existante sous ce nom : 409, jamais un doublon.
        $id2 = $this->materialRequestOk($m->getId(), ['proposedFirmName' => $newFirm, 'quantity' => '1', 'missionInterventionId' => $interventionId]);
        [$status, $json] = $this->call('POST', "/api/material-item-requests/{$id2}/resolve", $this->managerToken, [
            'newItem' => ['label' => self::PREFIX . 'Autre', 'referenceCode' => 'NF-2', 'newFirmName' => mb_strtoupper($newFirm)],
        ]);
        self::assertSame(409, $status);
        self::assertSame('FIRM_ALREADY_EXISTS', $this->errorCode($json));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM firm WHERE LOWER(name) = LOWER(?)', [$newFirm]));
    }

    /** 5. Quantité > 1 conservée exactement (et corrigeable par le manager). */
    public function test_05_quantity_greater_than_one_is_kept(): void
    {
        $m = $this->mission();
        $firm = $this->firm();
        $interventionId = $this->addIntervention($m->getId(), $this->type()->getId(), $firm->getId());
        $existing = $this->item($firm->getId());

        $id = $this->materialRequestOk($m->getId(), ['firmId' => $firm->getId(), 'quantity' => '3', 'missionInterventionId' => $interventionId]);
        self::assertSame('3.00', $this->listedMaterialRequest($id)['quantity']);
        [$status, $json] = $this->call('POST', "/api/material-item-requests/{$id}/resolve", $this->managerToken, ['materialItemId' => $existing->getId()]);
        self::assertSame(200, $status, json_encode($json));
        self::assertSame('3.00', $this->conn()->fetchOne('SELECT quantity FROM material_line WHERE id = ?', [$json['materialLine']['id']]));

        $id2 = $this->materialRequestOk($m->getId(), ['firmId' => $firm->getId(), 'quantity' => '4', 'missionInterventionId' => $interventionId]);
        [$status, $json] = $this->call('POST', "/api/material-item-requests/{$id2}/resolve", $this->managerToken, ['materialItemId' => $existing->getId(), 'quantity' => '2,5']);
        self::assertSame(200, $status, json_encode($json));
        self::assertSame('2.50', $this->conn()->fetchOne('SELECT quantity FROM material_line WHERE id = ?', [$json['materialLine']['id']]));
    }

    /** 6. Association à un matériel existant : sa firme et ses tarifs restent inchangés. */
    public function test_06_link_to_existing_item_keeps_its_firm(): void
    {
        $m = $this->mission();
        $interventionFirm = $this->firm('Globus');
        $itemFirm = $this->firm('Arthrex');
        $interventionId = $this->addIntervention($m->getId(), $this->type()->getId(), $interventionFirm->getId());
        $existing = $this->item($itemFirm->getId());
        $id = $this->materialRequestOk($m->getId(), ['firmId' => $interventionFirm->getId(), 'quantity' => '1', 'missionInterventionId' => $interventionId]);

        [$status, $json] = $this->call('POST', "/api/material-item-requests/{$id}/resolve", $this->managerToken, ['materialItemId' => $existing->getId()]);
        self::assertSame(200, $status, json_encode($json));
        self::assertFalse($json['createdItem']);
        self::assertSame($existing->getId(), $this->scalar('SELECT item_id FROM material_line WHERE id = ?', [$json['materialLine']['id']]));
        self::assertSame($itemFirm->getId(), $this->scalar('SELECT firm_id FROM material_item WHERE id = ?', [$existing->getId()]));
        self::assertSame($interventionFirm->getId(), $this->scalar('SELECT primary_firm_id FROM mission_intervention WHERE id = ?', [$interventionId]));
    }

    /** 7. Doublons : certain (409), possible (409 sauf confirmation), candidats proposés au manager. */
    public function test_07_duplicate_detection(): void
    {
        $m = $this->mission();
        $firm = $this->firm();
        $other = $this->firm('Autre');
        $interventionId = $this->addIntervention($m->getId(), $this->type()->getId(), $firm->getId());
        $existing = $this->item($firm->getId(), 'Ultrabutton Tib', 'DUP-1');

        $id = $this->materialRequestOk($m->getId(), ['label' => $existing->getLabel(), 'referenceCode' => 'DUP-1', 'firmId' => $firm->getId(), 'quantity' => '1', 'missionInterventionId' => $interventionId]);

        [$status, $json] = $this->call('GET', "/api/material-item-requests/{$id}/duplicate-candidates", $this->managerToken);
        self::assertSame(200, $status, json_encode($json));
        self::assertContains($existing->getId(), array_column($json['items'] ?? $json, 'id'));

        // Même firme + même référence : doublon certain, jamais créé.
        [$status, $json] = $this->call('POST', "/api/material-item-requests/{$id}/resolve", $this->managerToken,
            ['newItem' => ['label' => 'x', 'referenceCode' => 'DUP-1', 'firmId' => $firm->getId()]]);
        self::assertSame(409, $status);
        self::assertSame('MATERIAL_ITEM_DUPLICATE', $this->errorCode($json));
        self::assertSame($existing->getId(), $json['error']['existingMaterialItem']['id']);

        // Même référence chez une autre firme : doublon possible, à confirmer.
        [$status, $json] = $this->call('POST', "/api/material-item-requests/{$id}/resolve", $this->managerToken,
            ['newItem' => ['label' => self::PREFIX . 'Autre nom', 'referenceCode' => 'DUP-1', 'firmId' => $other->getId()]]);
        self::assertSame(409, $status);
        self::assertSame('MATERIAL_ITEM_POSSIBLE_DUPLICATE', $this->errorCode($json));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM material_item WHERE LOWER(reference_code) = ?', ['dup-1']));

        [$status, $json] = $this->call('POST', "/api/material-item-requests/{$id}/resolve", $this->managerToken,
            ['newItem' => ['label' => self::PREFIX . 'Autre nom', 'referenceCode' => 'DUP-1', 'firmId' => $other->getId()], 'confirmPossibleDuplicate' => true]);
        self::assertSame(200, $status, json_encode($json));
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM material_item WHERE LOWER(reference_code) = ?', ['dup-1']));
    }

    /** 8. Demande refusée sans nom, sans firme, avec deux firmes, ou sans quantité valide. */
    public function test_08_invalid_material_requests_are_refused(): void
    {
        $m = $this->mission();
        $firm = $this->firm();
        $interventionId = $this->addIntervention($m->getId(), $this->type()->getId(), $firm->getId());
        $base = ['label' => self::PREFIX . 'Vis', 'firmId' => $firm->getId(), 'quantity' => '1', 'missionInterventionId' => $interventionId];

        [$status, $json] = $this->materialRequest($m->getId(), ['label' => '  '] + $base);
        self::assertSame(422, $status);
        self::assertSame('LABEL_REQUIRED', $this->errorCode($json));
        [$status, $json] = $this->materialRequest($m->getId(), array_diff_key($base, ['firmId' => 1]));
        self::assertSame(422, $status);
        self::assertSame('FIRM_REQUIRED', $this->errorCode($json));
        [$status, $json] = $this->materialRequest($m->getId(), $base + ['proposedFirmName' => 'X']);
        self::assertSame(422, $status);
        self::assertSame('FIRM_AMBIGUOUS', $this->errorCode($json));
        foreach (['0', '-2', 'abc', '1.234'] as $q) {
            [$status, $json] = $this->materialRequest($m->getId(), ['quantity' => $q] + $base);
            self::assertSame(422, $status, "quantité {$q}");
            self::assertSame('QUANTITY_REQUIRED', $this->errorCode($json));
        }
        [$status] = $this->materialRequest($m->getId(), array_diff_key($base, ['quantity' => 1]));
        self::assertSame(422, $status);

        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM material_item_request WHERE mission_id = ?', [$m->getId()]));
    }

    // ── 9–14 : firme des interventions ────────────────────────────────────────

    /** 9. Intervention sans firme refusée (création directe ET demande d'intervention). */
    public function test_09_intervention_without_firm_is_refused(): void
    {
        $m = $this->mission();
        $type = $this->type();

        [$status] = $this->call('POST', "/api/missions/{$m->getId()}/interventions", $this->instrToken, ['interventionTypeId' => $type->getId()]);
        self::assertSame(422, $status);

        [$status, $json] = $this->call('POST', "/api/missions/{$m->getId()}/intervention-type-requests", $this->instrToken, ['label' => self::PREFIX . 'Sans firme']);
        self::assertSame(422, $status);
        self::assertSame('FIRM_REQUIRED', $this->errorCode($json));

        [$status, $json] = $this->call('POST', "/api/missions/{$m->getId()}/intervention-type-requests", $this->instrToken,
            ['label' => '   ', 'requestedFirmId' => $this->firm()->getId()]);
        self::assertSame(422, $status);
        self::assertSame('LABEL_REQUIRED', $this->errorCode($json));

        [$status, $json] = $this->call('POST', "/api/missions/{$m->getId()}/intervention-type-requests", $this->instrToken,
            ['label' => self::PREFIX . 'Deux firmes', 'requestedFirmId' => $this->firm()->getId(), 'proposedFirmName' => 'X']);
        self::assertSame(422, $status);
        self::assertSame('FIRM_AMBIGUOUS', $this->errorCode($json));

        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM mission_intervention WHERE mission_id = ?', [$m->getId()]));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM intervention_type_request WHERE mission_id = ?', [$m->getId()]));

        // Retrait explicite de la firme d'une intervention existante : refusé.
        $interventionId = $this->addIntervention($m->getId(), $type->getId(), $this->firm()->getId());
        [$status] = $this->call('PATCH', "/api/missions/{$m->getId()}/interventions/{$interventionId}", $this->instrToken, ['primaryFirmId' => null]);
        self::assertSame(422, $status);
    }

    /** 10. Firme demandée (existante ou proposée) relue sur le draft et préremplie côté manager. */
    public function test_10_requested_firm_is_kept_on_draft_and_exposed_to_the_manager(): void
    {
        $m = $this->mission();
        $firm = $this->firm('Globus');
        [$reqA] = $this->interventionRequestOk($m->getId(), ['requestedFirmId' => $firm->getId()]);
        $proposed = self::PREFIX . 'Proposée ' . bin2hex(random_bytes(2));
        [$reqB] = $this->interventionRequestOk($m->getId(), ['proposedFirmName' => $proposed]);

        $rowA = $this->listedInterventionRequest($reqA);
        self::assertSame($firm->getId(), $rowA['requestedFirm']['id']);
        $rowB = $this->listedInterventionRequest($reqB);
        self::assertNull($rowB['requestedFirm']);
        self::assertSame($proposed, $rowB['proposedFirmName']);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM firm WHERE name = ?', [$proposed]));

        // Après rechargement, l'écran d'encodage expose la firme de chaque draft.
        $drafts = array_values(array_filter($this->encodingEntries($m->getId())['entries'], static fn ($e) => $e['kind'] === 'DRAFT'));
        self::assertCount(2, $drafts);
        $byFirm = array_map(static fn ($e) => [$e['firm']['id'] ?? null, $e['proposedFirmName']], $drafts);
        self::assertContains([$firm->getId(), null], $byFirm);
        self::assertContains([null, $proposed], $byFirm);
    }

    /** 11. Firme modifiée par le manager à la résolution ; l'instantané de la demande reste intact. */
    public function test_11_manager_modifies_the_firm_at_resolution(): void
    {
        $m = $this->mission();
        $requested = $this->firm('Demandée');
        $validated = $this->firm('Validée');
        $type = $this->type();
        $this->offering($validated->getId(), $type->getId());
        [$req, $draftId] = $this->interventionRequestOk($m->getId(), ['requestedFirmId' => $requested->getId()]);

        [$status, $json] = $this->call('POST', "/api/intervention-type-requests/{$req}/resolve", $this->managerToken,
            ['interventionTypeId' => $type->getId(), 'firmId' => $validated->getId()]);
        self::assertSame(200, $status, json_encode($json));

        $interventionId = $this->scalar('SELECT resolved_mission_intervention_id FROM mission_intervention_draft WHERE id = ?', [$draftId]);
        self::assertSame($validated->getId(), $this->scalar('SELECT primary_firm_id FROM mission_intervention WHERE id = ?', [$interventionId]));
        self::assertSame($requested->getId(), $this->scalar('SELECT requested_firm_id FROM mission_intervention_draft WHERE id = ?', [$draftId]));

        // Sans firme : refus explicite, jamais une firme déduite.
        [$req2] = $this->interventionRequestOk($m->getId(), ['requestedFirmId' => $requested->getId()]);
        [$status, $json] = $this->call('POST', "/api/intervention-type-requests/{$req2}/resolve", $this->managerToken, ['interventionTypeId' => $type->getId()]);
        self::assertSame(422, $status);
        self::assertSame('FIRM_REQUIRED', $this->errorCode($json));
    }

    /** 12 + 13. Firme appliquée à la seule intervention concernée ; plusieurs interventions, plusieurs firmes. */
    public function test_12_13_firm_is_applied_only_to_the_concerned_intervention(): void
    {
        $m = $this->mission();
        $globus = $this->firm('Globus');
        $arthrex = $this->firm('Arthrex');
        $sn = $this->firm('SN');
        $typeA = $this->type('TLIF');
        $typeB = $this->type('Bankart');
        $existingA = $this->addIntervention($m->getId(), $typeA->getId(), $globus->getId());
        $existingB = $this->addIntervention($m->getId(), $typeB->getId(), $arthrex->getId());

        $typeC = $this->type('Méniscectomie');
        [$req, $draftId] = $this->interventionRequestOk($m->getId(), ['requestedFirmId' => $sn->getId()]);
        [$status, $json] = $this->call('POST', "/api/intervention-type-requests/{$req}/resolve", $this->managerToken,
            ['interventionTypeId' => $typeC->getId(), 'firmId' => $sn->getId(), 'offeringDecision' => 'LEAVE_UNCONFIGURED']);
        self::assertSame(200, $status, json_encode($json));
        self::assertNull($json['createdOffering'] ?: null);

        $firms = $this->conn()->fetchAllKeyValue('SELECT id, primary_firm_id FROM mission_intervention WHERE mission_id = ?', [$m->getId()]);
        $newId = $this->scalar('SELECT resolved_mission_intervention_id FROM mission_intervention_draft WHERE id = ?', [$draftId]);
        self::assertCount(3, $firms);
        self::assertSame($globus->getId(), (int) $firms[$existingA]);
        self::assertSame($arthrex->getId(), (int) $firms[$existingB]);
        self::assertSame($sn->getId(), (int) $firms[$newId]);
        // Aucune prestation inventée pour SN × Méniscectomie (« laisser à configurer »).
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM firm_service_offering WHERE firm_id = ? AND intervention_type_id = ?', [$sn->getId(), $typeC->getId()]));
    }

    /** 14. Matériel d'une firme tierce sur une intervention : chaque élément garde sa firme. */
    public function test_14_third_party_material_keeps_its_own_firm(): void
    {
        $m = $this->mission();
        $globus = $this->firm('Globus');
        $arthrex = $this->firm('Arthrex');
        $interventionId = $this->addIntervention($m->getId(), $this->type()->getId(), $globus->getId());
        $id = $this->materialRequestOk($m->getId(), ['firmId' => $arthrex->getId(), 'quantity' => '2', 'missionInterventionId' => $interventionId]);

        [$status, $json] = $this->call('POST', "/api/material-item-requests/{$id}/resolve", $this->managerToken,
            ['newItem' => ['label' => self::PREFIX . 'Ancre Arthrex', 'referenceCode' => 'AR-9', 'firmId' => $arthrex->getId()]]);
        self::assertSame(200, $status, json_encode($json));

        $line = $this->conn()->fetchAssociative('SELECT l.mission_intervention_id, l.quantity, mi.firm_id FROM material_line l JOIN material_item mi ON mi.id = l.item_id WHERE l.id = ?', [$json['materialLine']['id']]);
        self::assertSame($interventionId, (int) $line['mission_intervention_id']);
        self::assertSame($arthrex->getId(), (int) $line['firm_id']);
        self::assertSame('2.00', $line['quantity']);
        self::assertSame($globus->getId(), $this->scalar('SELECT primary_firm_id FROM mission_intervention WHERE id = ?', [$interventionId]));
    }

    // ── 15–17 : invariants communs ────────────────────────────────────────────

    /** 15. Réponse délégué exigée à la résolution quand la prestation la requiert (jamais un « Non » inventé). */
    public function test_15_representative_answer_is_required(): void
    {
        $m = $this->mission();
        $firm = $this->firm('Arthrex');
        $type = $this->type();
        $this->offering($firm->getId(), $type->getId(), representativeRelevant: true);
        [$req, $draftId] = $this->interventionRequestOk($m->getId(), ['requestedFirmId' => $firm->getId()]);

        [$status] = $this->call('POST', "/api/intervention-type-requests/{$req}/resolve", $this->managerToken,
            ['interventionTypeId' => $type->getId(), 'firmId' => $firm->getId()]);
        self::assertSame(422, $status);
        self::assertSame('OPEN', $this->conn()->fetchOne('SELECT status FROM mission_intervention_draft WHERE id = ?', [$draftId]));

        [$status, $json] = $this->call('POST', "/api/intervention-type-requests/{$req}/resolve", $this->managerToken,
            ['interventionTypeId' => $type->getId(), 'firmId' => $firm->getId(), 'representativePresent' => false]);
        self::assertSame(200, $status, json_encode($json));
        $interventionId = $this->scalar('SELECT resolved_mission_intervention_id FROM mission_intervention_draft WHERE id = ?', [$draftId]);
        self::assertSame(0, $this->scalar('SELECT representative_present FROM mission_intervention WHERE id = ?', [$interventionId]));
        self::assertNotNull($this->conn()->fetchOne('SELECT representative_present FROM mission_intervention WHERE id = ?', [$interventionId]));

        // Même règle à la création directe.
        [$status] = $this->call('POST', "/api/missions/{$m->getId()}/interventions", $this->instrToken,
            ['interventionTypeId' => $type->getId(), 'primaryFirmId' => $firm->getId()]);
        self::assertSame(422, $status);
    }

    /** 16. Choix obligatoire (type Globus) exigé à la résolution, jamais sélectionné arbitrairement. */
    public function test_16_required_choice_is_required(): void
    {
        $m = $this->mission();
        $globus = $this->firm('Globus');
        $type = $this->type('TLIF');
        $offering = $this->offering($globus->getId(), $type->getId());
        $options = $this->choiceGroup($offering->getId(), ['ALTERA' => null, 'Cage standard' => null]);
        [$req, $draftId] = $this->interventionRequestOk($m->getId(), ['requestedFirmId' => $globus->getId()]);

        [$status] = $this->call('POST', "/api/intervention-type-requests/{$req}/resolve", $this->managerToken,
            ['interventionTypeId' => $type->getId(), 'firmId' => $globus->getId()]);
        self::assertSame(422, $status);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM mission_intervention WHERE mission_id = ?', [$m->getId()]));

        [$status, $json] = $this->call('POST', "/api/intervention-type-requests/{$req}/resolve", $this->managerToken,
            ['interventionTypeId' => $type->getId(), 'firmId' => $globus->getId(), 'selectedChoiceOptionId' => $options['ALTERA']]);
        self::assertSame(200, $status, json_encode($json));
        $interventionId = $this->scalar('SELECT resolved_mission_intervention_id FROM mission_intervention_draft WHERE id = ?', [$draftId]);
        self::assertSame($options['ALTERA'], $this->scalar('SELECT selected_choice_option_id FROM mission_intervention WHERE id = ?', [$interventionId]));
    }

    /** 17. Exclusivité matérielle : à la résolution d'intervention, de matériel, et au réassignement. */
    public function test_17_material_exclusivity_is_enforced(): void
    {
        $m = $this->mission();
        $globus = $this->firm('Globus');
        $type = $this->type('TLIF');
        $offering = $this->offering($globus->getId(), $type->getId());
        $altera = $this->item($globus->getId(), 'ALTERA');
        $cage = $this->item($globus->getId(), 'Cage');
        $options = $this->choiceGroup($offering->getId(), ['ALTERA' => $altera->getId(), 'Cage standard' => $cage->getId()]);

        // Draft portant du matériel « Cage » ; résolution avec « ALTERA » : refusée, rien de créé.
        [$req, $draftId] = $this->interventionRequestOk($m->getId(), ['requestedFirmId' => $globus->getId()]);
        [$status, $json] = $this->call('POST', "/api/missions/{$m->getId()}/material-lines", $this->instrToken,
            ['itemId' => $cage->getId(), 'interventionDraftId' => $draftId, 'quantity' => 1]);
        self::assertSame(201, $status, json_encode($json));

        [$status, $json] = $this->call('POST', "/api/intervention-type-requests/{$req}/resolve", $this->managerToken,
            ['interventionTypeId' => $type->getId(), 'firmId' => $globus->getId(), 'selectedChoiceOptionId' => $options['ALTERA']]);
        self::assertSame(409, $status, json_encode($json));
        self::assertSame('INCOMPATIBLE_CHOICE_MATERIAL', $this->errorCode($json));
        self::assertSame('OPEN', $this->conn()->fetchOne('SELECT status FROM mission_intervention_draft WHERE id = ?', [$draftId]));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM mission_intervention WHERE mission_id = ?', [$m->getId()]));
        self::assertSame($draftId, $this->scalar('SELECT intervention_draft_id FROM material_line WHERE mission_id = ?', [$m->getId()]));

        // Demande de matériel « Cage » sur une intervention où « ALTERA » est choisi : refusée.
        $interventionId = $this->addIntervention($m->getId(), $type->getId(), $globus->getId(), ['selectedChoiceOptionId' => $options['ALTERA']]);
        $matReq = $this->materialRequestOk($m->getId(), ['firmId' => $globus->getId(), 'quantity' => '1', 'missionInterventionId' => $interventionId]);
        [$status, $json] = $this->call('POST', "/api/material-item-requests/{$matReq}/resolve", $this->managerToken, ['materialItemId' => $cage->getId()]);
        self::assertSame(409, $status, json_encode($json));
        self::assertSame('PENDING', $this->conn()->fetchOne('SELECT status FROM material_item_request WHERE id = ?', [$matReq]));

        // Ignorer en réassignant le matériel « Cage » vers l'intervention « ALTERA » : refusé, rien déplacé.
        [$status, $json] = $this->call('POST', "/api/intervention-type-requests/{$req}/ignore", $this->managerToken,
            ['reason' => 'DUPLICATE', 'comment' => 'doublon', 'strategy' => 'REASSIGN', 'missionInterventionId' => $interventionId]);
        self::assertSame(409, $status, json_encode($json));
        self::assertSame($draftId, $this->scalar('SELECT intervention_draft_id FROM material_line WHERE item_id = ?', [$cage->getId()]));
        self::assertSame('OPEN', $this->conn()->fetchOne('SELECT status FROM mission_intervention_draft WHERE id = ?', [$draftId]));
    }

    // ── 18–19 : missions verrouillées ─────────────────────────────────────────

    /** 18. Mission validée : refus explicite avec la réouverture proposée, jamais automatique. */
    public function test_18_validated_mission_refuses_resolution_and_proposes_reopen(): void
    {
        $m = $this->mission();
        $firm = $this->firm();
        $type = $this->type();
        $this->offering($firm->getId(), $type->getId());
        $interventionId = $this->addIntervention($m->getId(), $type->getId(), $firm->getId());
        $matReq = $this->materialRequestOk($m->getId(), ['firmId' => $firm->getId(), 'quantity' => '1', 'missionInterventionId' => $interventionId]);
        [$req] = $this->interventionRequestOk($m->getId(), ['requestedFirmId' => $firm->getId()]);
        $this->setMissionState($m->getId(), MissionStatus::VALIDATED);

        $row = $this->listedMaterialRequest($matReq);
        self::assertTrue($row['mission']['locked']);
        self::assertTrue($row['mission']['reopenAvailable']);

        [$status, $json] = $this->call('POST', "/api/material-item-requests/{$matReq}/resolve", $this->managerToken, ['materialItemId' => $this->item($firm->getId())->getId()]);
        self::assertSame(409, $status);
        self::assertSame('MISSION_LOCKED_FOR_CATALOGUE_RESOLUTION', $this->errorCode($json));
        self::assertTrue($json['error']['reopenAvailable']);

        [$status, $json] = $this->call('POST', "/api/intervention-type-requests/{$req}/resolve", $this->managerToken,
            ['interventionTypeId' => $type->getId(), 'firmId' => $firm->getId()]);
        self::assertSame(409, $status);
        self::assertSame('MISSION_LOCKED_FOR_CATALOGUE_RESOLUTION', $this->errorCode($json));

        self::assertSame('VALIDATED', $this->conn()->fetchOne('SELECT status FROM mission WHERE id = ?', [$m->getId()]));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM mission_intervention WHERE mission_id = ?', [$m->getId()]));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM material_line WHERE mission_id = ?', [$m->getId()]));
    }

    /** 19. Mission verrouillée financièrement (calcul LOCKED / facturée) : refus, aucune réouverture proposée. */
    public function test_19_financially_locked_mission_refuses_resolution(): void
    {
        $m = $this->mission();
        $firm = $this->firm();
        $interventionId = $this->addIntervention($m->getId(), $this->type()->getId(), $firm->getId());
        $matReq = $this->materialRequestOk($m->getId(), ['firmId' => $firm->getId(), 'quantity' => '1', 'missionInterventionId' => $interventionId]);
        $this->setMissionState($m->getId(), MissionStatus::VALIDATED, encodingLocked: true);
        $em = $this->em();
        $calc = new FinancialCalculation();
        $calc->setMission($em->find(Mission::class, $m->getId()));
        $calc->setStatus(FinancialCalculationStatus::LOCKED);
        $calc->setEffectiveAt(new \DateTimeImmutable('-2 hours'));
        $calc->setCalculatedAt(new \DateTimeImmutable());
        $em->persist($calc);
        $em->flush();

        [$status, $json] = $this->call('POST', "/api/material-item-requests/{$matReq}/resolve", $this->managerToken, ['materialItemId' => $this->item($firm->getId())->getId()]);
        self::assertSame(409, $status);
        self::assertSame('MISSION_LOCKED_FOR_CATALOGUE_RESOLUTION', $this->errorCode($json));
        self::assertFalse($json['error']['reopenAvailable']);

        $m2 = $this->mission();
        $i2 = $this->addIntervention($m2->getId(), $this->type()->getId(), $firm->getId());
        $matReq2 = $this->materialRequestOk($m2->getId(), ['firmId' => $firm->getId(), 'quantity' => '1', 'missionInterventionId' => $i2]);
        $this->setMissionState($m2->getId(), MissionStatus::SUBMITTED, invoiced: true);
        [$status, $json] = $this->call('POST', "/api/material-item-requests/{$matReq2}/resolve", $this->managerToken, ['materialItemId' => $this->item($firm->getId())->getId()]);
        self::assertSame(409, $status);
        self::assertFalse($json['error']['reopenAvailable']);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM material_line WHERE mission_id IN (?, ?)', [$m->getId(), $m2->getId()]));
    }

    // ── 20–21 : soumission / validation ───────────────────────────────────────

    /** 20. Soumission et validation refusées tant qu'une intervention est incomplète (appel API direct). */
    public function test_20_incomplete_interventions_block_submit_and_validate(): void
    {
        $m = $this->mission(MissionStatus::ENCODING_IN_PROGRESS);
        $this->giveHours($m->getId());
        $firm = $this->firm('Arthrex');
        $type = $this->type();
        $this->offering($firm->getId(), $type->getId(), representativeRelevant: true);
        $noFirm = $this->insertLegacyIntervention($m->getId(), $this->type()->getId());
        $noAnswer = $this->insertLegacyIntervention($m->getId(), $type->getId(), $firm->getId());

        [$status, $json] = $this->call('POST', "/api/missions/{$m->getId()}/encoding/complete", $this->instrToken, ['comment' => 'aucun matériel']);
        self::assertSame(422, $status, json_encode($json));
        self::assertSame('ENCODING_INCOMPLETE', $this->errorCode($json));
        $codes = array_map(static fn ($x) => [$x['code'], $x['missionInterventionId'] ?? null, $x['section']], $json['error']['missing']);
        self::assertContains(['INTERVENTION_FIRM', $noFirm, 'interventions'], $codes);
        self::assertContains(['INTERVENTION_REPRESENTATIVE_PRESENCE', $noAnswer, 'interventions'], $codes);
        // Alias legacy utilisé par le frontend (« Terminer l'encodage ») : même refus.
        [$status, $json] = $this->call('POST', "/api/missions/{$m->getId()}/submit", $this->instrToken, ['comment' => 'aucun matériel']);
        self::assertSame(422, $status, json_encode($json));
        self::assertSame('ENCODING_INCOMPLETE', $this->errorCode($json));
        self::assertSame('ENCODING_IN_PROGRESS', $this->conn()->fetchOne('SELECT status FROM mission WHERE id = ?', [$m->getId()]));

        // Même contrôle à la validation manager (mission soumise avant D-146 bis).
        $this->setMissionState($m->getId(), MissionStatus::SUBMITTED);
        [$status, $json] = $this->call('POST', "/api/missions/{$m->getId()}/encoding/validate", $this->managerToken);
        self::assertSame(422, $status, json_encode($json));
        self::assertContains('INTERVENTION_FIRM', array_column($json['error']['missing'], 'code'));
        self::assertSame('SUBMITTED', $this->conn()->fetchOne('SELECT status FROM mission WHERE id = ?', [$m->getId()]));

        // Une fois complétées, la validation passe.
        $this->conn()->executeStatement('UPDATE mission_intervention SET primary_firm_id = ? WHERE id = ?', [$firm->getId(), $noFirm]);
        $this->conn()->executeStatement('UPDATE mission_intervention SET representative_present = 1 WHERE id = ?', [$noAnswer]);
        [$status, $json] = $this->call('POST', "/api/missions/{$m->getId()}/encoding/validate", $this->managerToken);
        self::assertSame(200, $status, json_encode($json));
    }

    /** 21. Demande en attente : la soumission reste possible, la validation est bloquée. */
    public function test_21_pending_requests_block_validation_only(): void
    {
        $m = $this->mission(MissionStatus::ENCODING_IN_PROGRESS);
        $this->giveHours($m->getId());
        $firm = $this->firm();
        $type = $this->type();
        $interventionId = $this->addIntervention($m->getId(), $type->getId(), $firm->getId());
        $matReq = $this->materialRequestOk($m->getId(), ['firmId' => $firm->getId(), 'quantity' => '1', 'missionInterventionId' => $interventionId]);
        [$req] = $this->interventionRequestOk($m->getId(), ['requestedFirmId' => $firm->getId()]);

        [$status, $json] = $this->call('POST', "/api/missions/{$m->getId()}/encoding/complete", $this->instrToken, ['comment' => 'demande en cours']);
        self::assertSame(200, $status, json_encode($json));

        [$status, $json] = $this->call('POST', "/api/missions/{$m->getId()}/encoding/validate", $this->managerToken);
        self::assertSame(422, $status, json_encode($json));
        $codes = array_column($json['error']['missing'], 'code');
        self::assertContains('PENDING_MATERIAL_REQUEST', $codes);
        self::assertContains('PENDING_INTERVENTION_REQUEST', $codes);
        self::assertSame(['requests'], array_values(array_unique(array_column($json['error']['missing'], 'section'))));

        // Demandes traitées par le manager (mission soumise, non verrouillée) → validation possible.
        [$status, $json] = $this->call('POST', "/api/material-item-requests/{$matReq}/resolve", $this->managerToken, ['materialItemId' => $this->item($firm->getId())->getId()]);
        self::assertSame(200, $status, json_encode($json));
        [$status, $json] = $this->call('POST', "/api/intervention-type-requests/{$req}/ignore", $this->managerToken, ['reason' => 'DUPLICATE', 'comment' => 'déjà encodée']);
        self::assertSame(200, $status, json_encode($json));
        [$status, $json] = $this->call('POST', "/api/missions/{$m->getId()}/encoding/validate", $this->managerToken);
        self::assertSame(200, $status, json_encode($json));
    }

    // ── 22–24 ─────────────────────────────────────────────────────────────────

    /** 22. Résolution atomique : un refus tardif annule firme, type et prestation déjà créés. */
    public function test_22_resolution_is_atomic(): void
    {
        $m = $this->mission();
        $other = $this->firm('Autre');
        $otherType = $this->type();
        $foreignOption = $this->choiceGroup($this->offering($other->getId(), $otherType->getId())->getId(), ['Étrangère' => null])['Étrangère'];
        $proposed = self::PREFIX . 'Créée ' . bin2hex(random_bytes(2));
        $code = self::PREFIX . 'NEW-' . bin2hex(random_bytes(2));
        [$req, $draftId] = $this->interventionRequestOk($m->getId(), ['proposedFirmName' => $proposed]);

        // Firme + type + prestation créés dans la transaction, puis option d'une autre prestation → 422.
        [$status, $json] = $this->call('POST', "/api/intervention-type-requests/{$req}/resolve", $this->managerToken, [
            'newInterventionType' => ['code' => $code, 'label' => self::PREFIX . 'Nouvelle intervention'],
            'newFirmName' => $proposed,
            'offeringDecision' => 'FEE_APPLICABLE',
            'selectedChoiceOptionId' => $foreignOption,
        ]);
        self::assertSame(422, $status, json_encode($json));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM firm WHERE name = ?', [$proposed]));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM intervention_type WHERE code = ?', [$code]));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM mission_intervention WHERE mission_id = ?', [$m->getId()]));
        self::assertSame('OPEN', $this->conn()->fetchOne('SELECT status FROM mission_intervention_draft WHERE id = ?', [$draftId]));
        self::assertSame('PENDING', $this->conn()->fetchOne('SELECT status FROM intervention_type_request WHERE id = ?', [$req]));

        // Décision de prestation absente : jamais « Pas de forfait » par défaut.
        [$status, $json] = $this->call('POST', "/api/intervention-type-requests/{$req}/resolve", $this->managerToken, [
            'newInterventionType' => ['code' => $code, 'label' => self::PREFIX . 'Nouvelle intervention'],
            'newFirmName' => $proposed,
        ]);
        self::assertSame(422, $status);
        self::assertSame('OFFERING_DECISION_REQUIRED', $this->errorCode($json));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM firm WHERE name = ?', [$proposed]));

        // Même demande, complète : tout est créé ensemble.
        [$status, $json] = $this->call('POST', "/api/intervention-type-requests/{$req}/resolve", $this->managerToken, [
            'newInterventionType' => ['code' => $code, 'label' => self::PREFIX . 'Nouvelle intervention'],
            'newFirmName' => $proposed,
            'offeringDecision' => 'FEE_APPLICABLE',
        ]);
        self::assertSame(200, $status, json_encode($json));
        self::assertTrue($json['createdFirm']);
        self::assertTrue($json['createdInterventionType']);
        self::assertTrue($json['createdOffering']);
        $offering = $this->conn()->fetchAssociative('SELECT o.fee_applicable FROM firm_service_offering o JOIN firm f ON f.id = o.firm_id WHERE f.name = ?', [$proposed]);
        self::assertSame(1, (int) $offering['fee_applicable']);
        // Le forfait est « applicable » mais aucun montant n'est inventé.
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM pricing_rule p JOIN firm f ON f.id = p.firm_id WHERE f.name = ?', [$proposed]));
    }

    /** 23. Ignorer une demande portant du matériel : stratégie explicite obligatoire. */
    public function test_23_ignore_with_material_on_the_draft_requires_an_explicit_strategy(): void
    {
        $m = $this->mission();
        $firm = $this->firm();
        $item = $this->item($firm->getId());
        $targetId = $this->addIntervention($m->getId(), $this->type()->getId(), $firm->getId());

        [$reqKeep, $draftKeep] = $this->interventionRequestOk($m->getId(), ['requestedFirmId' => $firm->getId()]);
        $this->call('POST', "/api/missions/{$m->getId()}/material-lines", $this->instrToken, ['itemId' => $item->getId(), 'interventionDraftId' => $draftKeep, 'quantity' => 2]);
        [$reqMove, $draftMove] = $this->interventionRequestOk($m->getId(), ['requestedFirmId' => $firm->getId()]);
        $this->call('POST', "/api/missions/{$m->getId()}/material-lines", $this->instrToken, ['itemId' => $item->getId(), 'interventionDraftId' => $draftMove, 'quantity' => 3]);

        $row = $this->listedInterventionRequest($reqKeep);
        self::assertSame(1, $row['draft']['materialLineCount']);
        self::assertContains($targetId, array_column($row['mission']['interventions'], 'id'));

        [$status, $json] = $this->call('POST', "/api/intervention-type-requests/{$reqKeep}/ignore", $this->managerToken, ['reason' => 'DUPLICATE', 'comment' => 'doublon']);
        self::assertSame(422, $status);
        self::assertSame('MISSING_IGNORE_STRATEGY', $this->errorCode($json));

        [$status, $json] = $this->call('POST', "/api/intervention-type-requests/{$reqKeep}/ignore", $this->managerToken, ['reason' => 'DUPLICATE', 'comment' => 'doublon', 'strategy' => 'KEEP_AS_HISTORY']);
        self::assertSame(200, $status, json_encode($json));
        self::assertSame($draftKeep, $this->scalar('SELECT intervention_draft_id FROM material_line WHERE intervention_draft_id = ?', [$draftKeep]));

        [$status, $json] = $this->call('POST', "/api/intervention-type-requests/{$reqMove}/ignore", $this->managerToken,
            ['reason' => 'DUPLICATE', 'comment' => 'même intervention', 'strategy' => 'REASSIGN', 'missionInterventionId' => $targetId]);
        self::assertSame(200, $status, json_encode($json));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM material_line WHERE mission_intervention_id = ? AND quantity = 3', [$targetId]));
    }

    /** 24. Aucune résolution ne déclenche de calcul financier ni de facturation. */
    public function test_24_no_automatic_financial_calculation(): void
    {
        $m = $this->mission();
        $firm = $this->firm();
        $type = $this->type();
        $this->offering($firm->getId(), $type->getId());
        $interventionId = $this->addIntervention($m->getId(), $type->getId(), $firm->getId());
        $matReq = $this->materialRequestOk($m->getId(), ['firmId' => $firm->getId(), 'quantity' => '1', 'missionInterventionId' => $interventionId]);
        [$req] = $this->interventionRequestOk($m->getId(), ['requestedFirmId' => $firm->getId()]);

        $this->call('POST', "/api/material-item-requests/{$matReq}/resolve", $this->managerToken, ['newItem' => ['label' => self::PREFIX . 'Calc', 'referenceCode' => 'C-1', 'firmId' => $firm->getId()]]);
        [$status] = $this->call('POST', "/api/intervention-type-requests/{$req}/resolve", $this->managerToken, ['interventionTypeId' => $type->getId(), 'firmId' => $firm->getId()]);
        self::assertSame(200, $status);

        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM financial_calculation WHERE mission_id = ?', [$m->getId()]));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM firm_invoice_line WHERE mission_id = ?', [$m->getId()]));
        self::assertNull($this->conn()->fetchOne('SELECT invoice_generated_at FROM mission WHERE id = ?', [$m->getId()]));
    }

    /**
     * 25. Non-régression D-141 / D-142 / D-143 : le matériel créé par une résolution
     * apparaît dans « Matériel à régulariser » (D-142, décision de facturation explicite
     * attendue), les heures réelles restent exigées à la soumission (D-143). D-141 et D-144
     * sont couverts par leurs propres suites, rejouées intégralement avec ce lot.
     */
    public function test_25_non_regression_d142_d143(): void
    {
        $m = $this->mission(MissionStatus::ENCODING_IN_PROGRESS);
        $firm = $this->firm();
        $interventionId = $this->addIntervention($m->getId(), $this->type()->getId(), $firm->getId());
        $matReq = $this->materialRequestOk($m->getId(), ['firmId' => $firm->getId(), 'quantity' => '1', 'missionInterventionId' => $interventionId]);
        [$status, $json] = $this->call('POST', "/api/material-item-requests/{$matReq}/resolve", $this->managerToken,
            ['newItem' => ['label' => self::PREFIX . 'A régulariser', 'referenceCode' => 'REG-1', 'firmId' => $firm->getId()]]);
        self::assertSame(200, $status, json_encode($json));
        $itemId = $this->scalar('SELECT item_id FROM material_line WHERE id = ?', [$json['materialLine']['id']]);

        [$status, $json] = $this->call('GET', '/api/material-items/billing-regularisation', $this->managerToken);
        self::assertSame(200, $status, json_encode($json));
        self::assertStringContainsString('"id":' . $itemId . ',', json_encode($json));

        [$status, $json] = $this->call('POST', "/api/missions/{$m->getId()}/encoding/complete", $this->instrToken, ['comment' => 'x']);
        self::assertSame(422, $status);
        self::assertSame('ENCODING_INCOMPLETE', $this->errorCode($json));
        self::assertContains('hours', array_column($json['error']['missing'], 'section'));
    }

    // ── Droits ────────────────────────────────────────────────────────────────

    public function test_rights_instrumentist_and_anonymous_cannot_manage_requests(): void
    {
        $m = $this->mission();
        $firm = $this->firm();
        $type = $this->type();
        $interventionId = $this->addIntervention($m->getId(), $type->getId(), $firm->getId());
        $matReq = $this->materialRequestOk($m->getId(), ['firmId' => $firm->getId(), 'quantity' => '1', 'missionInterventionId' => $interventionId]);
        [$req] = $this->interventionRequestOk($m->getId(), ['requestedFirmId' => $firm->getId()]);

        foreach ([[$this->instrToken, 403], [null, 401]] as [$token, $expected]) {
            self::assertSame($expected, $this->call('GET', "/api/material-item-requests/{$matReq}/duplicate-candidates", $token)[0]);
            self::assertSame($expected, $this->call('POST', "/api/material-item-requests/{$matReq}/resolve", $token, ['materialItemId' => 1])[0]);
            self::assertSame($expected, $this->call('POST', "/api/intervention-type-requests/{$req}/resolve", $token, ['interventionTypeId' => $type->getId(), 'firmId' => $firm->getId()])[0]);
            self::assertSame($expected, $this->call('POST', "/api/intervention-type-requests/{$req}/ignore", $token, ['reason' => 'DUPLICATE', 'comment' => 'x'])[0]);
        }

        // Un instrumentiste étranger à la mission ne peut pas y déposer de demande.
        $stranger = $this->createUser('ROLE_INSTRUMENTIST');
        $strangerToken = $this->login($stranger);
        $this->client->request('POST', "/api/missions/{$m->getId()}/material-item-requests",
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $strangerToken],
            content: json_encode(['label' => 'x', 'firmId' => $firm->getId(), 'quantity' => '1', 'missionInterventionId' => $interventionId]));
        self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404]);

        self::assertSame('PENDING', $this->conn()->fetchOne('SELECT status FROM material_item_request WHERE id = ?', [$matReq]));
        self::assertSame('PENDING', $this->conn()->fetchOne('SELECT status FROM intervention_type_request WHERE id = ?', [$req]));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM material_item_request WHERE mission_id = ?', [$m->getId()]));
    }
}
