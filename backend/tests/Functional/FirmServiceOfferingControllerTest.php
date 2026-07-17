<?php

namespace App\Tests\Functional;

use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\SuggestedMaterial;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot 1 — /api/firms/{firmId}/service-offerings ("Prestations") + matériels suggérés.
 */
final class FirmServiceOfferingControllerTest extends WebTestCase
{
    private const PASSWORD = 'Offering15!';

    private EntityManagerInterface $em;
    private array $createdSuggestionIds = [];
    private array $createdOfferingIds = [];
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
            foreach ($this->createdSuggestionIds as $id) {
                $e = $this->em->find(SuggestedMaterial::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdOfferingIds as $id) {
                $e = $this->em->find(FirmServiceOffering::class, $id);
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
        $u->setEmail('offering-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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

    public function test_manager_can_create_offering_without_forfait_or_suggestions(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firm = $this->makeFirm();
        $type = $this->makeType();

        $response = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings", $token, [
            'interventionTypeId' => $type->getId(),
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->createdOfferingIds[] = $body['id'];
        self::assertTrue($body['active']);
        self::assertSame([], $body['suggestedMaterials']);
    }

    public function test_missing_intervention_type_is_rejected(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firm = $this->makeFirm();

        $response = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings", $token, []);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function test_duplicate_firm_intervention_type_pair_is_rejected(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firm = $this->makeFirm();
        $type = $this->makeType();

        $first = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings", $token, ['interventionTypeId' => $type->getId()]);
        self::assertSame(Response::HTTP_CREATED, $first->getStatusCode());
        $this->createdOfferingIds[] = json_decode($first->getContent(), true)['id'];

        $second = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings", $token, ['interventionTypeId' => $type->getId()]);

        self::assertSame(Response::HTTP_CONFLICT, $second->getStatusCode());
    }

    public function test_instrumentist_cannot_create_offering(): void
    {
        $client = $this->boot();
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $token = $this->login($client, $instr);
        $firm = $this->makeFirm();
        $type = $this->makeType();

        $response = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings", $token, ['interventionTypeId' => $type->getId()]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_deactivate_offering(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token = $this->login($client, $manager);
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $created = json_decode($this->request($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings", $token, ['interventionTypeId' => $type->getId()])->getContent(), true);
        $this->createdOfferingIds[] = $created['id'];

        $response = $this->request($client, 'PATCH', "/api/firms/{$firm->getId()}/service-offerings/{$created['id']}", $token, ['active' => false]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertFalse(json_decode($response->getContent(), true)['active']);
    }

    public function test_add_reorder_and_delete_suggested_materials(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $firm = $this->makeFirm();
        $type = $this->makeType();
        $itemA = $this->makeItem($firm);
        $itemB = $this->makeItem($firm);
        // Toute la fixture est créée avant la première requête HTTP : le client de test
        // reboote le kernel à chaque requête, ce qui détache les entités créées via
        // $this->em juste après (cf. MissionAssignInstrumentistControllerTest).
        $token = $this->login($client, $manager);

        $offeringId = json_decode($this->request($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings", $token, ['interventionTypeId' => $type->getId()])->getContent(), true)['id'];
        $this->createdOfferingIds[] = $offeringId;

        $respA = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings/{$offeringId}/suggested-materials", $token, ['materialItemId' => $itemA->getId()]);
        self::assertSame(Response::HTTP_CREATED, $respA->getStatusCode(), $respA->getContent());
        $suggestionA = json_decode($respA->getContent(), true);
        $this->createdSuggestionIds[] = $suggestionA['id'];
        self::assertSame(0, $suggestionA['displayOrder']);

        $respB = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings/{$offeringId}/suggested-materials", $token, ['materialItemId' => $itemB->getId()]);
        $suggestionB = json_decode($respB->getContent(), true);
        $this->createdSuggestionIds[] = $suggestionB['id'];
        self::assertSame(1, $suggestionB['displayOrder']);

        // Doublon refusé
        $dup = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings/{$offeringId}/suggested-materials", $token, ['materialItemId' => $itemA->getId()]);
        self::assertSame(Response::HTTP_CONFLICT, $dup->getStatusCode());

        // Réordonnancement : B avant A
        $reorder = $this->request($client, 'PATCH', "/api/firms/{$firm->getId()}/service-offerings/{$offeringId}/suggested-materials/reorder", $token, [
            'orderedIds' => [$suggestionB['id'], $suggestionA['id']],
        ]);
        self::assertSame(Response::HTTP_OK, $reorder->getStatusCode(), $reorder->getContent());
        $reordered = json_decode($reorder->getContent(), true);
        $orderById = [];
        foreach ($reordered as $row) { $orderById[$row['id']] = $row['displayOrder']; }
        self::assertSame(0, $orderById[$suggestionB['id']]);
        self::assertSame(1, $orderById[$suggestionA['id']]);

        // Suppression physique (pas de confirmation nécessaire, aucune incidence historique)
        $del = $this->request($client, 'DELETE', "/api/firms/{$firm->getId()}/service-offerings/{$offeringId}/suggested-materials/{$suggestionA['id']}", $token);
        self::assertSame(Response::HTTP_OK, $del->getStatusCode());
        $this->createdSuggestionIds = array_diff($this->createdSuggestionIds, [$suggestionA['id']]);
        self::assertNull($this->em->find(SuggestedMaterial::class, $suggestionA['id']));
    }

    public function test_material_from_another_firm_is_rejected_as_suggestion(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $firm = $this->makeFirm();
        $otherFirm = $this->makeFirm();
        $type = $this->makeType();
        $foreignItem = $this->makeItem($otherFirm);
        $token = $this->login($client, $manager);

        $offeringId = json_decode($this->request($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings", $token, ['interventionTypeId' => $type->getId()])->getContent(), true)['id'];
        $this->createdOfferingIds[] = $offeringId;

        $response = $this->request($client, 'POST', "/api/firms/{$firm->getId()}/service-offerings/{$offeringId}/suggested-materials", $token, [
            'materialItemId' => $foreignItem->getId(),
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), $response->getContent());
    }
}
