<?php

namespace App\Tests\Functional;

use App\Entity\Hospital;
use App\Entity\RecurrenceRule;
use App\Entity\ShiftPeriodConfig;
use App\Entity\SiteMembership;
use App\Entity\SurgeonInstrumentistIncompatibility;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\EmploymentType;
use App\Enum\IncompatibilityOrigin;
use App\Enum\MissionType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ShiftPeriod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-152 — a recurring post configured before its default instrumentist became incompatible with
 * its surgeon: the planning V2 preview shows it blocked (CONFLICT, blockedReason
 * SURGEON_INCOMPATIBILITY, never « OK »), generate() agrees (the mission is created without her,
 * reported in rejectedAssignments — exactly the blocked lines), the posts list flags the post,
 * and the manager corrects it with another instrumentist.
 */
final class PlanningV2IncompatiblePostTest extends WebTestCase
{
    private const PASSWORD = 'IncompatiblePost152!';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $token;
    private array $userIds = [];
    private ?int $siteId = null;
    private array $postIds = [];
    private ?int $shiftConfigId = null;

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
        $users = implode(',', array_map('intval', $this->userIds) ?: [0]);
        if ($this->siteId !== null) {
            $missions = 'SELECT id FROM mission WHERE site_id = ' . (int) $this->siteId;
            $conn->executeStatement("DELETE FROM planning_alert WHERE mission_id IN (SELECT id FROM ($missions) x)");
            $conn->executeStatement("DELETE FROM mission_assignment WHERE mission_id IN (SELECT id FROM ($missions) x)");
            $conn->executeStatement("DELETE FROM audit_event WHERE mission_id IN (SELECT id FROM ($missions) x)");
            $versions = $conn->fetchFirstColumn('SELECT DISTINCT planning_version_id FROM mission WHERE site_id = ? AND planning_version_id IS NOT NULL', [$this->siteId]);
            $conn->executeStatement('DELETE FROM mission WHERE site_id = ?', [$this->siteId]);
            foreach ($versions as $v) {
                $conn->executeStatement('DELETE FROM planning_version WHERE id = ?', [$v]);
            }
            $conn->executeStatement('DELETE FROM planning_version WHERE site_id = ?', [$this->siteId]);
        }
        foreach ($this->postIds as $id) {
            $conn->executeStatement('DELETE FROM surgeon_schedule_post WHERE id = ?', [$id]);
        }
        if ($this->shiftConfigId !== null) {
            $conn->executeStatement('DELETE FROM shift_period_config WHERE id = ?', [$this->shiftConfigId]);
        }
        if ($this->siteId !== null) {
            $conn->executeStatement('DELETE FROM site_membership WHERE site_id = ?', [$this->siteId]);
        }
        $conn->executeStatement("DELETE FROM surgeon_instrumentist_incompatibility WHERE surgeon_id IN ($users)");
        $conn->executeStatement("DELETE FROM refresh_tokens WHERE username IN (SELECT email FROM `user` WHERE id IN ($users))");
        $conn->executeStatement("DELETE FROM `user` WHERE id IN ($users)");
        if ($this->siteId !== null) {
            $conn->executeStatement('DELETE FROM hospital WHERE id = ?', [$this->siteId]);
        }
        parent::tearDown();
    }

    private static function month(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('first day of +3 months midnight');
    }

    private function makeUser(string $role, bool $withPassword = false): User
    {
        $u = new User();
        $u->setEmail('incompatible-post-' . bin2hex(random_bytes(4)) . '@example.org');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Post');
        $u->setLastname($role . '-' . bin2hex(random_bytes(2)));
        if ($role === 'ROLE_INSTRUMENTIST') {
            $u->setEmploymentType(EmploymentType::FREELANCER);
        }
        $u->setPassword($withPassword ? static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, self::PASSWORD) : '!');
        $this->em->persist($u);
        $this->em->flush();
        $this->userIds[] = $u->getId();

        return $u;
    }

    /** A weekly Monday-morning post, persisted directly (as configured before the incompatibility). */
    private function makePost(User $surgeon, Hospital $site, User $instrumentist): SurgeonSchedulePost
    {
        $first = self::month();
        $monday = (int) $first->format('N') === 1 ? $first : $first->modify('next monday');
        $rule = new RecurrenceRule();
        $rule->setFrequency(RecurrenceFrequency::WEEKLY);
        $rule->setInterval(1);
        $rule->setWeekdays([1]);
        $rule->setAnchorDate($monday);

        $p = new SurgeonSchedulePost();
        $p->setSurgeon($surgeon);
        $p->setSite($site);
        $p->setType(MissionType::BLOCK);
        $p->setPeriod(ShiftPeriod::MATIN);
        $p->setRecurrence($rule);
        $p->setInstrumentist($instrumentist);
        $p->setStartDate($first);
        $p->setCreatedBy($surgeon);
        $this->em->persist($p);
        $this->em->flush();
        $this->postIds[] = $p->getId();

        return $p;
    }

    private function request(string $method, string $uri, ?array $body = null): Response
    {
        $this->client->request($method, $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token],
            content: $body !== null ? json_encode($body) : null,
        );

        return $this->client->getResponse();
    }

    /** @return array<string, mixed> */
    private function ok(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true);
    }

    public function test_preview_generation_and_posts_agree_on_a_blocked_default_instrumentist(): void
    {
        $manager = $this->makeUser('ROLE_MANAGER', withPassword: true);
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $otherSurgeon = $this->makeUser('ROLE_SURGEON');
        $blocked = $this->makeUser('ROLE_INSTRUMENTIST');
        $fine = $this->makeUser('ROLE_INSTRUMENTIST');
        $replacement = $this->makeUser('ROLE_INSTRUMENTIST');

        $site = new Hospital();
        $site->setName('Incompatible post ' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        $shift = new ShiftPeriodConfig();
        $shift->setSite($site);
        $shift->setPeriod(ShiftPeriod::MATIN);
        $shift->setStartTime(new \DateTimeImmutable('08:00'));
        $shift->setEndTime(new \DateTimeImmutable('13:00'));
        $this->em->persist($shift);
        foreach ([$blocked, $fine, $replacement] as $instrumentist) {
            $this->em->persist((new SiteMembership())->setSite($site)->setUser($instrumentist)->setSiteRole('INSTRUMENTIST'));
        }
        $this->em->flush();
        $this->siteId = $site->getId();
        $this->shiftConfigId = $shift->getId();

        $blockedPost = $this->makePost($surgeon, $site, $blocked);
        $finePost = $this->makePost($otherSurgeon, $site, $fine);
        $this->em->persist(new SurgeonInstrumentistIncompatibility($surgeon, $blocked, IncompatibilityOrigin::SURGEON, $surgeon, new \DateTimeImmutable()));
        $this->em->flush();

        $this->client->request('POST', '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $manager->getEmail(), 'password' => self::PASSWORD]),
        );
        $this->token = json_decode((string) $this->client->getResponse()->getContent(), true)['token'];
        $target = ['siteId' => $this->siteId, 'year' => (int) self::month()->format('Y'), 'month' => (int) self::month()->format('n')];

        // Preview: every occurrence of the blocked post is a blocked CONFLICT, never « OK ».
        $preview = $this->ok($this->request('POST', '/api/planning/v2/preview', $target));
        $byPost = [];
        foreach ($preview['lines'] as $line) {
            $byPost[$line['postId']][] = $line;
        }
        self::assertNotEmpty($byPost[$blockedPost->getId()] ?? []);
        foreach ($byPost[$blockedPost->getId()] as $line) {
            self::assertSame('CONFLICT', $line['status']);
            self::assertSame('SURGEON_INCOMPATIBILITY', $line['blockedReason']);
            self::assertSame($blocked->getId(), $line['instrumentistId'], 'the post stays identifiable');
        }
        foreach ($byPost[$finePost->getId()] as $line) {
            self::assertSame('COVERED', $line['status']);
            self::assertNull($line['blockedReason']);
        }

        // Posts list: the blocked post is flagged for the manager to correct.
        $posts = array_column($this->ok($this->request('GET', '/api/planning/surgeon-posts?siteId=' . $this->siteId))['items'], null, 'id');
        self::assertTrue($posts[$blockedPost->getId()]['instrumentistBlocked']);
        self::assertFalse($posts[$finePost->getId()]['instrumentistBlocked']);

        // Generation agrees with the preview: exactly the blocked lines are refused, their
        // missions created without instrumentist; the other post keeps hers.
        $generated = $this->ok($this->request('POST', '/api/planning/v2/generate', $target));
        $refused = array_values(array_filter($generated['rejectedAssignments'], static fn (array $r) => in_array('SURGEON_INCOMPATIBILITY', $r['reasons'], true)));
        self::assertCount(count($byPost[$blockedPost->getId()]), $refused);
        self::assertSame([$blocked->getId()], array_values(array_unique(array_column($refused, 'requestedInstrumentistId'))));

        $conn = $this->em->getConnection();
        $rows = $conn->fetchAllAssociative('SELECT surgeon_id, instrumentist_id FROM mission WHERE planning_version_id = ?', [$generated['versionId']]);
        foreach ($rows as $row) {
            self::assertSame(
                (int) $row['surgeon_id'] === $surgeon->getId() ? null : $fine->getId(),
                $row['instrumentist_id'] === null ? null : (int) $row['instrumentist_id'],
                'never an incompatible pair persisted',
            );
        }

        // The manager corrects the post: keeping the blocked instrumentist is refused, another one is accepted.
        self::assertSame(422, $this->request('PATCH', '/api/planning/surgeon-posts/' . $blockedPost->getId(), ['endDate' => self::month()->modify('+1 year')->format('Y-m-d')])->getStatusCode());
        $corrected = $this->ok($this->request('PATCH', '/api/planning/surgeon-posts/' . $blockedPost->getId(), ['instrumentistId' => $replacement->getId()]));
        self::assertSame($replacement->getId(), $corrected['instrumentist']['id']);
        self::assertFalse($corrected['instrumentistBlocked']);
    }
}
