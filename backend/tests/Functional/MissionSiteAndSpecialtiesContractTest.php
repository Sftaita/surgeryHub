<?php

namespace App\Tests\Functional;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * GET /api/missions and GET /api/missions/{id} must carry site.photoPath / site.address
 * and surgeon.specialties — both were silently dropped by HospitalSlimDto/UserSlimDto
 * (MissionMapper::toHospitalSlim()/toUserSlim() built the DTOs with only a subset of the
 * entity's fields), which is the real reason hospital photos never displayed in the
 * instrumentist app: the field never reached the frontend at all, regardless of any
 * URL-resolution logic there.
 */
final class MissionSiteAndSpecialtiesContractTest extends WebTestCase
{
    private const PASSWORD = 'SiteContract15!';

    private EntityManagerInterface $em;
    private array $createdMissionIds = [];
    private array $createdUserIds    = [];
    private array $createdSiteIds    = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdMissionIds as $id) {
                $e = $this->em->find(Mission::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();

            foreach ($this->createdUserIds as $id) {
                $e = $this->em->find(User::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            foreach ($this->createdSiteIds as $id) {
                $e = $this->em->find(Hospital::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    private function boot(): KernelBrowser
    {
        $client   = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role, array $specialties = []): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $u = new User();
        $u->setEmail('sitecontract-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $u->setSpecialties($specialties);
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

    private function makeSite(?string $address = null, ?string $photoPath = null): Hospital
    {
        $h = new Hospital();
        $h->setName('SiteContract-' . bin2hex(random_bytes(3)));
        $h->setAddress($address);
        $h->setPhotoPath($photoPath);
        $this->em->persist($h);
        $this->em->flush();
        $this->createdSiteIds[] = $h->getId();
        return $h;
    }

    private function makeMission(Hospital $site, User $surgeon, User $createdBy, MissionStatus $status): Mission
    {
        $m = new Mission();
        $m->setType(MissionType::BLOCK);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($createdBy);
        $m->setStartAt(new \DateTimeImmutable('2026-09-01 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-09-01 12:00:00'));
        $m->setStatus($status);
        $this->em->persist($m);
        $this->em->flush();
        $this->createdMissionIds[] = $m->getId();
        return $m;
    }

    private function getJson(KernelBrowser $client, string $token, string $uri): Response
    {
        $client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        return $client->getResponse();
    }

    public function test_mission_list_includes_site_photo_address_and_surgeon_specialties(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON', ['GENOU', 'EPAULE']);
        $site    = $this->makeSite('Rue de la Clinique 1, 1000 Bruxelles', '/uploads/hospital-photos/hospital-x.jpg');
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED);

        $response = $this->getJson($client, $token, '/api/missions?limit=100');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $item = null;
        foreach ($body['items'] as $it) {
            if ($it['id'] === $mission->getId()) { $item = $it; break; }
        }
        self::assertNotNull($item, 'mission not found in list: ' . $response->getContent());
        self::assertSame('/uploads/hospital-photos/hospital-x.jpg', $item['site']['photoPath']);
        self::assertSame('Rue de la Clinique 1, 1000 Bruxelles', $item['site']['address']);
        self::assertSame(['GENOU', 'EPAULE'], $item['surgeon']['specialties']);
    }

    public function test_mission_detail_includes_site_photo_address_and_surgeon_specialties(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON', ['HANCHE']);
        $site    = $this->makeSite('Avenue du Bloc 12', '/uploads/hospital-photos/hospital-y.jpg');
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED);

        $response = $this->getJson($client, $token, '/api/missions/' . $mission->getId());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertSame('/uploads/hospital-photos/hospital-y.jpg', $body['site']['photoPath']);
        self::assertSame('Avenue du Bloc 12', $body['site']['address']);
        self::assertSame(['HANCHE'], $body['surgeon']['specialties']);
    }

    public function test_mission_without_site_photo_returns_null_photo_path(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site    = $this->makeSite(null, null);
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED);

        $response = $this->getJson($client, $token, '/api/missions/' . $mission->getId());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertNull($body['site']['photoPath']);
        self::assertNull($body['site']['address']);
    }

    public function test_mission_with_surgeon_without_specialty_returns_empty_array(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON', []);
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED);

        $response = $this->getJson($client, $token, '/api/missions/' . $mission->getId());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertSame([], $body['surgeon']['specialties']);
    }
}
