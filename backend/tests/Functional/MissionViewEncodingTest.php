<?php

namespace App\Tests\Functional;

use App\Entity\Firm;
use App\Entity\Hospital;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
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
 * Lot 6 (D-100) — VIEW_ENCODING (lecture) vs EDIT_ENCODING (écriture, inchangé).
 * GET /api/missions/{id}/encoding est désormais gated par VIEW_ENCODING : matrice
 * RBAC complète (chirurgien/instrumentiste/manager × statuts) + contrat DTO
 * (aucun champ financier/patient).
 */
final class MissionViewEncodingTest extends WebTestCase
{
    private const PASSWORD = 'Lot6Test15!';
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private array $createdIds = ['missions' => [], 'users' => [], 'sites' => [], 'firms' => [], 'types' => [], 'items' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->clear();
            foreach ($this->createdIds['missions'] as $id) {
                $m = $this->em->find(Mission::class, $id);
                if ($m !== null) {
                    foreach ($this->em->getRepository(MaterialLine::class)->findBy(['mission' => $id]) as $line) {
                        $this->em->remove($line);
                    }
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
        $u->setEmail('viewenc-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('ViewEnc');
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

    private function request(KernelBrowser $client, string $method, string $uri, ?string $token = null): Response
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $client->request($method, $uri, server: $server);
        return $client->getResponse();
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('ViewEncSite-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeFirm(): Firm
    {
        $f = new Firm();
        $f->setName('ViewEncFirm-' . bin2hex(random_bytes(3)));
        $f->setActive(true);
        $this->em->persist($f);
        $this->em->flush();
        $this->createdIds['firms'][] = $f->getId();
        return $f;
    }

    private function makeType(): InterventionType
    {
        $t = new InterventionType();
        $t->setCode('VE-' . bin2hex(random_bytes(4)));
        $t->setLabel('Type ViewEnc ' . bin2hex(random_bytes(2)));
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

    private function makeMission(User $surgeon, User $instr, Hospital $site, MissionStatus $status): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setSurgeon($surgeon);
        $m->setInstrumentist($instr);
        $m->setSite($site);
        $m->setCreatedBy($surgeon);
        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
        $m->setStartAt($now->modify('-2 hours'));
        $m->setEndAt($now->modify('+1 hour'));
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    private function addFullEncoding(Mission $mission, InterventionType $type, Firm $firm, MaterialItem $item): void
    {
        $intervention = new MissionIntervention();
        $intervention->setMission($mission);
        $intervention->setInterventionType($type);
        $intervention->setPrimaryFirm($firm);
        $intervention->setCode($type->getCode());
        $intervention->setLabel($type->getLabel());
        $intervention->setOrderIndex(0);
        $this->em->persist($intervention);
        $this->em->flush();

        $line = new MaterialLine();
        $line->setMission($mission);
        $line->setMissionIntervention($intervention);
        $line->setItem($item);
        $line->setQuantity('2.00');
        $line->setCreatedBy($mission->getInstrumentist());
        $this->em->persist($line);
        $this->em->flush();
    }

    // ── §23 Matrice RBAC — Chirurgien ───────────────────────────────────────

    /** @dataProvider surgeonEligibleStatusProvider */
    public function test_surgeon_view_encoding_by_status(MissionStatus $status, bool $expectViewAllowed): void
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, $status);
        $token = $this->login($client, $surgeon);

        $response = $this->request($client, 'GET', "/api/missions/{$mission->getId()}/encoding", $token);

        if ($expectViewAllowed) {
            self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        } else {
            self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode(), (string) $status->value);
        }
    }

    public static function surgeonEligibleStatusProvider(): array
    {
        return [
            'DRAFT refusé (pas d\'instrumentiste réellement assigné)' => [MissionStatus::DRAFT, false],
            'OPEN refusé' => [MissionStatus::OPEN, false],
            'DECLARED autorisé' => [MissionStatus::DECLARED, true],
            'ASSIGNED autorisé' => [MissionStatus::ASSIGNED, true],
            'IN_PROGRESS autorisé' => [MissionStatus::IN_PROGRESS, true],
            'ENCODING_IN_PROGRESS autorisé' => [MissionStatus::ENCODING_IN_PROGRESS, true],
            'SUBMITTED autorisé' => [MissionStatus::SUBMITTED, true],
            'VALIDATED autorisé (consultation rétrospective, cas principal du lot)' => [MissionStatus::VALIDATED, true],
            'CLOSED autorisé' => [MissionStatus::CLOSED, true],
            'REJECTED refusé' => [MissionStatus::REJECTED, false],
            'CANCELLED refusé' => [MissionStatus::CANCELLED, false],
        ];
    }

    public function test_surgeon_cannot_view_another_surgeons_mission_encoding(): void
    {
        $client = $this->boot();
        $ownerSurgeon = $this->createUser('ROLE_SURGEON');
        $otherSurgeon = $this->createUser('ROLE_SURGEON');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeMission($ownerSurgeon, $instr, $site, MissionStatus::VALIDATED);
        $token = $this->login($client, $otherSurgeon);

        $response = $this->request($client, 'GET', "/api/missions/{$mission->getId()}/encoding", $token);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    /** Manipuler l'ID d'une mission ne suffit jamais — l'ownership est toujours revérifiée. */
    public function test_surgeon_id_manipulation_via_url_is_impossible(): void
    {
        $client = $this->boot();
        $ownerSurgeon = $this->createUser('ROLE_SURGEON');
        $attacker = $this->createUser('ROLE_SURGEON');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeMission($ownerSurgeon, $instr, $site, MissionStatus::SUBMITTED);
        $token = $this->login($client, $attacker);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->request($client, 'GET', "/api/missions/{$mission->getId()}/encoding", $token);
            self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        }
    }

    public function test_surgeon_never_gets_edit_encoding_regardless_of_status(): void
    {
        $client = $this->boot();

        foreach ([MissionStatus::ASSIGNED, MissionStatus::SUBMITTED, MissionStatus::VALIDATED] as $status) {
            $surgeon = $this->createUser('ROLE_SURGEON');
            $instr = $this->createUser('ROLE_INSTRUMENTIST');
            $site = $this->makeSite();
            $mission = $this->makeMission($surgeon, $instr, $site, $status);
            $token = $this->login($client, $surgeon);

            $response = $this->request($client, 'GET', "/api/missions/{$mission->getId()}/encoding", $token);
            if ($response->getStatusCode() === Response::HTTP_OK) {
                $body = json_decode($response->getContent(), true);
                self::assertNotContains('edit_encoding', $body['mission']['allowedActions'] ?? [], (string) $status->value);
            }
        }
    }

    // ── §23 Matrice RBAC — Instrumentiste (régression, comportement inchangé) ──

    public function test_assigned_instrumentist_can_still_view_and_edit(): void
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED);
        $token = $this->login($client, $instr);

        $response = $this->request($client, 'GET', "/api/missions/{$mission->getId()}/encoding", $token);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertContains('edit_encoding', $body['mission']['allowedActions']);
    }

    public function test_unassigned_instrumentist_cannot_view(): void
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $otherInstr = $this->createUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED);
        $token = $this->login($client, $otherInstr);

        $response = $this->request($client, 'GET', "/api/missions/{$mission->getId()}/encoding", $token);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    // ── §23 Matrice RBAC — Manager/Admin ────────────────────────────────────

    /**
     * Avant ce lot : un manager ne pouvait PAS consulter l'encodage d'une mission
     * VALIDATED/CLOSED via cet endpoint (MissionEncodingGuard::assertEncodingAllowed()
     * bloquait inconditionnellement, contournement documenté côté frontend manager —
     * voir docblock de MissionController::getEncoding()). Ce lot corrige ce couplage :
     * effet de bord bénéfique attendu, pas une régression.
     */
    public function test_manager_can_view_validated_mission_encoding(): void
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $manager = $this->createUser('ROLE_MANAGER');
        $site = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::VALIDATED);
        $token = $this->login($client, $manager);

        $response = $this->request($client, 'GET', "/api/missions/{$mission->getId()}/encoding", $token);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
    }

    public function test_admin_can_view_any_mission_encoding(): void
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $admin = $this->createUser('ROLE_ADMIN');
        $site = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::SUBMITTED);
        $token = $this->login($client, $admin);

        $response = $this->request($client, 'GET', "/api/missions/{$mission->getId()}/encoding", $token);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    // ── §24 Contrat DTO — aucun champ financier/patient ─────────────────────

    public function test_encoding_dto_never_contains_financial_or_patient_fields(): void
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::VALIDATED);
        $type = $this->makeType();
        $firm = $this->makeFirm();
        $item = $this->makeItem($firm);
        $this->addFullEncoding($mission, $type, $firm, $item);

        $token = $this->login($client, $surgeon);
        $response = $this->request($client, 'GET', "/api/missions/{$mission->getId()}/encoding", $token);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);

        // Scan des CLÉS JSON (noms de champ, statiquement définis par les DTO — jamais
        // aléatoires), pas une recherche de sous-chaîne sur le corps brut : les fixtures
        // de ce test utilisent bin2hex(random_bytes(...)) pour garantir l'unicité
        // (referenceCode, suffixes firm/type/site) — une séquence hex peut par pur hasard
        // épeler un mot interdit ("fee", "bad", "cafe"...), ce qui rendrait une recherche
        // de sous-chaîne intrinsèquement instable (faux positif aléatoire selon le tirage
        // de random_bytes()) sans jamais refléter une vraie fuite de champ. Les CLÉS, en
        // revanche, ne varient jamais d'une exécution à l'autre.
        $keys = self::collectKeys($body);
        // "rate" seul est volontairement exclu de cette liste : sous-chaîne légitime de
        // l'action existante "rate_instrumentist" (noter l'instrumentiste, sans rapport
        // financier) déjà présente dans allowedActions — hourlyRate/consultationFee
        // couvrent déjà le risque financier réel autour de "rate".
        foreach (['amount', 'price', 'fee', 'pricingRule', 'invoice', 'billing', 'patient', 'salary', 'hourlyRate', 'consultationFee'] as $forbidden) {
            foreach ($keys as $key) {
                self::assertStringNotContainsStringIgnoringCase(
                    $forbidden,
                    $key,
                    "champ interdit détecté: {$forbidden} (clé JSON: {$key})",
                );
            }
        }

        // Contrat positif : les données réellement autorisées sont bien présentes.
        self::assertNotEmpty($body['interventions']);
        self::assertSame($type->getLabel(), $body['interventions'][0]['label']);
        self::assertNotEmpty($body['interventions'][0]['materialLines']);
        self::assertSame('2.00', $body['interventions'][0]['materialLines'][0]['quantity']);
    }

    /**
     * @return list<string> toutes les clés JSON, récursivement, à travers objets et
     *         tableaux — jamais les valeurs (qui peuvent contenir des données aléatoires
     *         de fixture, voir commentaire d'appel ci-dessus).
     */
    private static function collectKeys(mixed $node): array
    {
        if (!is_array($node)) {
            return [];
        }

        $keys = [];
        foreach ($node as $k => $v) {
            if (is_string($k)) {
                $keys[] = $k;
            }
            $keys = array_merge($keys, self::collectKeys($v));
        }

        return $keys;
    }
}
