<?php

namespace App\Tests\Functional;

use App\Entity\Firm;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\PricingRule;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot 1 — /api/firms/{firmId}/pricing-rules (nouvelle mouture : interventionType FK,
 * dates de validité, devise, anti-chevauchement bloquant).
 */
final class FirmBillingControllerPricingRuleTest extends WebTestCase
{
    private const PASSWORD = 'PricingRule15!';

    private EntityManagerInterface $em;
    private array $createdRuleIds = [];
    private array $createdItemIds = [];
    private array $createdTypeIds = [];
    private array $createdFirmIds = [];
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdRuleIds as $id) {
                $e = $this->em->find(PricingRule::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdItemIds as $id) {
                $e = $this->em->find(MaterialItem::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            foreach ($this->createdTypeIds as $id) {
                $e = $this->em->find(InterventionType::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdFirmIds as $id) {
                $e = $this->em->find(Firm::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
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
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('rule-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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

    private function request(KernelBrowser $client, string $method, string $uri, string $token, array $body = []): Response
    {
        $client->request($method, $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: $method === 'GET' ? null : json_encode($body),
        );
        return $client->getResponse();
    }

    private function makeFirm(): Firm
    {
        $f = new Firm();
        $f->setName('Firm-' . bin2hex(random_bytes(4)));
        $this->em->persist($f);
        $this->em->flush();
        $this->createdFirmIds[] = $f->getId();
        return $f;
    }

    private function makeType(): InterventionType
    {
        $t = new InterventionType();
        $t->setCode('CODE-' . bin2hex(random_bytes(4)));
        $t->setLabel('Type de test');
        $this->em->persist($t);
        $this->em->flush();
        $this->createdTypeIds[] = $t->getId();
        return $t;
    }

    private function makeItem(Firm $firm): MaterialItem
    {
        $mi = new MaterialItem();
        $mi->setFirm($firm);
        $mi->setLabel('Item-' . bin2hex(random_bytes(3)));
        $mi->setUnit('pièce');
        $mi->setReferenceCode(bin2hex(random_bytes(4)));
        $this->em->persist($mi);
        $this->em->flush();
        $this->createdItemIds[] = $mi->getId();
        return $mi;
    }

    public function test_create_intervention_fee_rule_defaults_to_eur_and_open_dates(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firm = $this->makeFirm();
        $type = $this->makeType();

        $response = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/pricing-rules", $token, [
            'ruleType' => 'INTERVENTION_FEE', 'interventionTypeId' => $type->getId(), 'unitPrice' => 180,
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->createdRuleIds[] = $body['id'];
        self::assertSame('EUR', $body['currency']);
        self::assertNull($body['validFrom']);
        self::assertNull($body['validTo']);
        // Comparaison numérique : la réponse immédiate (sans re-fetch DB) reflète la
        // chaîne PHP telle que posée ("180"), pas le format decimal(10,2) stocké.
        self::assertSame(180.0, (float) $body['unitPrice']);
    }

    public function test_create_material_fee_rule_for_non_implant_item_succeeds(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firm = $this->makeFirm();
        $item = $this->makeItem($firm); // isImplant=false par défaut

        $response = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/pricing-rules", $token, [
            'ruleType' => 'MATERIAL_FEE', 'materialItemId' => $item->getId(), 'unitPrice' => 40,
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $this->createdRuleIds[] = json_decode($response->getContent(), true)['id'];
    }

    public function test_material_from_another_firm_is_rejected(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firm = $this->makeFirm();
        $otherFirm = $this->makeFirm();
        $item = $this->makeItem($otherFirm);

        $response = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/pricing-rules", $token, [
            'ruleType' => 'MATERIAL_FEE', 'materialItemId' => $item->getId(), 'unitPrice' => 40,
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function test_overlapping_validity_periods_are_rejected(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firm = $this->makeFirm();
        $type = $this->makeType();

        $first = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/pricing-rules", $token, [
            'ruleType' => 'INTERVENTION_FEE', 'interventionTypeId' => $type->getId(), 'unitPrice' => 250,
            'validFrom' => '2026-01-01', 'validTo' => '2026-12-31',
        ]);
        self::assertSame(Response::HTTP_CREATED, $first->getStatusCode(), $first->getContent());
        $this->createdRuleIds[] = json_decode($first->getContent(), true)['id'];

        // Chevauche les 15 derniers jours de la première règle.
        $second = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/pricing-rules", $token, [
            'ruleType' => 'INTERVENTION_FEE', 'interventionTypeId' => $type->getId(), 'unitPrice' => 275,
            'validFrom' => '2026-12-15', 'validTo' => null,
        ]);

        self::assertSame(Response::HTTP_CONFLICT, $second->getStatusCode(), $second->getContent());
    }

    public function test_consecutive_non_overlapping_periods_are_accepted(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firm = $this->makeFirm();
        $type = $this->makeType();

        $first = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/pricing-rules", $token, [
            'ruleType' => 'INTERVENTION_FEE', 'interventionTypeId' => $type->getId(), 'unitPrice' => 250,
            'validFrom' => null, 'validTo' => '2026-12-31',
        ]);
        self::assertSame(Response::HTTP_CREATED, $first->getStatusCode(), $first->getContent());
        $this->createdRuleIds[] = json_decode($first->getContent(), true)['id'];

        $second = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/pricing-rules", $token, [
            'ruleType' => 'INTERVENTION_FEE', 'interventionTypeId' => $type->getId(), 'unitPrice' => 275,
            'validFrom' => '2027-01-01', 'validTo' => null,
        ]);

        self::assertSame(Response::HTTP_CREATED, $second->getStatusCode(), $second->getContent());
        $this->createdRuleIds[] = json_decode($second->getContent(), true)['id'];
    }

    public function test_exact_boundary_date_is_covered_by_the_rule(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firm = $this->makeFirm();
        $type = $this->makeType();

        $created = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/pricing-rules", $token, [
            'ruleType' => 'INTERVENTION_FEE', 'interventionTypeId' => $type->getId(), 'unitPrice' => 250,
            'validFrom' => '2026-01-01', 'validTo' => '2026-12-31',
        ]);
        self::assertSame(Response::HTTP_CREATED, $created->getStatusCode());
        $body = json_decode($created->getContent(), true);
        $this->createdRuleIds[] = $body['id'];

        $rule = $this->em->find(PricingRule::class, $body['id']);
        self::assertTrue($rule->coversDate(new \DateTimeImmutable('2026-01-01')));
        self::assertTrue($rule->coversDate(new \DateTimeImmutable('2026-12-31')));
        self::assertFalse($rule->coversDate(new \DateTimeImmutable('2027-01-01')));
    }

    public function test_instrumentist_cannot_create_pricing_rule(): void
    {
        $client = $this->boot();
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $instr);
        $firm = $this->makeFirm();
        $type = $this->makeType();

        $response = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/pricing-rules", $token, [
            'ruleType' => 'INTERVENTION_FEE', 'interventionTypeId' => $type->getId(), 'unitPrice' => 100,
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }
}
