<?php

namespace App\Tests\Functional;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionPublication;
use App\Entity\SiteMembership;
use App\Entity\User;
use App\Enum\EmploymentType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Service\MissionPostDeployService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Non-régression de D-177 (audit de la PR #58) : les missions ORDINAIRES — demande nominative
 * D-125, pool, missions Planning V2 sans publication — se comportent exactement comme avant
 * la priorité de reprise. Fichier autonome, sans aucune API introduite par D-177 : il
 * s'exécute à l'identique sur `main` et sur la branche, pour comparer les deux.
 */
final class OrdinaryMissionDispatchNonRegressionTest extends WebTestCase
{
    private const PASSWORD = 'NonRegression177!';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $siteIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $sql = static function (string $q, array $p = []) use ($conn): void {
            try { $conn->executeStatement($q, $p); } catch (\Throwable) { /* best effort */ }
        };
        $missionIds = [];
        foreach ($this->userIds as $uid) {
            foreach ($conn->fetchFirstColumn('SELECT id FROM mission WHERE surgeon_id = ? OR instrumentist_id = ? OR created_by_id = ?', [$uid, $uid, $uid]) as $mid) {
                $missionIds[(int) $mid] = true;
            }
        }
        foreach (array_keys($missionIds) as $mid) {
            foreach (['notification_event', 'outbound_notification', 'audit_event', 'mission_claim', 'planning_alert', 'mission_publication', 'mission_assignment', 'mission_communication'] as $table) {
                $sql("DELETE FROM {$table} WHERE mission_id = ?", [$mid]);
            }
            $sql('DELETE FROM mission WHERE id = ?', [$mid]);
        }
        foreach ($this->userIds as $uid) {
            $sql('DELETE FROM notification_event WHERE user_id = ?', [$uid]);
            $sql('DELETE FROM outbound_notification WHERE recipient_user_id = ?', [$uid]);
            $sql('DELETE FROM audit_event WHERE actor_id = ?', [$uid]);
            $sql('DELETE FROM site_membership WHERE user_id = ?', [$uid]);
            $sql('DELETE FROM refresh_tokens WHERE username IN (SELECT email FROM user WHERE id = ?)', [$uid]);
        }
        foreach ($this->userIds as $uid) {
            $sql('DELETE FROM user WHERE id = ?', [$uid]);
        }
        foreach ($this->siteIds as $siteId) {
            $sql('DELETE FROM shift_period_config WHERE site_id = ?', [$siteId]);
            $sql('DELETE FROM hospital WHERE id = ?', [$siteId]);
        }
        parent::tearDown();
    }

    // ── 1. Demande nominative refusée, puis pool ────────────────────────────────────────

    public function test_targeted_request_declined_then_published_to_the_pool(): void
    {
        $s = $this->scene();
        $m = $this->mission($s, MissionStatus::DRAFT);

        self::assertSame(204, $this->publishTargeted($s, $m, $s['x'])->getStatusCode());
        self::assertSame(403, $this->claim($m, $s['yToken'])->getStatusCode(), 'reserved for the requested instrumentist');
        self::assertSame(200, $this->request('POST', "/api/missions/{$m->getId()}/decline-offer", $s['xToken'], ['reason' => 'Indisponible'])->getStatusCode());

        $detail = $this->detail($s, $m);
        self::assertSame('DECLINED', $detail['targetedOffer']['status'] ?? null, 'awaiting a new dispatch');

        self::assertSame(204, $this->publishPool($s, $m)->getStatusCode());
        self::assertSame(200, $this->claim($m, $s['yToken'])->getStatusCode());
        self::assertSame(2, $this->countPublications($m));
    }

    // ── 2. Demande nominative acceptée, puis libérée par le manager ────────────────────

    public function test_accepted_request_released_by_the_manager_keeps_its_publications(): void
    {
        $s = $this->scene();
        $m = $this->mission($s, MissionStatus::DRAFT);

        self::assertSame(204, $this->publishTargeted($s, $m, $s['x'])->getStatusCode());
        self::assertSame(200, $this->claim($m, $s['xToken'])->getStatusCode());
        self::assertSame(200, $this->request('POST', "/api/missions/{$m->getId()}/release", $s['managerToken'])->getStatusCode());

        // Behaviour before D-177, kept: no publication added, the request is unchanged.
        self::assertSame(1, $this->countPublications($m));
        self::assertSame(['scope' => 'TARGETED', 'declined' => false], $this->publicationShape($m)[0]);
        self::assertSame(403, $this->claim($m, $s['yToken'])->getStatusCode(), 'still reserved by the request, as before');
        self::assertSame(200, $this->claim($m, $s['xToken'])->getStatusCode());
    }

    // ── 3. Demande en attente, mission annulée puis restaurée en OPEN ──────────────────

    public function test_pending_request_survives_cancellation_and_restoration_to_open(): void
    {
        $s = $this->scene();
        $m = $this->mission($s, MissionStatus::DRAFT);
        self::assertSame(204, $this->publishTargeted($s, $m, $s['x'])->getStatusCode());

        $service = static::getContainer()->get(MissionPostDeployService::class);
        $service->cancel($this->ref($m), $this->ref($s['manager']), 'Bloc fermé', false);
        $this->em->clear();
        $service->restoreAfterCancellation($this->ref($m), $this->ref($s['manager']), null, 0);
        $this->em->clear();

        self::assertSame('OPEN', $this->ref($m)->getStatus()->value);
        self::assertSame(1, $this->countPublications($m), 'no publication added');
        self::assertSame('PENDING', $this->detail($s, $m)['targetedOffer']['status'] ?? null, 'the request is still pending');
        self::assertSame(403, $this->claim($m, $s['yToken'])->getStatusCode());
        self::assertSame(200, $this->claim($m, $s['xToken'])->getStatusCode());
    }

    // ── 4. Pool : prise, libération, nouvelle prise ─────────────────────────────────────

    public function test_pool_claim_release_and_new_claim(): void
    {
        $s = $this->scene();
        $m = $this->mission($s, MissionStatus::DRAFT);

        self::assertSame(204, $this->publishPool($s, $m)->getStatusCode());
        self::assertSame(200, $this->claim($m, $s['yToken'])->getStatusCode());
        self::assertSame(403, $this->claim($m, $s['xToken'])->getStatusCode(), 'one assignment only');
        self::assertSame(200, $this->request('POST', "/api/missions/{$m->getId()}/release", $s['managerToken'])->getStatusCode());
        self::assertSame(200, $this->claim($m, $s['xToken'])->getStatusCode());
        self::assertSame(1, $this->countPublications($m));
    }

    // ── 5. Demande nominative envoyée sur une mission déjà au pool, puis refusée ───────

    public function test_request_on_a_pool_mission_declined_is_shown_as_declined(): void
    {
        $s = $this->scene();
        $m = $this->mission($s, MissionStatus::DRAFT);

        self::assertSame(204, $this->publishPool($s, $m)->getStatusCode());
        self::assertSame(204, $this->publishTargeted($s, $m, $s['x'])->getStatusCode());
        self::assertSame(200, $this->request('POST', "/api/missions/{$m->getId()}/decline-offer", $s['xToken'])->getStatusCode());

        self::assertSame('DECLINED', $this->detail($s, $m)['targetedOffer']['status'] ?? null);
        self::assertSame(200, $this->claim($m, $s['yToken'])->getStatusCode(), 'the pool publication still applies');
    }

    // ── 6. Attribution directe pendant une demande en attente ──────────────────────────

    public function test_direct_assignment_while_a_request_is_pending(): void
    {
        $s = $this->scene();
        $m = $this->mission($s, MissionStatus::DRAFT);

        self::assertSame(204, $this->publishTargeted($s, $m, $s['x'])->getStatusCode());
        $res = $this->request('POST', "/api/missions/{$m->getId()}/assign-directly", $s['managerToken'], ['instrumentistId' => $s['y']->getId()]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());

        self::assertSame('ASSIGNED', $this->ref($m)->getStatus()->value);
        self::assertSame($s['y']->getId(), $this->ref($m)->getInstrumentist()?->getId());
        self::assertSame(['scope' => 'TARGETED', 'declined' => false], $this->publicationShape($m)[0], 'request row untouched');
        self::assertContains($this->claim($m, $s['xToken'])->getStatusCode(), [403, 409]);
    }

    // ── 7. Mission Planning V2 OPEN sans publication ───────────────────────────────────

    public function test_planning_mission_without_publication_stays_open_to_the_pool(): void
    {
        $s = $this->scene();
        $m = $this->mission($s, MissionStatus::OPEN);

        self::assertSame(200, $this->claim($m, $s['yToken'])->getStatusCode());
        self::assertSame(200, $this->request('POST', "/api/missions/{$m->getId()}/release", $s['managerToken'])->getStatusCode());
        self::assertSame(0, $this->countPublications($m), 'never a publication for a generated mission');
        self::assertSame(200, $this->claim($m, $s['xToken'])->getStatusCode());
    }

    // ── 8. Visibilité : liste « éligibles » de l'instrumentiste ────────────────────────

    public function test_eligible_lists_show_a_targeted_request_to_its_recipient_only(): void
    {
        $s = $this->scene();
        $m = $this->mission($s, MissionStatus::DRAFT);
        self::assertSame(204, $this->publishTargeted($s, $m, $s['x'])->getStatusCode());

        $ids = fn (string $token) => array_column($this->json($this->request('GET', '/api/missions?eligibleToMe=true', $token))['items'] ?? [], 'id');
        self::assertContains($m->getId(), $ids($s['xToken']));
        self::assertNotContains($m->getId(), $ids($s['yToken']));
        self::assertSame(403, $this->request('GET', "/api/missions/{$m->getId()}", $s['yToken'])->getStatusCode());
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function scene(): array
    {
        [$manager, $managerToken] = $this->loggedUser('ROLE_MANAGER', 'Manon');
        [$surgeon] = $this->loggedUser('ROLE_SURGEON', 'Simon');
        [$x, $xToken] = $this->loggedUser('ROLE_INSTRUMENTIST', 'Xavier', EmploymentType::EMPLOYEE);
        [$y, $yToken] = $this->loggedUser('ROLE_INSTRUMENTIST', 'Yasmine', EmploymentType::EMPLOYEE);

        $site = new Hospital();
        $site->setName('NonReg ' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        $this->em->flush();
        $this->siteIds[] = $site->getId();

        foreach ([[$surgeon, 'SURGEON'], [$x, 'INSTRUMENTIST'], [$y, 'INSTRUMENTIST']] as [$user, $role]) {
            $sm = new SiteMembership();
            $sm->setUser($this->ref($user));
            $sm->setSite($this->ref($site));
            $sm->setSiteRole($role);
            $this->em->persist($sm);
        }
        $this->em->flush();

        return compact('manager', 'managerToken', 'surgeon', 'x', 'xToken', 'y', 'yToken', 'site');
    }

    private function mission(array $s, MissionStatus $status): Mission
    {
        $tz = new \DateTimeZone(\App\Doctrine\Type\BusinessDateTimeImmutableType::BUSINESS_TIMEZONE);
        $day = (new \DateTimeImmutable('today', $tz))->modify('+21 days')->format('Y-m-d');
        $m = new Mission();
        $m->setStatus($status);
        $m->setType(MissionType::BLOCK);
        $m->setSurgeon($this->ref($s['surgeon']));
        $m->setSite($this->ref($s['site']));
        $m->setStartAt(new \DateTimeImmutable("{$day} 08:00:00", $tz));
        $m->setEndAt(new \DateTimeImmutable("{$day} 13:00:00", $tz));
        $m->setCreatedBy($this->ref($s['manager']));
        $this->em->persist($m);
        $this->em->flush();

        return $m;
    }

    /** @return array{0: User, 1: string} */
    private function loggedUser(string $role, string $firstname, ?EmploymentType $employment = null): array
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('nonreg177-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname($firstname);
        $u->setLastname('Test');
        $u->setEmploymentType($employment);
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->userIds[] = $u->getId();

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $u->getEmail(), 'password' => self::PASSWORD]));

        return [$u, $this->json($this->client->getResponse())['token'] ?? ''];
    }

    private function ref(object $entity): object
    {
        return $this->em->find($entity::class, $entity->getId());
    }

    private function publishTargeted(array $s, Mission $m, User $target): Response
    {
        return $this->request('POST', "/api/missions/{$m->getId()}/publish", $s['managerToken'], ['scope' => 'TARGETED', 'targetUserId' => $target->getId()]);
    }

    private function publishPool(array $s, Mission $m): Response
    {
        return $this->request('POST', "/api/missions/{$m->getId()}/publish", $s['managerToken'], ['scope' => 'POOL']);
    }

    private function claim(Mission $m, string $token): Response
    {
        return $this->request('POST', "/api/missions/{$m->getId()}/claim", $token);
    }

    /** @return array<string, mixed> */
    private function detail(array $s, Mission $m): array
    {
        return $this->json($this->request('GET', "/api/missions/{$m->getId()}", $s['managerToken']));
    }

    private function countPublications(Mission $m): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM mission_publication WHERE mission_id = ?', [$m->getId()]);
    }

    /** @return list<array{scope: string, declined: bool}> */
    private function publicationShape(Mission $m): array
    {
        $this->em->clear();

        return array_map(
            static fn (MissionPublication $p) => ['scope' => $p->getScope()->value, 'declined' => $p->getDeclinedAt() !== null],
            $this->em->getRepository(MissionPublication::class)->findBy(['mission' => $m->getId()], ['id' => 'ASC']),
        );
    }

    private function request(string $method, string $uri, string $token, ?array $body = null): Response
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
        if ($body !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        $this->client->request($method, $uri, server: $server, content: $body !== null ? json_encode($body) : null);
        $this->em->clear();

        return $this->client->getResponse();
    }

    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }
}
