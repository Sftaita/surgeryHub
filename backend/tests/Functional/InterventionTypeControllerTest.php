<?php

namespace App\Tests\Functional;

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

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdOfferingIds as $id) {
                $e = $this->em->find(FirmServiceOffering::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdFirmIds as $id) {
                $e = $this->em->find(Firm::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            foreach ($this->createdTypeIds as $id) {
                $e = $this->em->find(InterventionType::class, $id);
                if ($e !== null) { $this->em->remove($e); }
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
}
