<?php

namespace App\Tests\Functional;

use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\Hospital;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\SuggestedMaterial;
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
 * Lot 6 — l'encodage exploite enfin le référentiel : contexte riche par InterventionType
 * (prestations, firme principale suggérée, matériels suggérés) et signaux de cohérence
 * (informationnels, jamais bloquants) sur chaque MissionIntervention déjà encodée.
 */
final class InterventionEncodingIntelligenceTest extends WebTestCase
{
    private const PASSWORD = 'Lot6Test15!';
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private array $createdIds = [
        'missions' => [], 'users' => [], 'sites' => [], 'firms' => [], 'types' => [],
        'offerings' => [], 'items' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdIds['missions'] as $id) {
                $m = $this->em->find(Mission::class, $id);
                if ($m !== null) {
                    foreach ($m->getMaterialLines() as $l) { $this->em->remove($l); }
                    foreach ($m->getInterventions() as $i) { $this->em->remove($i); }
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $id) {
                $e = $this->em->find(Mission::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['offerings'] as $id) {
                $e = $this->em->find(FirmServiceOffering::class, $id);
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
        $u->setEmail('lot6-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Lot6');
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
        $h->setName('Lot6Site-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeFirm(bool $active = true): Firm
    {
        $f = new Firm();
        $f->setName('Lot6Firm-' . bin2hex(random_bytes(3)));
        $f->setActive($active);
        $this->em->persist($f);
        $this->em->flush();
        $this->createdIds['firms'][] = $f->getId();
        return $f;
    }

    private function makeType(): InterventionType
    {
        $t = new InterventionType();
        $t->setCode('L6-' . bin2hex(random_bytes(4)));
        $t->setLabel('Type Lot6 ' . bin2hex(random_bytes(2)));
        $t->setActive(true);
        $this->em->persist($t);
        $this->em->flush();
        $this->createdIds['types'][] = $t->getId();
        return $t;
    }

    private function makeMaterialItem(Firm $firm): MaterialItem
    {
        $mi = new MaterialItem();
        $mi->setFirm($firm);
        $mi->setReferenceCode('REF-' . bin2hex(random_bytes(3)));
        $mi->setLabel('Item ' . bin2hex(random_bytes(2)));
        $mi->setUnit('pièce');
        $mi->setActive(true);
        $this->em->persist($mi);
        $this->em->flush();
        $this->createdIds['items'][] = $mi->getId();
        return $mi;
    }

    /** @param MaterialItem[] $suggested */
    private function makeOffering(Firm $firm, InterventionType $type, array $suggested = []): FirmServiceOffering
    {
        $o = new FirmServiceOffering();
        $o->setFirm($firm);
        $o->setInterventionType($type);
        $o->setActive(true);
        $this->em->persist($o);
        $this->em->flush();
        $this->createdIds['offerings'][] = $o->getId();

        foreach ($suggested as $order => $item) {
            $sm = new SuggestedMaterial();
            $sm->setFirmServiceOffering($o);
            $sm->setFirm($firm);
            $sm->setMaterialItem($item);
            $sm->setDisplayOrder($order);
            $this->em->persist($sm);
        }
        $this->em->flush();

        return $o;
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

    // ── Contexte riche par InterventionType ─────────────────────────────

    public function test_encoding_context_with_single_offering_suggests_the_primary_firm(): void
    {
        [$client, , $token] = $this->bootMissionScenario();
        $type = $this->makeType();
        $firm = $this->makeFirm();
        $item = $this->makeMaterialItem($firm);
        $this->makeOffering($firm, $type, [$item]);

        $response = $this->request($client, 'GET', "/api/intervention-types/{$type->getId()}/encoding-context", $token);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);

        self::assertSame($type->getId(), $body['interventionType']['id']);
        self::assertSame($firm->getId(), $body['suggestedPrimaryFirm']['id'], 'une seule prestation active => firme non ambiguë');
        self::assertCount(1, $body['offerings']);
        self::assertCount(1, $body['offerings'][0]['suggestedMaterials']);
        self::assertSame($item->getId(), $body['offerings'][0]['suggestedMaterials'][0]['id']);
    }

    public function test_encoding_context_with_multiple_offerings_does_not_guess_a_firm(): void
    {
        [$client, , $token] = $this->bootMissionScenario();
        $type = $this->makeType();
        $firmA = $this->makeFirm();
        $firmB = $this->makeFirm();
        $this->makeOffering($firmA, $type);
        $this->makeOffering($firmB, $type);

        $response = $this->request($client, 'GET', "/api/intervention-types/{$type->getId()}/encoding-context", $token);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertNull($body['suggestedPrimaryFirm'], 'ambiguïté entre 2 prestations => aucune firme devinée');
        self::assertCount(2, $body['offerings']);
    }

    public function test_encoding_context_with_no_offering_returns_empty_shape(): void
    {
        [$client, , $token] = $this->bootMissionScenario();
        $type = $this->makeType();

        $response = $this->request($client, 'GET', "/api/intervention-types/{$type->getId()}/encoding-context", $token);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertNull($body['suggestedPrimaryFirm']);
        self::assertSame([], $body['offerings']);
    }

    public function test_encoding_context_is_accessible_by_instrumentist_not_just_manager(): void
    {
        // bootMissionScenario() already logs in as an instrumentist — a 200 here IS the assertion.
        [$client, , $token] = $this->bootMissionScenario();
        $type = $this->makeType();

        $response = $this->request($client, 'GET', "/api/intervention-types/{$type->getId()}/encoding-context", $token);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    // ── Matériels suggérés + cohérence sur une intervention encodée ─────

    public function test_suggested_materials_and_coherence_when_nothing_added_yet(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $type = $this->makeType();
        $firm = $this->makeFirm();
        $item1 = $this->makeMaterialItem($firm);
        $item2 = $this->makeMaterialItem($firm);
        $this->makeOffering($firm, $type, [$item1, $item2]);

        $created = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(), 'primaryFirmId' => $firm->getId(), 'orderIndex' => 0,
        ]);
        self::assertSame(Response::HTTP_CREATED, $created->getStatusCode());

        $encoding = $this->request($client, 'GET', "/api/missions/{$mission->getId()}/encoding", $token);
        $body = json_decode($encoding->getContent(), true);
        $itv = $body['interventions'][0];

        self::assertCount(2, $itv['suggestedMaterials']);
        self::assertTrue($itv['coherence']['hasNoMaterialLines']);
        self::assertEqualsCanonicalizing([$item1->getId(), $item2->getId()], $itv['coherence']['unusedSuggestedMaterialItemIds']);
        self::assertSame([], $itv['coherence']['unexpectedMaterialItemIds']);
        self::assertSame([], $itv['coherence']['materialLineIdsFromOtherFirm']);
    }

    public function test_coherence_after_adding_a_suggested_item_an_unexpected_item_and_an_other_firm_item(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $type = $this->makeType();
        $firm = $this->makeFirm();
        $otherFirm = $this->makeFirm();
        $suggested = $this->makeMaterialItem($firm);
        $notSuggested = $this->makeMaterialItem($firm);
        $fromOtherFirm = $this->makeMaterialItem($otherFirm);
        $this->makeOffering($firm, $type, [$suggested]);

        $created = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(), 'primaryFirmId' => $firm->getId(), 'orderIndex' => 0,
        ]);
        $interventionId = json_decode($created->getContent(), true)['id'];

        // Ajoute le matériel suggéré, un matériel non suggéré (même firme), et un matériel d'une autre firme.
        foreach ([$suggested, $notSuggested, $fromOtherFirm] as $item) {
            $r = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/material-lines", $token, [
                'missionInterventionId' => $interventionId, 'itemId' => $item->getId(), 'quantity' => '1',
            ]);
            self::assertSame(Response::HTTP_CREATED, $r->getStatusCode(), $r->getContent());
        }

        $encoding = $this->request($client, 'GET', "/api/missions/{$mission->getId()}/encoding", $token);
        $itv = json_decode($encoding->getContent(), true)['interventions'][0];

        self::assertFalse($itv['coherence']['hasNoMaterialLines']);
        self::assertSame([], $itv['coherence']['unusedSuggestedMaterialItemIds'], 'le matériel suggéré a été ajouté');
        // "unexpected" = pas dans la liste suggérée, quelle que soit la firme — le matériel
        // d'une autre firme n'est pas suggéré non plus, il est donc doublement inattendu.
        self::assertEqualsCanonicalizing([$notSuggested->getId(), $fromOtherFirm->getId()], $itv['coherence']['unexpectedMaterialItemIds']);
        self::assertCount(1, $itv['coherence']['materialLineIdsFromOtherFirm']);
    }

    public function test_no_primary_firm_means_no_suggestions_but_coherence_still_computed(): void
    {
        [$client, $mission, $token] = $this->bootMissionScenario();
        $type = $this->makeType();

        $created = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/interventions", $token, [
            'interventionTypeId' => $type->getId(), 'orderIndex' => 0,
        ]);
        self::assertSame(Response::HTTP_CREATED, $created->getStatusCode());

        $encoding = $this->request($client, 'GET', "/api/missions/{$mission->getId()}/encoding", $token);
        $itv = json_decode($encoding->getContent(), true)['interventions'][0];

        self::assertSame([], $itv['suggestedMaterials']);
        self::assertTrue($itv['coherence']['hasNoMaterialLines']);
        self::assertSame([], $itv['coherence']['unusedSuggestedMaterialItemIds']);
    }

    // ── Compatibilité historique ─────────────────────────────────────────

    public function test_legacy_intervention_without_type_gets_empty_suggestions_and_valid_coherence(): void
    {
        $this->boot();
        $legacy = $this->em->getRepository(MissionIntervention::class)->findOneBy(['code' => 'LCA']);

        if ($legacy === null) {
            self::markTestSkipped('Ligne historique mission #529 absente de cet environnement de test.');
        }

        $manager = $this->createUser('ROLE_MANAGER');
        $client = static::createClient();
        $token = $this->login($client, $manager);

        $missionId = $legacy->getMission()->getId();
        $response = $this->request($client, 'GET', "/api/missions/{$missionId}/encoding", $token);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $itv = current(array_filter($body['interventions'], fn ($i) => $i['id'] === $legacy->getId()));
        self::assertNotFalse($itv);
        self::assertNull($itv['interventionType']);
        self::assertSame([], $itv['suggestedMaterials']);
        self::assertIsBool($itv['coherence']['hasNoMaterialLines']);
    }

    // ── Recherche matériel consolidée ───────────────────────────────────

    public function test_quick_search_with_firm_id_scopes_results_to_that_firm(): void
    {
        [$client, , $token] = $this->bootMissionScenario();
        $firmA = $this->makeFirm();
        $firmB = $this->makeFirm();
        $itemA = $this->makeMaterialItem($firmA);
        $itemA->setLabel('SharedNeedle Alpha');
        $itemB = $this->makeMaterialItem($firmB);
        $itemB->setLabel('SharedNeedle Beta');
        $this->em->flush();

        $response = $this->request($client, 'GET', '/api/material-items/quick-search?q=SharedNeedle&firmId=' . $firmA->getId(), $token);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $items = json_decode($response->getContent(), true)['items'];
        self::assertCount(1, $items);
        self::assertSame($itemA->getId(), $items[0]['id']);
    }
}
