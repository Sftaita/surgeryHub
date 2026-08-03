<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\FirmServiceOffering;
use App\Entity\Firm;
use App\Entity\InterventionType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot 1 — /api/intervention-types : référentiel médical fermé, MANAGER/ADMIN uniquement
 * pour la mutation, code unique et immuable, suppression bloquée si utilisé.
 */
final class InterventionTypeControllerTest extends WebTestCase
{
    private const PASSWORD = 'InterventionType15!';

    private EntityManagerInterface $em;
    private array $createdTypeIds = [];
    private array $createdUserIds = [];
    private array $createdFirmIds = [];
    private array $createdOfferingIds = [];
    private array $createdRuleIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdRuleIds as $id) {
                $e = $this->em->find(\App\Entity\PricingRule::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdOfferingIds as $id) {
                $e = $this->em->find(FirmServiceOffering::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdFirmIds as $id) {
                $e = $this->em->find(Firm::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            // Task 11 — un type fusionné pointe vers un autre type créé dans le même test
            // (self-FK) : dénouer avant suppression pour ne jamais dépendre de l'ordre de
            // suppression choisi par Doctrine.
            foreach ($this->createdTypeIds as $id) {
                $e = $this->em->find(InterventionType::class, $id);
                if ($e !== null && $e->getMergedInto() !== null) { $e->setMergedInto(null); }
            }
            $this->em->flush();
            foreach ($this->createdTypeIds as $id) {
                $e = $this->em->find(InterventionType::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            // Task 11 — InterventionTypeMergeService::merge() écrit un AuditEvent(actor=...)
            // sans Mission associée (recordGlobal) : à nettoyer avant de supprimer l'acteur.
            foreach ($this->createdUserIds as $id) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['actor' => $id]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            $this->em->flush();
            foreach ($this->createdUserIds as $id) {
                $e = $this->em->find(User::class, $id);
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
        $u->setEmail('itype-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->createdUserIds[] = $u->getId();
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

    private function request(KernelBrowser $client, string $method, string $uri, ?string $token = null, array $body = []): Response
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $client->request($method, $uri, server: $server, content: $method === 'GET' ? null : json_encode($body));
        return $client->getResponse();
    }

    private function makeType(string $code): InterventionType
    {
        $t = new InterventionType();
        $t->setCode($code);
        $t->setLabel($code);
        $this->em->persist($t);
        $this->em->flush();
        $this->createdTypeIds[] = $t->getId();
        return $t;
    }

    public function test_manager_can_create_intervention_type(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);

        $code = 'LCA-' . bin2hex(random_bytes(3));
        $response = $this->request($client, 'POST', '/api/intervention-types', $token, [
            'code' => $code, 'label' => 'LCA primaire',
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->createdTypeIds[] = $body['id'];
        self::assertSame(strtoupper($code), $body['code']);
        self::assertTrue($body['active']);
    }

    public function test_admin_can_create_intervention_type(): void
    {
        $client = $this->boot();
        $admin = $this->createUser('ROLE_ADMIN');
        $token = $this->login($client, $admin);

        $response = $this->request($client, 'POST', '/api/intervention-types', $token, [
            'code' => 'PTG-' . bin2hex(random_bytes(3)), 'label' => 'PTG',
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $this->createdTypeIds[] = json_decode($response->getContent(), true)['id'];
    }

    public function test_instrumentist_cannot_create_intervention_type(): void
    {
        $client = $this->boot();
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $instr);

        $response = $this->request($client, 'POST', '/api/intervention-types', $token, [
            'code' => 'MPFL-' . bin2hex(random_bytes(3)), 'label' => 'MPFL',
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_duplicate_code_is_rejected(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $code = 'PTE-' . bin2hex(random_bytes(3));
        $this->makeType($code);

        $response = $this->request($client, 'POST', '/api/intervention-types', $token, [
            'code' => strtolower($code), 'label' => 'Doublon',
        ]);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    public function test_code_is_immutable_no_field_accepted_on_update(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $type = $this->makeType('PTG-REV-' . bin2hex(random_bytes(3)));
        $originalCode = $type->getCode();

        // Le endpoint update ne lit jamais 'code' dans le body — même en l'envoyant,
        // il doit être ignoré silencieusement (pas de setter appelé dessus).
        $response = $this->request($client, 'PATCH', "/api/intervention-types/{$type->getId()}", $token, [
            'code' => 'AUTRE-CODE', 'label' => 'Nouveau libellé',
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertSame($originalCode, $body['code'], 'le code ne doit jamais changer via PATCH');
        self::assertSame('Nouveau libellé', $body['label']);
    }

    public function test_deactivation_succeeds(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $type = $this->makeType('ARTHRO-' . bin2hex(random_bytes(3)));

        $response = $this->request($client, 'PATCH', "/api/intervention-types/{$type->getId()}", $token, ['active' => false]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertFalse(json_decode($response->getContent(), true)['active']);
    }

    public function test_cannot_delete_a_type_used_by_a_service_offering(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $type = $this->makeType('PTE-INV-' . bin2hex(random_bytes(3)));

        $firm = new Firm();
        $firm->setName('Firm-' . bin2hex(random_bytes(3)));
        $this->em->persist($firm);
        $this->em->flush();
        $this->createdFirmIds[] = $firm->getId();

        $offering = new FirmServiceOffering();
        $offering->setFirm($firm);
        $offering->setInterventionType($type);
        $this->em->persist($offering);
        $this->em->flush();
        $this->createdOfferingIds[] = $offering->getId();

        $response = $this->request($client, 'DELETE', "/api/intervention-types/{$type->getId()}", $token);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode(), $response->getContent());
        self::assertNotNull($this->em->find(InterventionType::class, $type->getId()), 'le type ne doit pas avoir été supprimé');
    }

    public function test_can_delete_an_unused_type(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $type = $this->makeType('TEMP-' . bin2hex(random_bytes(3)));
        $typeId = $type->getId();

        $response = $this->request($client, 'DELETE', "/api/intervention-types/{$typeId}", $token);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $this->createdTypeIds = array_diff($this->createdTypeIds, [$typeId]);
        self::assertNull($this->em->find(InterventionType::class, $typeId));
    }

    // ── Task 11 — /similar, /duplicate-audit, /merge ─────────────────────

    public function test_similar_suggests_a_high_confidence_candidate_without_blocking_creation(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $this->makeType('PTG-' . bin2hex(random_bytes(3)))->setLabel('Prothèse totale de genou');
        $this->em->flush();

        $response = $this->request($client, 'GET', '/api/intervention-types/similar?' . http_build_query(['label' => 'PROTHESE TOTALE DE GENOU']), $token);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertNotEmpty($body, 'une variante accents/casse doit être suggérée.');
        self::assertSame('HIGH', $body[0]['confidence']);
    }

    public function test_similar_returns_empty_for_a_genuinely_different_label(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $this->makeType('PTG-' . bin2hex(random_bytes(3)))->setLabel('Prothèse totale de genou');
        $this->em->flush();

        $response = $this->request($client, 'GET', '/api/intervention-types/similar?' . http_build_query(['label' => 'Reconstruction du ligament croisé antérieur']), $token);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame([], json_decode($response->getContent(), true), 'des interventions réellement différentes ne doivent jamais être suggérées comme doublons.');
    }

    public function test_similar_does_not_block_creation_it_only_suggests(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $this->makeType('PTG-' . bin2hex(random_bytes(3)))->setLabel('PTG');
        $this->em->flush();

        // Même si /similar suggérerait un rapprochement, /intervention-types (create) doit
        // rester une simple création libre — jamais un blocage automatique sur la similarité.
        $response = $this->request($client, 'POST', '/api/intervention-types', $token, [
            'code' => 'PTG2-' . bin2hex(random_bytes(3)), 'label' => 'PTG',
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $this->createdTypeIds[] = json_decode($response->getContent(), true)['id'];
    }

    public function test_duplicate_audit_reports_firms_count_and_candidates(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);

        $type = $this->makeType('AUD-' . bin2hex(random_bytes(3)));
        $type->setLabel('Audit LCA');
        $this->em->flush();

        $firm = new Firm();
        $firm->setName('AuditFirm-' . bin2hex(random_bytes(3)));
        $this->em->persist($firm);
        $this->em->flush();
        $this->createdFirmIds[] = $firm->getId();

        $offering = new FirmServiceOffering();
        $offering->setFirm($firm);
        $offering->setInterventionType($type);
        $this->em->persist($offering);
        $this->em->flush();
        $this->createdOfferingIds[] = $offering->getId();

        $response = $this->request($client, 'GET', '/api/intervention-types/duplicate-audit', $token);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $row = null;
        foreach ($body as $r) {
            if ($r['id'] === $type->getId()) { $row = $r; break; }
        }
        self::assertNotNull($row);
        self::assertSame(1, $row['firmsCount']);
        self::assertSame([$firm->getName()], $row['firms']);
        self::assertArrayHasKey('candidates', $row);
    }

    public function test_instrumentist_cannot_access_similar_or_duplicate_audit(): void
    {
        $client = $this->boot();
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $instr);

        $r1 = $this->request($client, 'GET', '/api/intervention-types/similar?label=PTG', $token);
        self::assertSame(Response::HTTP_FORBIDDEN, $r1->getStatusCode());

        $r2 = $this->request($client, 'GET', '/api/intervention-types/duplicate-audit', $token);
        self::assertSame(Response::HTTP_FORBIDDEN, $r2->getStatusCode());
    }

    public function test_manager_can_merge_a_duplicate_into_its_canonical_type(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);

        $source = $this->makeType('SRC-' . bin2hex(random_bytes(3)));
        $target = $this->makeType('TGT-' . bin2hex(random_bytes(3)));

        $response = $this->request($client, 'POST', "/api/intervention-types/{$source->getId()}/merge", $token, ['targetId' => $target->getId()]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertFalse($body['source']['active']);
        self::assertSame($target->getId(), $body['source']['mergedIntoId']);
        self::assertSame(0, $body['offeringsReassigned']);
    }

    public function test_merge_conflict_returns_409_with_conflicting_firm_names(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);

        $source = $this->makeType('SRC2-' . bin2hex(random_bytes(3)));
        $target = $this->makeType('TGT2-' . bin2hex(random_bytes(3)));

        $firm = new Firm();
        $firm->setName('ConflictFirm-' . bin2hex(random_bytes(3)));
        $this->em->persist($firm);
        $this->em->flush();
        $this->createdFirmIds[] = $firm->getId();

        foreach ([$source, $target] as $t) {
            $o = new FirmServiceOffering();
            $o->setFirm($firm);
            $o->setInterventionType($t);
            $this->em->persist($o);
            $this->em->flush();
            $this->createdOfferingIds[] = $o->getId();
        }

        $response = $this->request($client, 'POST', "/api/intervention-types/{$source->getId()}/merge", $token, ['targetId' => $target->getId()]);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertContains($firm->getName(), $body['error']['conflictingFirms']);
    }

    public function test_offerings_returns_firms_using_the_type_with_resolved_forfait(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);

        $type = $this->makeType('OFF-' . bin2hex(random_bytes(3)));
        $type->setLabel('Offerings LCA');
        $this->em->flush();

        $firmWithRate = new Firm();
        $firmWithRate->setName('WithRate-' . bin2hex(random_bytes(3)));
        $this->em->persist($firmWithRate);
        $firmNoForfait = new Firm();
        $firmNoForfait->setName('NoForfait-' . bin2hex(random_bytes(3)));
        $this->em->persist($firmNoForfait);
        $this->em->flush();
        $this->createdFirmIds[] = $firmWithRate->getId();
        $this->createdFirmIds[] = $firmNoForfait->getId();

        $offering1 = new FirmServiceOffering();
        $offering1->setFirm($firmWithRate);
        $offering1->setInterventionType($type);
        $offering1->setFeeApplicable(true);
        $this->em->persist($offering1);

        $offering2 = new FirmServiceOffering();
        $offering2->setFirm($firmNoForfait);
        $offering2->setInterventionType($type);
        $offering2->setFeeApplicable(false);
        $this->em->persist($offering2);
        $this->em->flush();
        $this->createdOfferingIds[] = $offering1->getId();
        $this->createdOfferingIds[] = $offering2->getId();

        $rule = new \App\Entity\PricingRule();
        $rule->setFirm($firmWithRate);
        $rule->setRuleType(\App\Enum\PricingRuleType::INTERVENTION_FEE);
        $rule->setInterventionType($type);
        $rule->setUnitPrice('220.00');
        $this->em->persist($rule);
        $this->em->flush();
        $this->createdRuleIds[] = $rule->getId();

        $response = $this->request($client, 'GET', "/api/intervention-types/{$type->getId()}/offerings", $token);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertCount(2, $body);

        $rows = [];
        foreach ($body as $row) { $rows[$row['firm']['name']] = $row; }

        $withRateRow = $rows[$firmWithRate->getName()];
        self::assertSame('220.00', $withRateRow['forfait']['amount']);
        self::assertTrue($withRateRow['feeApplicable']);
        self::assertArrayHasKey('logoPath', $withRateRow['firm']);

        $noForfaitRow = $rows[$firmNoForfait->getName()];
        self::assertFalse($noForfaitRow['feeApplicable']);
        self::assertNull($noForfaitRow['forfait'], 'feeApplicable=false ne doit jamais déclencher de résolution de tarif.');
    }

    public function test_instrumentist_cannot_merge(): void
    {
        $client = $this->boot();
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $instr);
        $source = $this->makeType('SRC3-' . bin2hex(random_bytes(3)));
        $target = $this->makeType('TGT3-' . bin2hex(random_bytes(3)));

        $response = $this->request($client, 'POST', "/api/intervention-types/{$source->getId()}/merge", $token, ['targetId' => $target->getId()]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }
}
