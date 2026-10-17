<?php

namespace App\Tests\Functional;

use App\Entity\SurgeonInstrumentistIncompatibility;
use App\Entity\User;
use App\Enum\EmploymentType;
use App\Enum\IncompatibilityOrigin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-152 (réserve 1) — `GET /api/incompatibilities/candidates`: the manager's incompatibility
 * picker searches and pages on the server, beyond 500 accounts (520 instrumentists here), with
 * the population create() accepts from a manager (active, right role, not already
 * incompatible), and the incompatibility rights (MANAGE).
 */
final class IncompatibilityCandidatesSearchTest extends WebTestCase
{
    private const PASSWORD = 'Candidates152!';
    private const COUNT = 520;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    /** Unique to this run: the search is isolated from any other account of the test database. */
    private string $tag;
    private array $userIds = [];

    private User $manager;
    private User $surgeon;
    private User $otherSurgeon;
    private User $blocked;
    private User $inactive;
    private User $instrumentistFile;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->tag = 'Cand' . bin2hex(random_bytes(3));

        $this->manager = $this->makeUser('ROLE_MANAGER', 'Manon', 'Manager', withPassword: true);
        $this->surgeon = $this->makeUser('ROLE_SURGEON', 'Simon', $this->tag . '-chirurgien', withPassword: true);
        $this->otherSurgeon = $this->makeUser('ROLE_SURGEON', 'Sarah', $this->tag . '-chirurgienne');
        for ($i = 1; $i <= self::COUNT; $i++) {
            $this->makeUser('ROLE_INSTRUMENTIST', 'Inst', sprintf('%s-%03d', $this->tag, $i), flush: false);
        }
        $this->blocked = $this->makeUser('ROLE_INSTRUMENTIST', 'Bloque', $this->tag . '-zz-bloque', flush: false);
        $this->inactive = $this->makeUser('ROLE_INSTRUMENTIST', 'Inactif', $this->tag . '-zz-inactif', flush: false);
        $this->inactive->setActive(false);
        $this->instrumentistFile = $this->makeUser('ROLE_INSTRUMENTIST', 'Iris', $this->tag . '-zz-fiche', withPassword: true, flush: false);
        $this->em->flush();
        foreach ([$this->blocked, $this->inactive, $this->instrumentistFile] as $u) {
            $this->userIds[] = $u->getId();
        }

        $incompatibility = new SurgeonInstrumentistIncompatibility($this->surgeon, $this->blocked, IncompatibilityOrigin::MANAGER, $this->manager, new \DateTimeImmutable());
        $this->em->persist($incompatibility);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM surgeon_instrumentist_incompatibility WHERE surgeon_id IN (SELECT id FROM `user` WHERE lastname LIKE ?)', [$this->tag . '%']);
        $conn->executeStatement('DELETE FROM refresh_tokens WHERE username IN (SELECT email FROM `user` WHERE lastname LIKE ? OR id = ?)', [$this->tag . '%', $this->manager->getId()]);
        $conn->executeStatement('DELETE FROM `user` WHERE lastname LIKE ? OR id = ?', [$this->tag . '%', $this->manager->getId()]);
        parent::tearDown();
    }

    private function makeUser(string $role, string $firstname, string $lastname, bool $withPassword = false, bool $flush = true): User
    {
        $u = new User();
        $u->setEmail(strtolower($lastname) . '-' . bin2hex(random_bytes(3)) . '@example.org');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname($firstname);
        $u->setLastname($lastname);
        if ($role === 'ROLE_INSTRUMENTIST') {
            $u->setEmploymentType(EmploymentType::FREELANCER);
        }
        $u->setPassword($withPassword
            ? static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, self::PASSWORD)
            : '!');
        $this->em->persist($u);
        if ($flush) {
            $this->em->flush();
            $this->userIds[] = $u->getId();
        }

        return $u;
    }

    private function get(User $as, string $uri): Response
    {
        $this->client->request('POST', '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $as->getEmail(), 'password' => self::PASSWORD]),
        );
        $token = json_decode((string) $this->client->getResponse()->getContent(), true)['token'] ?? '';
        $this->client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        return $this->client->getResponse();
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true);
    }

    public function test_pages_through_more_than_500_instrumentists_on_the_server(): void
    {
        $base = '/api/incompatibilities/candidates?surgeonId=' . $this->surgeon->getId() . '&q=' . $this->tag;

        $first = $this->data($this->get($this->manager, $base . '&limit=50'));
        self::assertSame(self::COUNT + 1, $first['total'], '520 + the instrumentist of the other file; blocked and inactive excluded');
        self::assertCount(50, $first['items'], 'one page only, never the whole list');
        self::assertTrue($first['hasMore']);
        self::assertSame('Inst ' . $this->tag . '-001', $first['items'][0]['name']);

        // Page 11 (offset 500): accounts beyond the old 500 limit are reachable.
        $page11 = $this->data($this->get($this->manager, $base . '&limit=50&page=11'));
        self::assertSame(['Inst ' . $this->tag . '-501', 'Inst ' . $this->tag . '-520'], [$page11['items'][0]['name'], $page11['items'][19]['name']]);
        self::assertSame('Iris ' . $this->tag . '-zz-fiche', $page11['items'][20]['name']);
        self::assertFalse($page11['hasMore']);

        // Search narrows on the server: the 517th account found directly, several terms combined.
        $one = $this->data($this->get($this->manager, '/api/incompatibilities/candidates?surgeonId=' . $this->surgeon->getId() . '&q=' . urlencode('inst ' . $this->tag . '-517')));
        self::assertSame(1, $one['total']);
        self::assertSame('Inst ' . $this->tag . '-517', $one['items'][0]['name']);

        $names = array_column($this->data($this->get($this->manager, $base . '&q=' . urlencode($this->tag . '-zz') . '&limit=50'))['items'], 'name');
        self::assertNotContains('Bloque ' . $this->tag . '-zz-bloque', $names, 'already incompatible with this surgeon');
        self::assertNotContains('Inactif ' . $this->tag . '-zz-inactif', $names);
        self::assertSame(20, $this->data($this->get($this->manager, $base))['limit'], 'default page size');
    }

    public function test_instrumentist_file_searches_surgeons(): void
    {
        $data = $this->data($this->get($this->manager, '/api/incompatibilities/candidates?instrumentistId=' . $this->blocked->getId() . '&q=' . $this->tag));

        self::assertSame(['Sarah ' . $this->tag . '-chirurgienne'], array_column($data['items'], 'name'), 'surgeons only, the one already incompatible excluded');
    }

    public function test_rights_and_validation(): void
    {
        $uri = '/api/incompatibilities/candidates?surgeonId=' . $this->surgeon->getId();
        $this->client->request('GET', $uri);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertSame(403, $this->get($this->surgeon, $uri)->getStatusCode(), 'a surgeon uses /api/me/blockable-instrumentists');
        self::assertSame(403, $this->get($this->instrumentistFile, $uri)->getStatusCode(), 'never visible to an instrumentist');

        foreach ([
            '/api/incompatibilities/candidates' => 422,
            $uri . '&instrumentistId=' . $this->blocked->getId() => 422,
            '/api/incompatibilities/candidates?surgeonId=' . $this->blocked->getId() => 422,
            '/api/incompatibilities/candidates?surgeonId=999999999' => 404,
            $uri . '&limit=51' => 422,
            $uri . '&page=0' => 422,
            $uri . '&limit=abc' => 422,
        ] as $bad => $status) {
            self::assertSame($status, $this->get($this->manager, $bad)->getStatusCode(), $bad);
        }
    }
}
