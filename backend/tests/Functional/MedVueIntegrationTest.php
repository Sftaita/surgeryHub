<?php

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\MedVueAccountLink;
use App\Entity\User;
use App\Entity\UserAuditEvent;
use App\Enum\MedVueLinkRevocationSource;
use App\Enum\UserAuditEventType;
use App\Tests\Support\MockHttpClientCallback;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-140 — intégration MedVue côté SurgicalHub : association des comptes (droits, code, limites,
 * conflits, audit), API machine de lecture des congés (authentification, minimisation, fenêtre,
 * instantané complet) et révocation dans les deux sens.
 *
 * MedVue est simulé par MockHttpClientCallback : aucun appel réseau réel.
 */
final class MedVueIntegrationTest extends WebTestCase
{
    private const MACHINE_SECRET = 'test-medvue-to-surgicalhub-secret';
    private const PASSWORD = 'MedVueLink123!';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        // Garde le même noyau (et donc le cache array des limiteurs) entre les requêtes d'un test.
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        MockHttpClientCallback::reset();
    }

    protected function tearDown(): void
    {
        if ($this->userIds !== []) {
            $conn = $this->em->getConnection();
            $ids = implode(',', array_map('intval', $this->userIds));
            $conn->executeStatement("DELETE FROM user_audit_event WHERE actor_id IN ($ids) OR target_user_id IN ($ids)");
            $conn->executeStatement("DELETE FROM medvue_account_link WHERE user_id IN ($ids)");
            $conn->executeStatement("DELETE FROM absence WHERE user_id IN ($ids) OR created_by_id IN ($ids)");
            $conn->executeStatement("DELETE FROM refresh_tokens WHERE username IN (SELECT email FROM `user` WHERE id IN ($ids))");
            $conn->executeStatement("DELETE FROM `user` WHERE id IN ($ids)");
        }
        MockHttpClientCallback::reset();
        parent::tearDown();
    }

    // ── Association : parcours nominal ─────────────────────────────────────────────

    public function test_surgeon_links_own_account(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Jeanne', 'Martin');
        $token = $this->login($surgeon);
        MockHttpClientCallback::push($this->redeemOk('link-0001'));

        $this->postJson("/api/medvue-integration/users/{$surgeon->getId()}/link", ['code' => 'k7qm-2xpa-9drt'], $token);

        self::assertResponseStatusCodeSame(201);
        $data = $this->json();
        self::assertTrue($data['linked']);
        self::assertSame('Jeanne Martin', $data['linkedBy']['displayName']);

        // Requête sortante : corps plat exact du contrat v1, secret SH→MV, code normalisé.
        self::assertCount(1, MockHttpClientCallback::$requests);
        $request = MockHttpClientCallback::$requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://medvue.test/api/integrations/surgicalhub/v1/link-codes/redeem', $request['url']);
        self::assertContains('Authorization: Bearer test-surgicalhub-to-medvue-secret-0123456789', $request['options']['headers']);
        self::assertSame([
            'code' => 'K7QM2XPA9DRT',
            'surgicalHubUserId' => (string) $surgeon->getId(),
            'surgicalHubDisplayName' => 'Jeanne Martin',
            'actorDisplayName' => 'Jeanne Martin',
            'actorIsAdministrator' => false,
        ], json_decode($request['options']['body'], true));

        $link = $this->activeLink($surgeon);
        self::assertNotNull($link);
        self::assertSame('link-0001', $link->getMedvueLinkId());

        $audit = $this->auditFor($surgeon, UserAuditEventType::MEDVUE_LINKED);
        self::assertCount(1, $audit);
        self::assertSame(['linkId' => 'link-0001', 'byAdministrator' => false], $audit[0]->getPayload());
        self::assertStringNotContainsString('K7QM', json_encode([$audit[0]->getDescription(), $audit[0]->getPayload()]));

        $this->client->request('GET', "/api/medvue-integration/users/{$surgeon->getId()}/link", server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['linked']);
        self::assertTrue($this->json()['configured']);
    }

    /** @return iterable<string, array{string}> */
    public static function selfLinkRoles(): iterable
    {
        yield 'admin' => ['ROLE_ADMIN'];
        yield 'manager' => ['ROLE_MANAGER'];
        yield 'instrumentist' => ['ROLE_INSTRUMENTIST'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('selfLinkRoles')]
    public function test_every_business_role_can_link_own_account(string $role): void
    {
        $user = $this->makeUser($role);
        $token = $this->login($user);
        MockHttpClientCallback::push($this->redeemOk('link-self-' . strtolower(substr($role, 5))));

        $this->postJson("/api/medvue-integration/users/{$user->getId()}/link", ['code' => 'K7QM2XPA9DRT'], $token);

        self::assertResponseStatusCodeSame(201);
    }

    public function test_admin_links_another_user_with_that_users_code(): void
    {
        $admin = $this->makeUser('ROLE_ADMIN', 'Alice', 'Admin');
        $surgeon = $this->makeUser('ROLE_SURGEON', 'Paul', 'Durand');
        $token = $this->login($admin);
        MockHttpClientCallback::push($this->redeemOk('link-admin-0001'));

        $this->postJson("/api/medvue-integration/users/{$surgeon->getId()}/link", ['code' => 'K7QM2XPA9DRT'], $token);

        self::assertResponseStatusCodeSame(201);
        $body = json_decode(MockHttpClientCallback::$requests[0]['options']['body'], true);
        self::assertSame((string) $surgeon->getId(), $body['surgicalHubUserId']);
        self::assertSame('Paul Durand', $body['surgicalHubDisplayName']);
        self::assertSame('Alice Admin', $body['actorDisplayName']);
        self::assertTrue($body['actorIsAdministrator']);

        $link = $this->activeLink($surgeon);
        self::assertNotNull($link);
        self::assertSame($admin->getId(), $link->getLinkedBy()?->getId());
        self::assertNull($this->activeLink($admin));
        $audit = $this->auditFor($surgeon, UserAuditEventType::MEDVUE_LINKED);
        self::assertSame($admin->getId(), $audit[0]->getActor()?->getId());
        self::assertTrue($audit[0]->getPayload()['byAdministrator']);
    }

    // ── Association : droits ───────────────────────────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function nonAdminRoles(): iterable
    {
        yield 'manager' => ['ROLE_MANAGER'];
        yield 'surgeon' => ['ROLE_SURGEON'];
        yield 'instrumentist' => ['ROLE_INSTRUMENTIST'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonAdminRoles')]
    public function test_non_admin_cannot_link_view_or_revoke_another_user(string $role): void
    {
        $actor = $this->makeUser($role);
        $other = $this->makeUser('ROLE_SURGEON');
        $token = $this->login($actor);

        $this->postJson("/api/medvue-integration/users/{$other->getId()}/link", ['code' => 'K7QM2XPA9DRT'], $token);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', "/api/medvue-integration/users/{$other->getId()}/link", server: $this->auth($token));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('DELETE', "/api/medvue-integration/users/{$other->getId()}/link", server: $this->auth($token));
        self::assertResponseStatusCodeSame(403);

        self::assertSame([], MockHttpClientCallback::$requests, 'MedVue must never be called on a refused request.');
        self::assertNull($this->activeLink($other));
    }

    public function test_account_without_business_role_cannot_link(): void
    {
        $technical = $this->makeUser(null);
        $token = $this->login($technical);

        $this->postJson("/api/medvue-integration/users/{$technical->getId()}/link", ['code' => 'K7QM2XPA9DRT'], $token);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], MockHttpClientCallback::$requests);
    }

    public function test_anonymous_cannot_link(): void
    {
        $user = $this->makeUser('ROLE_SURGEON');
        $this->postJson("/api/medvue-integration/users/{$user->getId()}/link", ['code' => 'K7QM2XPA9DRT'], null);
        self::assertResponseStatusCodeSame(401);
    }

    // ── Association : code et refus ────────────────────────────────────────────────

    public function test_code_refused_by_medvue_is_reported_without_storing_or_logging_the_code(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $token = $this->login($surgeon);
        // MedVue : inconnu, expiré, déjà consommé et remplacé sont indistinguables (contrat v1).
        MockHttpClientCallback::push(new MockResponse(json_encode(['error' => 'invalid_code', 'message' => 'x']), ['http_code' => 422]));

        $this->postJson("/api/medvue-integration/users/{$surgeon->getId()}/link", ['code' => 'ZZZZ-ZZZZ-ZZZZ'], $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_code', $this->json()['error']['code']);
        self::assertNull($this->activeLink($surgeon));
        $audit = $this->auditFor($surgeon, UserAuditEventType::MEDVUE_LINK_FAILED);
        self::assertCount(1, $audit);
        self::assertSame('invalid_code', $audit[0]->getPayload()['reason']);
        self::assertStringNotContainsString('ZZZZ', json_encode([$audit[0]->getDescription(), $audit[0]->getPayload()]));
    }

    public function test_malformed_code_is_refused_without_calling_medvue(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $token = $this->login($surgeon);

        foreach (['', 'ABC', 'K7QM2XPA9DRTX', 'K7QM2XPA9DRU', '<script>'] as $code) {
            $this->postJson("/api/medvue-integration/users/{$surgeon->getId()}/link", ['code' => $code], $token);
            self::assertResponseStatusCodeSame(422, $code);
            self::assertSame('invalid_code_format', $this->json()['error']['code']);
        }
        self::assertSame([], MockHttpClientCallback::$requests);
    }

    public function test_repeated_attempts_are_rate_limited_before_reaching_medvue(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $token = $this->login($surgeon);
        for ($i = 0; $i < 5; $i++) {
            MockHttpClientCallback::push(new MockResponse(json_encode(['error' => 'invalid_code']), ['http_code' => 422]));
        }

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/medvue-integration/users/{$surgeon->getId()}/link", ['code' => 'K7QM2XPA9DRT'], $token);
            self::assertResponseStatusCodeSame(422);
        }
        $this->postJson("/api/medvue-integration/users/{$surgeon->getId()}/link", ['code' => 'K7QM2XPA9DRT'], $token);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $this->json()['error']['code']);
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));
        self::assertCount(5, MockHttpClientCallback::$requests, 'The 6th attempt must not reach MedVue.');
    }

    public function test_attempts_against_one_target_are_limited_across_actors(): void
    {
        $target = $this->makeUser('ROLE_SURGEON');
        $admins = [];
        for ($i = 0; $i < 5; $i++) {
            $admins[] = $this->login($this->makeUser('ROLE_ADMIN'));
        }
        for ($i = 0; $i < 20; $i++) {
            MockHttpClientCallback::push(new MockResponse(json_encode(['error' => 'invalid_code']), ['http_code' => 422]));
        }

        foreach ($admins as $token) {
            for ($i = 0; $i < 4; $i++) {
                $this->postJson("/api/medvue-integration/users/{$target->getId()}/link", ['code' => 'K7QM2XPA9DRT'], $token);
                self::assertResponseStatusCodeSame(422);
            }
        }
        $fresh = $this->login($this->makeUser('ROLE_ADMIN'));
        $this->postJson("/api/medvue-integration/users/{$target->getId()}/link", ['code' => 'K7QM2XPA9DRT'], $fresh);

        self::assertResponseStatusCodeSame(429);
        self::assertCount(20, MockHttpClientCallback::$requests);
    }

    public function test_already_linked_account_is_refused_locally(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $token = $this->login($surgeon);
        MockHttpClientCallback::push($this->redeemOk('link-first'));
        $this->postJson("/api/medvue-integration/users/{$surgeon->getId()}/link", ['code' => 'K7QM2XPA9DRT'], $token);
        self::assertResponseStatusCodeSame(201);

        $this->postJson("/api/medvue-integration/users/{$surgeon->getId()}/link", ['code' => 'A7QM2XPA9DRT'], $token);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('already_linked', $this->json()['error']['code']);
        self::assertCount(1, MockHttpClientCallback::$requests);
        self::assertSame('link-first', $this->activeLink($surgeon)?->getMedvueLinkId());
    }

    /** @return iterable<string, array{string, string}> */
    public static function medvueConflicts(): iterable
    {
        yield 'SurgicalHub account linked to another MedVue account' => ['already_linked', 'surgicalhub_account_linked_elsewhere'];
        yield 'MedVue account linked to another SurgicalHub account' => ['medvue_account_already_linked', 'medvue_account_already_linked'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('medvueConflicts')]
    public function test_conflicting_association_reported_by_medvue_is_refused(string $medvueError, string $expected): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $token = $this->login($surgeon);
        MockHttpClientCallback::push(new MockResponse(json_encode(['error' => $medvueError]), ['http_code' => 409]));

        $this->postJson("/api/medvue-integration/users/{$surgeon->getId()}/link", ['code' => 'K7QM2XPA9DRT'], $token);

        self::assertResponseStatusCodeSame(409);
        self::assertSame($expected, $this->json()['error']['code']);
        self::assertNull($this->activeLink($surgeon));
    }

    public function test_same_link_id_active_for_another_account_is_refused(): void
    {
        $first = $this->makeUser('ROLE_SURGEON');
        $second = $this->makeUser('ROLE_SURGEON');
        MockHttpClientCallback::push($this->redeemOk('link-shared'));
        MockHttpClientCallback::push($this->redeemOk('link-shared'));

        $this->postJson("/api/medvue-integration/users/{$first->getId()}/link", ['code' => 'K7QM2XPA9DRT'], $this->login($first));
        self::assertResponseStatusCodeSame(201);
        $this->postJson("/api/medvue-integration/users/{$second->getId()}/link", ['code' => 'K7QM2XPA9DRT'], $this->login($second));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('link_conflict', $this->json()['error']['code']);
        self::assertNull($this->activeLink($second));
    }

    /** @return iterable<string, array{MockResponse|\Throwable}> */
    public static function technicalFailures(): iterable
    {
        yield 'network' => [new TransportException('Connection refused')];
        yield '500' => [new MockResponse('oops', ['http_code' => 500])];
        yield '401 secret' => [new MockResponse(json_encode(['error' => 'unauthorized']), ['http_code' => 401])];
        yield '422 validation' => [new MockResponse(json_encode(['error' => 'validation_failed']), ['http_code' => 422])];
        yield '429' => [new MockResponse('', ['http_code' => 429])];
        yield '200 malformed' => [new MockResponse(json_encode(['linkId' => '../../x']), ['http_code' => 200])];
        yield '200 html' => [new MockResponse('<html>Traefik</html>', ['http_code' => 200])];
        yield 'redirect' => [new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'https://evil.test']])];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('technicalFailures')]
    public function test_technical_failure_stores_nothing(MockResponse|\Throwable $response): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $token = $this->login($surgeon);
        MockHttpClientCallback::push($response);

        $this->postJson("/api/medvue-integration/users/{$surgeon->getId()}/link", ['code' => 'K7QM2XPA9DRT'], $token);

        self::assertResponseStatusCodeSame(502);
        self::assertSame('medvue_unavailable', $this->json()['error']['code']);
        self::assertNull($this->activeLink($surgeon));
    }

    // ── API machine : authentification ─────────────────────────────────────────────

    public function test_machine_api_rejects_missing_wrong_or_user_credentials(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $link = $this->persistLink($surgeon, 'link-auth');
        $url = '/api/integrations/medvue/v1/links/link-auth/absences?from=2026-01-01&to=2026-12-31';

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(401);
        self::assertSame(['apiVersion' => 1, 'error' => 'unauthorized'], $this->json());

        $this->client->request('GET', $url, server: ['HTTP_AUTHORIZATION' => 'Bearer wrong-secret']);
        self::assertResponseStatusCodeSame(401);

        // Un JWT utilisateur, même ADMIN, n'ouvre jamais l'API machine.
        $adminToken = $this->login($this->makeUser('ROLE_ADMIN'));
        $this->client->request('GET', $url, server: $this->auth($adminToken));
        self::assertResponseStatusCodeSame(401);

        $this->client->request('DELETE', '/api/integrations/medvue/v1/links/link-auth');
        self::assertResponseStatusCodeSame(401);
        self::assertTrue($link->isActive());
    }

    public function test_machine_secret_opens_no_other_api_route(): void
    {
        foreach (['/api/absences', '/api/me', '/api/absences/mine', '/api/admin/users'] as $path) {
            $this->client->request('GET', $path, server: $this->machineAuth());
            self::assertResponseStatusCodeSame(401, $path);
        }
    }

    public function test_machine_api_exposes_no_write_on_absences(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $this->persistLink($surgeon, 'link-write');

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->client->request($method, '/api/integrations/medvue/v1/links/link-write/absences', server: $this->machineAuth());
            self::assertResponseStatusCodeSame(405, $method);
        }
    }

    // ── API machine : lecture des congés ───────────────────────────────────────────

    public function test_absence_snapshot_is_complete_minimal_and_scoped_to_the_link_owner(): void
    {
        $owner = $this->makeUser('ROLE_SURGEON');
        $other = $this->makeUser('ROLE_SURGEON');
        $this->persistLink($owner, 'link-read');

        $before = $this->absence($owner, '2026-01-01', '2026-01-10', 'Hors fenêtre');
        $straddle = $this->absence($owner, '2026-01-25', '2026-02-03', 'Motif médical confidentiel');
        $inside = $this->absence($owner, '2026-03-10', '2026-03-10', 'Congrès');
        $lastDay = $this->absence($owner, '2026-06-30', '2026-07-15', null);
        $after = $this->absence($owner, '2026-07-01', '2026-07-05', null);
        $foreign = $this->absence($other, '2026-03-01', '2026-03-31', 'Autre personne');

        $this->client->request('GET', '/api/integrations/medvue/v1/links/link-read/absences?from=2026-02-01&to=2026-06-30', server: $this->machineAuth());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $data = $this->json();
        self::assertSame(1, $data['apiVersion']);
        self::assertSame('link-read', $data['linkId']);
        self::assertSame(['from' => '2026-02-01', 'to' => '2026-06-30'], $data['window']);
        self::assertTrue($data['complete']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $data['generatedAt']));
        self::assertSame([
            ['id' => (string) $straddle->getId(), 'startDate' => '2026-01-25', 'endDate' => '2026-02-03', 'status' => 'CONFIRMED', 'updatedAt' => null],
            ['id' => (string) $inside->getId(), 'startDate' => '2026-03-10', 'endDate' => '2026-03-10', 'status' => 'CONFIRMED', 'updatedAt' => null],
            ['id' => (string) $lastDay->getId(), 'startDate' => '2026-06-30', 'endDate' => '2026-07-15', 'status' => 'CONFIRMED', 'updatedAt' => null],
        ], $data['absences']);

        $raw = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('reason', $raw);
        self::assertStringNotContainsString('confidentiel', $raw);
        self::assertStringNotContainsString('Congrès', $raw);
        self::assertStringNotContainsString('createdBy', $raw);
        self::assertNotContains((string) $before->getId(), array_column($data['absences'], 'id'));
        self::assertNotContains((string) $after->getId(), array_column($data['absences'], 'id'));
        self::assertNotContains((string) $foreign->getId(), array_column($data['absences'], 'id'));
    }

    public function test_modified_split_and_deleted_absences_are_reflected_in_the_next_snapshot(): void
    {
        $owner = $this->makeUser('ROLE_SURGEON');
        $this->persistLink($owner, 'link-changes');
        $kept = $this->absence($owner, '2026-04-01', '2026-04-10', null);
        $deleted = $this->absence($owner, '2026-05-01', '2026-05-02', null);
        $url = '/api/integrations/medvue/v1/links/link-changes/absences?from=2026-01-01&to=2026-12-31';

        $this->client->request('GET', $url, server: $this->machineAuth());
        self::assertCount(2, $this->json()['absences']);

        // Modification en place + scission (« retirer un jour » crée une nouvelle ligne) + suppression.
        $kept->setDateEnd(new \DateTimeImmutable('2026-04-04'));
        $split = $this->absence($owner, '2026-04-06', '2026-04-10', null);
        $this->em->remove($deleted);
        $this->em->flush();

        $this->client->request('GET', $url, server: $this->machineAuth());
        self::assertSame([
            ['id' => (string) $kept->getId(), 'startDate' => '2026-04-01', 'endDate' => '2026-04-04', 'status' => 'CONFIRMED', 'updatedAt' => null],
            ['id' => (string) $split->getId(), 'startDate' => '2026-04-06', 'endDate' => '2026-04-10', 'status' => 'CONFIRMED', 'updatedAt' => null],
        ], $this->json()['absences']);
    }

    public function test_empty_snapshot_is_still_explicitly_complete(): void
    {
        $owner = $this->makeUser('ROLE_SURGEON');
        $this->persistLink($owner, 'link-empty');

        $this->client->request('GET', '/api/integrations/medvue/v1/links/link-empty/absences?from=2026-01-01&to=2026-12-31', server: $this->machineAuth());

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['complete']);
        self::assertSame([], $this->json()['absences']);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidWindows(): iterable
    {
        yield 'missing' => [''];
        yield 'missing to' => ['?from=2026-01-01'];
        yield 'bad format' => ['?from=01/01/2026&to=2026-12-31'];
        yield 'impossible date' => ['?from=2026-02-30&to=2026-12-31'];
        yield 'reversed' => ['?from=2026-12-31&to=2026-01-01'];
        yield 'over 850 days' => ['?from=2026-01-01&to=2028-05-01'];
        yield 'array' => ['?from[]=2026-01-01&to=2026-12-31'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidWindows')]
    public function test_invalid_window_is_refused(string $query): void
    {
        $owner = $this->makeUser('ROLE_SURGEON');
        $this->persistLink($owner, 'link-window');

        $this->client->request('GET', '/api/integrations/medvue/v1/links/link-window/absences' . $query, server: $this->machineAuth());

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['apiVersion' => 1, 'error' => 'invalid_window'], $this->json());
    }

    public function test_window_of_exactly_850_days_is_accepted(): void
    {
        $owner = $this->makeUser('ROLE_SURGEON');
        $this->persistLink($owner, 'link-850');
        $from = new \DateTimeImmutable('2026-07-12');
        $to = $from->modify('+850 days');

        $this->client->request('GET', sprintf('/api/integrations/medvue/v1/links/link-850/absences?from=%s&to=%s', $from->format('Y-m-d'), $to->format('Y-m-d')), server: $this->machineAuth());

        self::assertResponseIsSuccessful();
    }

    public function test_snapshot_over_the_cap_is_refused_never_truncated(): void
    {
        $owner = $this->makeUser('ROLE_SURGEON');
        $this->persistLink($owner, 'link-cap');
        $conn = $this->em->getConnection();
        $values = [];
        for ($i = 0; $i < 1001; $i++) {
            $values[] = sprintf("(%d, '2026-03-01', '2026-03-01', NULL, %d, NOW())", $owner->getId(), $owner->getId());
        }
        $conn->executeStatement('INSERT INTO absence (user_id, date_start, date_end, reason, created_by_id, created_at) VALUES ' . implode(',', $values));

        $this->client->request('GET', '/api/integrations/medvue/v1/links/link-cap/absences?from=2026-01-01&to=2026-12-31', server: $this->machineAuth());

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['apiVersion' => 1, 'error' => 'window_too_large'], $this->json());
    }

    public function test_unknown_link_is_404_with_exact_contract_body(): void
    {
        $this->client->request('GET', '/api/integrations/medvue/v1/links/never-seen/absences?from=2026-01-01&to=2026-12-31', server: $this->machineAuth());

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['apiVersion' => 1, 'error' => 'link_not_found'], $this->json());
    }

    // ── Révocation ─────────────────────────────────────────────────────────────────

    public function test_revocation_in_surgicalhub_blocks_later_reads_with_410(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $token = $this->login($surgeon);
        $this->persistLink($surgeon, 'link-revoke');
        $this->absence($surgeon, '2026-03-01', '2026-03-05', null);
        $url = '/api/integrations/medvue/v1/links/link-revoke/absences?from=2026-01-01&to=2026-12-31';

        $this->client->request('DELETE', "/api/medvue-integration/users/{$surgeon->getId()}/link", server: $this->auth($token));
        self::assertResponseStatusCodeSame(204);
        self::assertSame([], MockHttpClientCallback::$requests, 'No SurgicalHub → MedVue call on revocation.');

        $this->client->request('GET', $url, server: $this->machineAuth());
        self::assertResponseStatusCodeSame(410);
        self::assertSame(['apiVersion' => 1, 'error' => 'link_revoked'], $this->json());

        $audit = $this->auditFor($surgeon, UserAuditEventType::MEDVUE_UNLINKED);
        self::assertSame(['linkId' => 'link-revoke', 'via' => 'SURGICALHUB'], $audit[0]->getPayload());

        $this->client->request('DELETE', "/api/medvue-integration/users/{$surgeon->getId()}/link", server: $this->auth($token));
        self::assertResponseStatusCodeSame(404);
        self::assertSame('not_linked', $this->json()['error']['code']);
    }

    public function test_admin_revokes_another_users_link(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $admin = $this->makeUser('ROLE_ADMIN');
        $this->persistLink($surgeon, 'link-admin-revoke');

        $this->client->request('DELETE', "/api/medvue-integration/users/{$surgeon->getId()}/link", server: $this->auth($this->login($admin)));

        self::assertResponseStatusCodeSame(204);
        $this->em->clear();
        $link = $this->em->getRepository(MedVueAccountLink::class)->findOneBy(['medvueLinkId' => 'link-admin-revoke']);
        self::assertFalse($link->isActive());
        self::assertSame($admin->getId(), $link->getRevokedBy()?->getId());
        self::assertSame(MedVueLinkRevocationSource::SURGICALHUB, $link->getRevokedVia());
    }

    public function test_revocation_requested_by_medvue_is_idempotent_and_audited(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $this->persistLink($surgeon, 'link-mv-revoke');

        $this->client->request('DELETE', '/api/integrations/medvue/v1/links/link-mv-revoke', server: $this->machineAuth());
        self::assertResponseStatusCodeSame(204);
        $this->client->request('DELETE', '/api/integrations/medvue/v1/links/link-mv-revoke', server: $this->machineAuth());
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/integrations/medvue/v1/links/link-mv-revoke/absences?from=2026-01-01&to=2026-12-31', server: $this->machineAuth());
        self::assertResponseStatusCodeSame(410);

        $audit = $this->auditFor($surgeon, UserAuditEventType::MEDVUE_UNLINKED);
        self::assertCount(1, $audit);
        self::assertSame('MEDVUE', $audit[0]->getPayload()['via']);
        self::assertSame($surgeon->getId(), $audit[0]->getActor()?->getId());

        $this->client->request('DELETE', '/api/integrations/medvue/v1/links/never-seen', server: $this->machineAuth());
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['apiVersion' => 1, 'error' => 'link_not_found'], $this->json());
    }

    public function test_relinking_after_revocation_with_the_same_link_id_reopens_reads(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $token = $this->login($surgeon);
        $this->persistLink($surgeon, 'link-same-pair');
        $this->client->request('DELETE', "/api/medvue-integration/users/{$surgeon->getId()}/link", server: $this->auth($token));
        self::assertResponseStatusCodeSame(204);

        // MedVue reconfirme la même paire avec le même linkId (contrat v1).
        MockHttpClientCallback::push($this->redeemOk('link-same-pair'));
        $this->postJson("/api/medvue-integration/users/{$surgeon->getId()}/link", ['code' => 'K7QM2XPA9DRT'], $token);
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/integrations/medvue/v1/links/link-same-pair/absences?from=2026-01-01&to=2026-12-31', server: $this->machineAuth());
        self::assertResponseIsSuccessful();
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM medvue_account_link WHERE medvue_link_id = ?', ['link-same-pair']));
    }

    public function test_not_configured_server_refuses_linking_cleanly(): void
    {
        // Couvert par MedVueLinkCodeRedeemerTest (configuration vide) ; ici on vérifie seulement
        // que l'état publié l'annonce, pour que l'interface puisse masquer le formulaire.
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $this->client->request('GET', "/api/medvue-integration/users/{$surgeon->getId()}/link", server: $this->auth($this->login($surgeon)));
        self::assertSame(['configured' => true, 'linked' => false, 'linkedAt' => null, 'linkedBy' => null], $this->json());
    }

    // ── Helpers ────────────────────────────────────────────────────────────────────

    private function makeUser(?string $role, ?string $firstname = null, ?string $lastname = null): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('medvue-' . bin2hex(random_bytes(5)) . '@surgicalhub.test');
        $user->setRoles($role === null ? [] : [$role]);
        $user->setActive(true);
        $user->setFirstname($firstname);
        $user->setLastname($lastname);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();
        $this->userIds[] = $user->getId();

        return $user;
    }

    private function login(User $user): string
    {
        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, (string) $this->client->getResponse()->getContent());

        return $data['token'];
    }

    private function auth(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    private function machineAuth(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . self::MACHINE_SECRET];
    }

    private function postJson(string $url, array $payload, ?string $token): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'] + ($token !== null ? $this->auth($token) : []);
        $this->client->request('POST', $url, server: $server, content: json_encode($payload));
    }

    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function redeemOk(string $linkId): MockResponse
    {
        return new MockResponse(json_encode(['linkId' => $linkId, 'linkedAt' => '2026-10-10T08:00:00+00:00']), ['http_code' => 200]);
    }

    private function persistLink(User $user, string $linkId): MedVueAccountLink
    {
        $link = new MedVueAccountLink($user, $linkId, $user, new \DateTimeImmutable());
        $this->em->persist($link);
        $this->em->flush();

        return $link;
    }

    private function absence(User $user, string $start, string $end, ?string $reason): Absence
    {
        $absence = new Absence();
        $absence->setUser($user);
        $absence->setCreatedBy($user);
        $absence->setDateStart(new \DateTimeImmutable($start));
        $absence->setDateEnd(new \DateTimeImmutable($end));
        $absence->setReason($reason);
        $this->em->persist($absence);
        $this->em->flush();

        return $absence;
    }

    private function activeLink(User $user): ?MedVueAccountLink
    {
        $this->em->clear();

        return $this->em->getRepository(MedVueAccountLink::class)->findOneBy(['activeUserId' => $user->getId()]);
    }

    /** @return list<UserAuditEvent> */
    private function auditFor(User $target, UserAuditEventType $type): array
    {
        return $this->em->getRepository(UserAuditEvent::class)->findBy(['targetUser' => $target->getId(), 'eventType' => $type], ['id' => 'ASC']);
    }
}
