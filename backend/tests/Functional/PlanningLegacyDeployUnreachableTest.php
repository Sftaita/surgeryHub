<?php

namespace App\Tests\Functional;

use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\SurgeonInstrumentistIncompatibility;
use App\Entity\User;
use App\Enum\EmploymentType;
use App\Enum\IncompatibilityOrigin;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-152 (réserve 2) — non-regression: the historical « Planning V1 » deploy (no version: every
 * DRAFT of a period becomes OPEN, no revalidation, no incompatibility check) is not reachable
 * over HTTP. A request in the V1 shape (period + site, no planningVersionId) is refused before
 * anything is published; a DRAFT carrying an incompatible pair stays DRAFT.
 */
final class PlanningLegacyDeployUnreachableTest extends WebTestCase
{
    private const PASSWORD = 'LegacyDeploy152!';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private array $userIds = [];
    private ?int $siteId = null;
    private ?int $missionId = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $conn = $this->em->getConnection();
        if ($this->missionId !== null) {
            $conn->executeStatement('DELETE FROM mission_assignment WHERE mission_id = ?', [$this->missionId]);
            $conn->executeStatement('DELETE FROM audit_event WHERE mission_id = ?', [$this->missionId]);
            $conn->executeStatement('DELETE FROM mission WHERE id = ?', [$this->missionId]);
        }
        $ids = implode(',', array_map('intval', $this->userIds) ?: [0]);
        $conn->executeStatement("DELETE FROM surgeon_instrumentist_incompatibility WHERE surgeon_id IN ($ids)");
        $conn->executeStatement("DELETE FROM audit_event WHERE actor_id IN ($ids)");
        $conn->executeStatement("DELETE FROM `user` WHERE id IN ($ids)");
        if ($this->siteId !== null) {
            $conn->executeStatement('DELETE FROM hospital WHERE id = ?', [$this->siteId]);
        }
        parent::tearDown();
    }

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('legacy-deploy-' . bin2hex(random_bytes(4)) . '@example.org');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Legacy');
        $u->setLastname($role);
        if ($role === 'ROLE_INSTRUMENTIST') {
            $u->setEmploymentType(EmploymentType::FREELANCER);
        }
        $u->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->userIds[] = $u->getId();

        return $u;
    }

    public function test_a_v1_shaped_deploy_request_is_refused_and_publishes_nothing(): void
    {
        $manager = $this->makeUser('ROLE_MANAGER');
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $site = new Hospital();
        $site->setName('Legacy deploy ' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        $this->em->persist(new SurgeonInstrumentistIncompatibility($surgeon, $instrumentist, IncompatibilityOrigin::MANAGER, $manager, new \DateTimeImmutable()));

        $day = (new \DateTimeImmutable('today', new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE)))->modify('+12 days');
        $draft = new Mission();
        $draft->setType(MissionType::BLOCK);
        $draft->setSite($site);
        $draft->setSurgeon($surgeon);
        $draft->setCreatedBy($manager);
        $draft->setStartAt($day->setTime(8, 0));
        $draft->setEndAt($day->setTime(13, 0));
        $draft->setStatus(MissionStatus::DRAFT);
        $draft->setInstrumentist($instrumentist);
        $this->em->persist($draft);
        $this->em->flush();
        $this->siteId = $site->getId();
        $this->missionId = $draft->getId();

        $this->client->request('POST', '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $manager->getEmail(), 'password' => self::PASSWORD]),
        );
        $token = json_decode((string) $this->client->getResponse()->getContent(), true)['token'];

        foreach ([
            [],
            ['from' => $day->format('Y-m-d'), 'to' => $day->format('Y-m-d'), 'siteId' => $this->siteId],
            ['from' => $day->format('Y-m-d'), 'to' => $day->format('Y-m-d'), 'planningVersionId' => null],
        ] as $body) {
            $this->client->request('POST', '/api/planning/v2/deploy',
                server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
                content: json_encode($body),
            );
            self::assertSame(400, $this->client->getResponse()->getStatusCode(), json_encode($body));
        }

        $conn = $this->em->getConnection();
        self::assertSame('DRAFT', $conn->fetchOne('SELECT status FROM mission WHERE id = ?', [$this->missionId]), 'never published by a period-only deploy');
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM mission_assignment WHERE mission_id = ?', [$this->missionId]));

        // No other route exposes a deploy.
        $deployRoutes = [];
        foreach (static::getContainer()->get('router')->getRouteCollection() as $name => $route) {
            if (str_contains($route->getPath(), 'deploy') && in_array('POST', $route->getMethods(), true)) {
                $deployRoutes[] = $route->getPath();
            }
        }
        self::assertSame(['/api/planning/v2/deploy'], $deployRoutes);
    }
}
