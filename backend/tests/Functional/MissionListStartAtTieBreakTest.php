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
 * MissionService::list() orders by m.startAt then, for ties, by m.id in the same
 * direction as the primary sort (see MissionService.php, section on the secondary
 * sort key). This test proves that contract explicitly by creating two missions
 * sharing the exact same startAt and asserting the exact order of the IDs returned
 * by GET /api/missions — not merely that both are present.
 */
final class MissionListStartAtTieBreakTest extends WebTestCase
{
    private const PASSWORD = 'TieBreak15!';

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

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $u = new User();
        $u->setEmail('tiebreak-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('TieBreak-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdSiteIds[] = $h->getId();
        return $h;
    }

    private function makeMission(
        Hospital $site,
        User $surgeon,
        User $createdBy,
        \DateTimeImmutable $startAt,
        \DateTimeImmutable $endAt,
    ): Mission {
        $m = new Mission();
        $m->setType(MissionType::BLOCK);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($createdBy);
        $m->setStartAt($startAt);
        $m->setEndAt($endAt);
        $m->setStatus(MissionStatus::ASSIGNED);
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

    /**
     * Créneau unique par exécution : évite toute collision avec les données
     * résiduelles de surgicalhub_test ou avec d'autres fichiers de test.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function uniqueWindow(): array
    {
        $offsetMinutes = random_int(1, 5_000_000);
        $start = (new \DateTimeImmutable('2031-01-01 00:00:00'))->modify("+{$offsetMinutes} minutes");
        $end = $start->modify('+4 hours');
        return [$start, $end];
    }

    /**
     * GET /api/missions sans eligibleToMe=true trie m.startAt DESC, m.id DESC — c'est
     * la seule direction atteignable par un appelant ROLE_MANAGER via cet endpoint.
     * eligibleToMe=true (branche ASC) est réservé aux INSTRUMENTIST et nécessite en
     * plus tout le mécanisme d'éligibilité (SiteMembership / freelance / statut OPEN /
     * MissionPublication) pour même atteindre l'ORDER BY : hors périmètre ici, car ce
     * ne serait pas "simple et cohérent" à mettre en place seulement pour ce test de
     * tri. Seule la branche DESC est donc couverte.
     */
    public function test_list_breaks_startAt_ties_by_id_desc_when_sorted_desc(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site    = $this->makeSite();

        [$startAt, $endAt] = $this->uniqueWindow();

        $missionA = $this->makeMission($site, $surgeon, $manager, $startAt, $endAt);
        $missionB = $this->makeMission($site, $surgeon, $manager, $startAt, $endAt);

        $from = $startAt->modify('-1 minute')->format('Y-m-d H:i:s');
        $to   = $endAt->modify('+1 minute')->format('Y-m-d H:i:s');

        $response = $this->getJson($client, $token, '/api/missions?' . http_build_query([
            'from' => $from,
            'to' => $to,
            'limit' => 100,
        ]));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);

        $ourIds = [$missionA->getId(), $missionB->getId()];
        $returnedMissionIds = array_values(array_filter(
            array_map(static fn (array $it) => $it['id'], $body['items']),
            static fn (int $id) => in_array($id, $ourIds, true)
        ));

        // DESC direction: on a startAt tie, the higher id must come first — computed
        // from the actual IDs, not assumed from creation order or consecutiveness.
        $expected = $missionA->getId() > $missionB->getId()
            ? [$missionA->getId(), $missionB->getId()]
            : [$missionB->getId(), $missionA->getId()];

        self::assertSame(
            $expected,
            $returnedMissionIds,
            'Ties on startAt must break by id, same direction (DESC) as the primary sort: ' . $response->getContent()
        );
    }
}
