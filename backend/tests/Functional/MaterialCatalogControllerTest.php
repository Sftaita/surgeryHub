<?php

namespace App\Tests\Functional;

use App\Entity\Firm;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot 1 — /api/material-items : firme obligatoire, unicité (firm, referenceCode),
 * `active` réellement exposé, firme immuable une fois référencée par une MaterialLine
 * réelle, contrôle de rôle désormais explicite (trou identifié dans l'audit du
 * 2026-07-16 — MaterialCatalogController n'avait aucune vérification).
 */
final class MaterialCatalogControllerTest extends WebTestCase
{
    private const PASSWORD = 'MaterialItem15!';

    private EntityManagerInterface $em;
    private array $createdLineIds = [];
    private array $createdInterventionIds = [];
    private array $createdMissionIds = [];
    private array $createdItemIds = [];
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
            foreach ($this->createdLineIds as $id) {
                $e = $this->em->find(MaterialLine::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdInterventionIds as $id) {
                $e = $this->em->find(MissionIntervention::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdMissionIds as $id) {
                $e = $this->em->find(Mission::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdItemIds as $id) {
                $e = $this->em->find(MaterialItem::class, $id);
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
        $u->setEmail('mat-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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

    public function test_instrumentist_cannot_create_material_item(): void
    {
        $client = $this->boot();
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $instr);
        $firm = $this->makeFirm();

        $response = $this->request($client, 'POST', '/api/material-items', $token, [
            'firmId' => $firm->getId(), 'label' => 'Ancre', 'unit' => 'pièce',
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_manager_can_create_and_toggle_active(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firm = $this->makeFirm();

        $created = $this->request($client, 'POST', '/api/material-items', $token, [
            'firmId' => $firm->getId(), 'label' => 'Ancre TightRope', 'unit' => 'pièce', 'referenceCode' => 'TR-1',
        ]);
        self::assertSame(Response::HTTP_CREATED, $created->getStatusCode(), $created->getContent());
        $item = json_decode($created->getContent(), true);
        $this->createdItemIds[] = $item['id'];
        self::assertTrue($item['active']);

        $deactivated = $this->request($client, 'PATCH', "/api/material-items/{$item['id']}", $token, ['active' => false]);
        self::assertSame(Response::HTTP_OK, $deactivated->getStatusCode(), $deactivated->getContent());
        self::assertFalse(json_decode($deactivated->getContent(), true)['active']);
    }

    public function test_duplicate_reference_code_within_same_firm_is_rejected(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firm = $this->makeFirm();

        $first = $this->request($client, 'POST', '/api/material-items', $token, [
            'firmId' => $firm->getId(), 'label' => 'Vis A', 'unit' => 'pièce', 'referenceCode' => 'REF-DUP',
        ]);
        self::assertSame(Response::HTTP_CREATED, $first->getStatusCode());
        $this->createdItemIds[] = json_decode($first->getContent(), true)['id'];

        $second = $this->request($client, 'POST', '/api/material-items', $token, [
            'firmId' => $firm->getId(), 'label' => 'Vis B', 'unit' => 'pièce', 'referenceCode' => 'REF-DUP',
        ]);

        self::assertSame(Response::HTTP_CONFLICT, $second->getStatusCode(), $second->getContent());
    }

    public function test_firm_can_be_changed_when_item_is_not_yet_used(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firmA = $this->makeFirm();
        $firmB = $this->makeFirm();

        $created = $this->request($client, 'POST', '/api/material-items', $token, [
            'firmId' => $firmA->getId(), 'label' => 'Item libre', 'unit' => 'pièce',
        ]);
        $item = json_decode($created->getContent(), true);
        $this->createdItemIds[] = $item['id'];

        $updated = $this->request($client, 'PATCH', "/api/material-items/{$item['id']}", $token, ['firmId' => $firmB->getId()]);

        self::assertSame(Response::HTTP_OK, $updated->getStatusCode(), $updated->getContent());
        self::assertSame($firmB->getId(), json_decode($updated->getContent(), true)['firm']['id']);
    }

    public function test_firm_is_immutable_once_used_by_a_real_material_line(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firmA = $this->makeFirm();
        $firmB = $this->makeFirm();

        $created = $this->request($client, 'POST', '/api/material-items', $token, [
            'firmId' => $firmA->getId(), 'label' => 'Item utilisé', 'unit' => 'pièce',
        ]);
        $itemDto = json_decode($created->getContent(), true);
        $item = $this->em->find(MaterialItem::class, $itemDto['id']);
        $this->createdItemIds[] = $item->getId();

        // Le client de test reboote le kernel à chaque requête HTTP, ce qui détache les
        // entités capturées avant (comme $manager) — on les re-récupère fraîchement.
        $manager = $this->em->find(User::class, $manager->getId());

        // Simule une vraie utilisation (Lot 5 branchera l'encodage réel).
        $mission = new Mission();
        $mission->setType(MissionType::BLOCK);
        $mission->setSite($this->makeHospital());
        $mission->setSurgeon($manager);
        $mission->setCreatedBy($manager);
        $mission->setStartAt(new \DateTimeImmutable('2026-09-01 08:00:00'));
        $mission->setEndAt(new \DateTimeImmutable('2026-09-01 12:00:00'));
        $mission->setStatus(MissionStatus::ASSIGNED);
        $this->em->persist($mission);
        $this->em->flush();
        $this->createdMissionIds[] = $mission->getId();

        $intervention = new MissionIntervention();
        $intervention->setMission($mission);
        $intervention->setCode('TEST');
        $intervention->setLabel('Test');
        $this->em->persist($intervention);
        $this->em->flush();
        $this->createdInterventionIds[] = $intervention->getId();

        $line = new MaterialLine();
        $line->setMission($mission);
        $line->setMissionIntervention($intervention);
        $line->setItem($item);
        $line->setQuantity('1.00');
        $line->setCreatedBy($manager);
        $this->em->persist($line);
        $this->em->flush();
        $this->createdLineIds[] = $line->getId();

        $response = $this->request($client, 'PATCH', "/api/material-items/{$item->getId()}", $token, ['firmId' => $firmB->getId()]);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode(), $response->getContent());
        self::assertSame($firmA->getId(), $this->em->find(MaterialItem::class, $item->getId())->getFirm()->getId());
    }

    private function makeHospital(): \App\Entity\Hospital
    {
        $h = new \App\Entity\Hospital();
        $h->setName('Hopital-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        return $h;
    }
}
