<?php

namespace App\Tests\Functional;

use App\Dto\MissionFinancialEvaluation;
use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\Hospital;
use App\Entity\InterventionType;
use App\Entity\InterventionTypeRequest;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionDraft;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use App\Service\FinancialCalculationService;
use App\Service\InterventionRequirementsService;
use App\Service\MissionInterventionDraftService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Version de repli D-178 (base v2026.10.31-prod-2) : l'application d'avant D-178, qui comprend
 * les données « Aucune firme » déjà enregistrées par D-178 sans jamais en créer de nouvelles.
 * Une intervention marquée reste complète, évaluée WITHOUT_FIRM (jamais MISSING_PRIMARY_FIRM),
 * même si l'autorisation du type est retirée ; une demande « Aucune firme » est conservée et se
 * résout sans firme avec les mêmes contrôles ; rien n'est supprimé ni transformé.
 */
final class InterventionWithoutFirmFallbackTest extends WebTestCase
{
    private const PASSWORD = 'SansFirme178!';
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private array $createdIds = ['missions' => [], 'users' => [], 'sites' => [], 'firms' => [], 'types' => [], 'offerings' => [], 'items' => []];
    /** @var list<string> codes de types créés via l'API (résolution d'une demande) */
    private array $createdTypeCodes = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->clear();
            $conn = $this->em->getConnection();
            foreach ($this->createdIds['missions'] as $id) {
                $conn->executeStatement('DELETE FROM audit_event WHERE mission_id = ?', [$id]);
                $conn->executeStatement('DELETE FROM material_line WHERE mission_id = ?', [$id]);
                $conn->executeStatement('DELETE FROM mission_intervention_draft WHERE mission_id = ?', [$id]);
                $conn->executeStatement('DELETE FROM intervention_type_request WHERE mission_id = ?', [$id]);
                $conn->executeStatement('DELETE FROM mission_intervention WHERE mission_id = ?', [$id]);
            }
            foreach ($this->createdIds['missions'] as $id) { $e = $this->em->find(Mission::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->createdIds['offerings'] as $id) { $e = $this->em->find(FirmServiceOffering::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->createdIds['items'] as $id) { $e = $this->em->find(MaterialItem::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->createdTypeCodes as $code) {
                $t = $this->em->getRepository(InterventionType::class)->findOneBy(['code' => $code]);
                if ($t) $this->em->remove($t);
            }
            foreach ($this->createdIds['types'] as $id) { $e = $this->em->find(InterventionType::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->createdIds['firms'] as $id) { $e = $this->em->find(Firm::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
            foreach ($this->createdIds['users'] as $id) { $e = $this->em->find(User::class, $id); if ($e) $this->em->remove($e); }
            foreach ($this->createdIds['sites'] as $id) { $e = $this->em->find(Hospital::class, $id); if ($e) $this->em->remove($e); }
            $this->em->flush();
        }
        parent::tearDown();
    }

    // ── Interventions « Aucune firme » déjà enregistrées ────────────────

    public function test_recorded_without_firm_intervention_stays_complete_and_billed_without_firm_even_after_withdrawal(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $mission = $this->mission($surgeon, $instr, $site);
        $type = $this->type('QUAD', allowedWithoutFirm: true);
        $materialFirm = $this->firm('Arthrex');
        $item = $this->item($materialFirm);
        $recorded = $this->intervention($mission, $type, null, withoutFirm: true);
        $line = $this->materialLine($mission, $recorded->getId(), $item, $instr);

        foreach ([true, false] as $authorised) {
            $this->em->clear();
            $t = $this->em->find(InterventionType::class, $type->getId());
            $t->setAllowedWithoutFirm($authorised);
            $this->em->flush();
            $this->em->clear();

            $i = $this->em->find(MissionIntervention::class, $recorded->getId());
            self::assertTrue($i->isWithoutFirm(), 'marqueur compris, jamais transformé');
            self::assertSame([], $this->requirements()->missingFor($i), 'complète, autorisation ' . ($authorised ? 'active' : 'retirée'));
            $evaluation = $this->financial()->evaluate($this->em->find(Mission::class, $mission->getId()));
            self::assertNotContains('MISSING_PRIMARY_FIRM', array_map(static fn ($a) => $a->code, $evaluation->anomalies));
            self::assertSame(MissionFinancialEvaluation::WITHOUT_FIRM, $evaluation->outcome('MISSION_INTERVENTION:' . $recorded->getId())['kind']);
            // Le matériel garde SA firme et ses règles (ici sans tarif : anomalie tarif matériel, jamais « firme manquante »).
            self::assertNotContains('MISSING_PRIMARY_FIRM', $evaluation->outcome('MATERIAL_LINE:' . $line->getId())['anomalyCodes']);

            $encoding = $this->getJson($client, "/api/missions/{$mission->getId()}/encoding", $this->login($client, $instr));
            $entry = array_values(array_filter($encoding['entries'], static fn (array $e) => $e['kind'] === 'INTERVENTION' && $e['id'] === $recorded->getId()))[0];
            self::assertTrue($entry['withoutFirm']);
            self::assertNull($entry['firm']);
            self::assertFalse($encoding['coherenceSummary']['hasMissingPrimaryFirm']);
        }
    }

    public function test_old_correction_window_keeps_without_firm_and_only_an_explicit_firm_lifts_it(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $mission = $this->mission($surgeon, $instr, $site);
        $type = $this->type('QUAD', allowedWithoutFirm: false); // autorisation retirée depuis l'encodage
        $notAllowed = $this->type('LCA');
        $firm = $this->firm('Arthrex');
        $recorded = $this->intervention($mission, $type, null, withoutFirm: true);
        $token = $this->login($client, $instr);
        $uri = "/api/missions/{$mission->getId()}/interventions/{$recorded->getId()}";

        // L'ancienne fenêtre renvoie toujours primaryFirmId (null) : firme inchangée, marqueur conservé.
        $this->request($client, 'PATCH', $uri, $token, ['interventionTypeId' => $type->getId(), 'primaryFirmId' => null, 'orderIndex' => 2]);
        self::assertSame(204, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->em->clear();
        $i = $this->em->find(MissionIntervention::class, $recorded->getId());
        self::assertTrue($i->isWithoutFirm());
        self::assertNull($i->getPrimaryFirm());

        // Nouveau type non autorisé sans firme : refusé, rien n'est modifié.
        $this->request($client, 'PATCH', $uri, $token, ['interventionTypeId' => $notAllowed->getId(), 'primaryFirmId' => null]);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $this->em->clear();
        self::assertSame($type->getId(), $this->em->find(MissionIntervention::class, $recorded->getId())->getInterventionType()?->getId());

        // Une firme choisie explicitement lève le marqueur (réponse de l'utilisateur, pas une transformation).
        $this->request($client, 'PATCH', $uri, $token, ['primaryFirmId' => $firm->getId()]);
        self::assertSame(204, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->em->clear();
        $i = $this->em->find(MissionIntervention::class, $recorded->getId());
        self::assertSame($firm->getId(), $i->getPrimaryFirm()?->getId());
        self::assertFalse($i->isWithoutFirm());
    }

    public function test_the_fallback_never_creates_new_without_firm_choices(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $manager = $this->user('ROLE_MANAGER');
        $mission = $this->mission($surgeon, $instr, $site);
        $type = $this->type('QUAD', allowedWithoutFirm: true);
        $other = $this->type('BICEPS');
        $firm = $this->firm('Arthrex');
        $withFirm = $this->intervention($mission, $type, $firm);
        $token = $this->login($client, $instr);

        $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, ['interventionTypeId' => $type->getId(), 'withoutFirm' => true]);
        self::assertSame(422, $client->getResponse()->getStatusCode(), 'création « Aucune firme » non proposée par la version de repli');
        $this->request($client, 'PATCH', "/api/missions/{$mission->getId()}/interventions/{$withFirm->getId()}", $token, ['primaryFirmId' => null, 'withoutFirm' => true]);
        self::assertSame(422, $client->getResponse()->getStatusCode(), 'retirer une firme reste refusé (D-146 bis)');
        $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $token, ['label' => 'Geste sans firme', 'withoutFirm' => true]);
        self::assertSame(422, $client->getResponse()->getStatusCode(), 'demande : firme toujours exigée');

        // Le paramétrage n'est ni modifiable ni perdu : lu tel quel.
        $managerToken = $this->login($client, $manager);
        $this->request($client, 'PATCH', "/api/intervention-types/{$other->getId()}", $managerToken, ['allowedWithoutFirm' => true]);
        $this->em->clear();
        self::assertFalse($this->em->find(InterventionType::class, $other->getId())->isAllowedWithoutFirm());
        $types = $this->getJson($client, '/api/intervention-types', $managerToken);
        $listed = array_values(array_filter($types['items'] ?? $types, static fn (array $t) => $t['id'] === $type->getId()));
        self::assertTrue($listed[0]['allowedWithoutFirm']);
    }

    // ── Demandes « Aucune firme » déjà introduites ──────────────────────

    public function test_open_request_without_firm_is_kept_and_resolved_without_firm_with_the_same_controls(): void
    {
        $client = $this->boot();
        [$surgeon, $instr, $site] = $this->people();
        $manager = $this->user('ROLE_MANAGER');
        $mission = $this->mission($surgeon, $instr, $site);
        $allowed = $this->type('QUAD', allowedWithoutFirm: true);
        $notAllowed = $this->type('LCA');
        $firm = $this->firm('Arthrex');
        $requestId = $this->requestWithoutFirm($mission, $instr, 'Suture tendon quadricipital');

        $managerToken = $this->login($client, $manager);
        $listed = array_values(array_filter($this->getJson($client, '/api/intervention-type-requests', $managerToken)['items'], static fn (array $r) => $r['id'] === $requestId));
        self::assertTrue($listed[0]['withoutFirm'], 'demande conservée avec son information « sans firme »');
        $encoding = $this->getJson($client, "/api/missions/{$mission->getId()}/encoding", $this->login($client, $instr));
        $draft = array_values(array_filter($encoding['entries'], static fn (array $e) => $e['kind'] === 'DRAFT'))[0];
        self::assertTrue($draft['withoutFirm']);

        $managerToken = $this->login($client, $manager);
        $resolveUri = "/api/intervention-type-requests/{$requestId}/resolve";
        $this->request($client, 'POST', $resolveUri, $managerToken, ['interventionTypeId' => $allowed->getId(), 'firmId' => $firm->getId()]);
        self::assertSame(422, $client->getResponse()->getStatusCode(), 'jamais transformée en demande avec firme');
        $this->request($client, 'POST', $resolveUri, $managerToken, ['interventionTypeId' => $notAllowed->getId()]);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $this->em->clear();
        self::assertSame(MissionInterventionDraft::STATUS_OPEN, $this->em->getRepository(MissionInterventionDraft::class)->findOneBy(['mission' => $mission->getId()])->getStatus());

        $this->request($client, 'POST', $resolveUri, $managerToken, ['interventionTypeId' => $allowed->getId()]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $resolved = json_decode((string) $client->getResponse()->getContent(), true);
        $this->em->clear();
        $intervention = $this->em->find(MissionIntervention::class, $resolved['missionInterventionId']);
        self::assertNull($intervention->getPrimaryFirm());
        self::assertTrue($intervention->isWithoutFirm());
        self::assertSame([], $this->requirements()->missingFor($intervention));

        // Autorisation retirée : une demande encore ouverte n'est plus résolue sans firme (ignorable).
        $secondId = $this->requestWithoutFirm($mission, $instr, 'Autre geste sans firme');
        $this->em->clear();
        $this->em->find(InterventionType::class, $allowed->getId())->setAllowedWithoutFirm(false);
        $this->em->flush();
        $this->request($client, 'POST', "/api/intervention-type-requests/{$secondId}/resolve", $this->login($client, $manager), ['interventionTypeId' => $allowed->getId()]);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $this->em->clear();
        self::assertTrue($this->em->find(InterventionTypeRequest::class, $secondId)->getDraft()->isWithoutFirm(), 'demande conservée telle quelle');
    }

    /** Demande « Aucune firme » telle que la version D-178 l'enregistre (même service). */
    private function requestWithoutFirm(Mission $mission, User $by, string $label): int
    {
        $req = (new InterventionTypeRequest())
            ->setMission($this->em->getReference(Mission::class, $mission->getId()))
            ->setLabel($label)
            ->setCreatedBy($this->em->getReference(User::class, $by->getId()));
        $this->em->persist($req);
        static::getContainer()->get(MissionInterventionDraftService::class)->createForRequest($req, null, $this->em->find(User::class, $by->getId()), null, true);
        return (int) $req->getId();
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function requirements(): InterventionRequirementsService
    {
        return static::getContainer()->get(InterventionRequirementsService::class);
    }

    private function financial(): FinancialCalculationService
    {
        return static::getContainer()->get(FinancialCalculationService::class);
    }

    /** @return array{0: User, 1: User, 2: Hospital} */
    private function people(): array
    {
        return [$this->user('ROLE_SURGEON'), $this->user('ROLE_INSTRUMENTIST'), $this->site()];
    }

    private function user(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('sansfirme178-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('SansFirme');
        $u->setLastname('Test');
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function site(): Hospital
    {
        $h = new Hospital();
        $h->setName('SansFirme178Site-' . bin2hex(random_bytes(3)));
        $this->em->persist($h); $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function firm(string $name): Firm
    {
        $f = new Firm();
        $f->setName($name . '-' . bin2hex(random_bytes(3)));
        $this->em->persist($f); $this->em->flush();
        $this->createdIds['firms'][] = $f->getId();
        return $f;
    }

    private function type(string $code, bool $allowedWithoutFirm = false): InterventionType
    {
        $t = new InterventionType();
        $t->setCode($code . '-' . bin2hex(random_bytes(4)));
        $t->setLabel('Type ' . $code);
        $t->setAllowedWithoutFirm($allowedWithoutFirm);
        $this->em->persist($t); $this->em->flush();
        $this->createdIds['types'][] = $t->getId();
        return $t;
    }

    private function item(Firm $firm): MaterialItem
    {
        $item = new MaterialItem();
        $item->setFirm($this->em->getReference(Firm::class, $firm->getId()));
        $item->setLabel('Ancre ' . bin2hex(random_bytes(2)));
        $item->setUnit('pièce');
        $item->setReferenceCode(bin2hex(random_bytes(4)));
        $this->em->persist($item); $this->em->flush();
        $this->createdIds['items'][] = $item->getId();
        return $item;
    }

    private function mission(User $surgeon, User $instr, Hospital $site): Mission
    {
        $m = new Mission();
        $m->setStatus(MissionStatus::ASSIGNED);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setSurgeon($this->em->getReference(User::class, $surgeon->getId()));
        $m->setInstrumentist($this->em->getReference(User::class, $instr->getId()));
        $m->setSite($this->em->getReference(Hospital::class, $site->getId()));
        $m->setCreatedBy($this->em->getReference(User::class, $surgeon->getId()));
        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
        $m->setStartAt($now->modify('-1 hour'));
        $m->setEndAt($now->modify('+2 hours'));
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    private function intervention(Mission $mission, InterventionType $type, ?Firm $firm, bool $withoutFirm = false): MissionIntervention
    {
        $i = new MissionIntervention();
        $i->setMission($this->em->getReference(Mission::class, $mission->getId()))
            ->setInterventionType($this->em->getReference(InterventionType::class, $type->getId()))
            ->setPrimaryFirm($firm !== null ? $this->em->getReference(Firm::class, $firm->getId()) : null)
            ->setWithoutFirm($withoutFirm)
            ->setCode((string) $type->getCode())
            ->setLabel((string) $type->getLabel())
            ->setOrderIndex(0);
        $this->em->persist($i);
        $this->em->flush();
        return $i;
    }

    private function materialLine(Mission $mission, int $interventionId, MaterialItem $item, User $by): MaterialLine
    {
        $l = new MaterialLine();
        $l->setCreatedBy($this->em->getReference(User::class, $by->getId()));
        $l->setMission($this->em->getReference(Mission::class, $mission->getId()));
        $l->setMissionIntervention($this->em->getReference(MissionIntervention::class, $interventionId));
        $l->setItem($this->em->getReference(MaterialItem::class, $item->getId()));
        $l->setQuantity('1');
        $this->em->persist($l);
        $this->em->flush();
        return $l;
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

    private function getJson(KernelBrowser $client, string $uri, string $token): array
    {
        $response = $this->request($client, 'GET', $uri, $token);
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        return json_decode((string) $response->getContent(), true);
    }
}
