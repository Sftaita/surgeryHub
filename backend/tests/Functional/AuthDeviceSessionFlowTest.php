<?php

namespace App\Tests\Functional;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Security\Session\DeviceSessionService;
use App\Service\UserAdministrationService;
use App\Tests\Support\MockHttpClientCallback;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-157 — persistent sliding device sessions, through the real HTTP kernel and a real database:
 * refresh with the (expired) access token still sent, sliding inactivity windows, rotation,
 * concurrency and lost responses, replay detection, logout per device, security revocations.
 */
#[IgnoreDeprecations]
final class AuthDeviceSessionFlowTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const PASSWORD = 'SessionTest123!';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private MockClock $clock;
    private string $email;
    /** @var list<string> */
    private array $emails = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clock = self::mockTime();
        $this->email = $this->createUser('ROLE_MANAGER');
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->clear();
            MockHttpClientCallback::reset();
            $this->em->getConnection()->executeStatement(
                'DELETE a FROM user_audit_event a JOIN user u ON u.id = a.actor_id OR u.id = a.target_user_id WHERE u.email IN (?)',
                [$this->emails],
                [ArrayParameterType::STRING],
            );
            foreach ($this->emails as $email) {
                $this->em->createQuery('DELETE FROM App\Entity\RefreshToken r WHERE r.username = :u')->setParameter('u', $email)->execute();
                $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
                if ($user !== null) {
                    $this->em->remove($user);
                }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    #[WithoutErrorHandler]
    public function testRefreshSucceedsWhileTheExpiredAccessTokenIsStillSentAndTheNewOneWorks(): void
    {
        $login = $this->login($this->email, true);
        $expired = static::getContainer()->get(JWTEncoderInterface::class)->encode([
            'username' => $this->email, 'roles' => ['ROLE_MANAGER'], 'iat' => time() - 7200, 'exp' => time() - 3600,
        ]);

        // The SPA sends its stored (expired) access token along: it must never prevent the refresh.
        $refreshed = $this->refresh($login['refresh_token'], $expired);
        self::assertSame(200, $refreshed['status']);
        $again = $this->refresh($refreshed['refresh_token'], 'not.a.jwt');
        self::assertSame(200, $again['status']);

        $this->client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$again['token']]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame($this->email, json_decode((string) $this->client->getResponse()->getContent(), true)['email']);
    }

    #[WithoutErrorHandler]
    public function testDailyUseSlidesTheWindowSoAnActiveUserIsNeverLoggedOut(): void
    {
        $token = $this->login($this->email, true)['refresh_token'];

        // 4 months of use, once every 25 days: each refresh gives a fresh 30-day window.
        for ($i = 0; $i < 5; ++$i) {
            $this->clock->modify('+25 days');
            $r = $this->refresh($token);
            self::assertSame(200, $r['status'], 'refresh #'.$i);
            $token = $r['refresh_token'];
        }

        $current = $this->row($token);
        self::assertEqualsWithDelta($this->clock->now()->getTimestamp() + DeviceSessionService::TTL_REMEMBER_ME, $current->getValid()->getTimestamp(), 2);
        self::assertTrue($current->isRememberMe());
        self::assertLessThanOrEqual(3, count($this->rows($this->email)), 'Rotated generations are pruned after 7 days.');
    }

    #[WithoutErrorHandler]
    public function testThirtyDaysWithoutAnyUseRequireANewLogin(): void
    {
        $token = $this->login($this->email, true)['refresh_token'];

        $this->clock->modify('+30 days +1 minute');

        self::assertSame(401, $this->refresh($token)['status']);
        self::assertCount(0, $this->rows($this->email), 'The expired session is removed.');
    }

    #[WithoutErrorHandler]
    public function testSharedDeviceSessionEndsAfterOneDayWithoutUse(): void
    {
        $token = $this->login($this->email, false)['refresh_token'];

        $this->clock->modify('+20 hours');
        $r = $this->refresh($token);
        self::assertSame(200, $r['status']);
        self::assertFalse($this->row($r['refresh_token'])->isRememberMe());

        $this->clock->modify('+25 hours');
        self::assertSame(401, $this->refresh($r['refresh_token'])['status']);
    }

    #[WithoutErrorHandler]
    public function testConcurrentRefreshesWithTheSameTokenShareOneSuccessor(): void
    {
        $token = $this->login($this->email, true)['refresh_token'];

        $first = $this->refresh($token);
        $this->clock->modify('+5 seconds');
        $second = $this->refresh($token); // second tab / second request, same old token

        self::assertSame(200, $second['status']);
        self::assertSame($first['refresh_token'], $second['refresh_token']);
        self::assertCount(2, $this->rows($this->email));
    }

    #[WithoutErrorHandler]
    public function testLostRefreshResponseIsRecoveredEvenAfterTheGraceWindow(): void
    {
        $token = $this->login($this->email, true)['refresh_token'];
        $lost = $this->refresh($token); // the response never reached the device (network cut)

        $this->clock->modify('+2 hours');
        $retry = $this->refresh($token);

        self::assertSame(200, $retry['status']);
        self::assertSame($lost['refresh_token'], $retry['refresh_token']);
    }

    #[WithoutErrorHandler]
    public function testReplayOfAnAlreadyRotatedTokenRevokesTheWholeSession(): void
    {
        $stolen = $this->login($this->email, true)['refresh_token'];
        $b = $this->refresh($stolen)['refresh_token'];
        $c = $this->refresh($b)['refresh_token']; // the legitimate device kept going

        $this->clock->modify('+10 minutes');
        self::assertSame(401, $this->refresh($stolen)['status']);
        self::assertSame(401, $this->refresh($c)['status'], 'The compromised session is revoked as a whole.');
        self::assertCount(0, $this->rows($this->email));
    }

    #[WithoutErrorHandler]
    public function testLogoutRevokesOnlyThisDeviceEvenWithAnExpiredAccessTokenSent(): void
    {
        $phone = $this->login($this->email, true)['refresh_token'];
        $desktop = $this->login($this->email, true)['refresh_token'];
        $phone = $this->refresh($phone)['refresh_token'];

        $this->client->request('POST', '/api/auth/logout', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer expired.or.not'],
            content: json_encode(['refresh_token' => $phone]));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        self::assertSame(401, $this->refresh($phone)['status'], 'Logged-out token cannot be reused.');
        self::assertSame(200, $this->refresh($desktop)['status'], 'The other device keeps its session.');
    }

    #[WithoutErrorHandler]
    public function testSuspensionRevokesEverySessionAndAnInactiveAccountCannotRefresh(): void
    {
        $one = $this->login($this->email, true)['refresh_token'];
        $this->login($this->email, false);
        $adminEmail = $this->createUser('ROLE_ADMIN');

        $users = $this->em->getRepository(User::class);
        static::getContainer()->get(UserAdministrationService::class)->suspendUser($users->findOneBy(['email' => $this->email]), $users->findOneBy(['email' => $adminEmail]));

        self::assertCount(0, $this->rows($this->email));
        self::assertSame(401, $this->refresh($one)['status']);

        // Account disabled by any other path: the refresh refuses it too and drops the session.
        $other = $this->createUser('ROLE_SURGEON');
        $token = $this->login($other, true)['refresh_token'];
        $this->em->createQuery('UPDATE App\Entity\User u SET u.active = false WHERE u.email = :e')->setParameter('e', $other)->execute();
        self::assertSame(401, $this->refresh($token)['status']);
        self::assertCount(0, $this->rows($other));
    }

    #[WithoutErrorHandler]
    public function testAnEmailChangeClosesTheSessionsOpenedUnderTheFormerEmail(): void
    {
        $token = $this->login($this->email, true)['refresh_token'];
        $other = $this->login($this->createUser('ROLE_SURGEON'), true)['refresh_token'];
        $newEmail = 'renamed-'.bin2hex(random_bytes(4)).'@surgicalhub.test';
        $this->emails[] = $newEmail;

        // What UserEmailChangeService::changeEmail() does on the session side (wiring: its unit test).
        $this->em->createQuery('UPDATE App\Entity\User u SET u.email = :n WHERE u.email = :o')->setParameter('n', $newEmail)->setParameter('o', $this->email)->execute();
        static::getContainer()->get(DeviceSessionService::class)->revokeAllForIdentifier($this->email);

        self::assertSame(401, $this->refresh($token)['status'], 'D-063 : reconnexion avec la nouvelle adresse.');
        self::assertSame(200, $this->refresh($other)['status'], 'Other users are untouched.');
        $this->login($newEmail, true);
    }

    #[WithoutErrorHandler]
    public function testTokensIssuedBeforeTheMigrationKeepWorkingAndJoinAFamily(): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $this->email]);
        $legacy = (new RefreshToken())->setRememberMe(true);
        $legacy->setRefreshToken(bin2hex(random_bytes(64)));
        $legacy->setUsername($this->email);
        $legacy->setValid(new \DateTime('+10 days'));
        $this->em->persist($legacy);
        $this->em->flush();
        self::assertNull($legacy->getFamilyId());
        unset($user);

        $r = $this->refresh((string) $legacy->getRefreshToken());

        self::assertSame(200, $r['status']);
        self::assertNotNull($this->row($r['refresh_token'])->getFamilyId());
        self::assertTrue($this->row($r['refresh_token'])->isRememberMe());
    }

    #[WithoutErrorHandler]
    public function testRefreshWithoutAnyTokenIsAClean401(): void
    {
        $this->client->request('POST', '/api/auth/refresh', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertSame(401, $this->refresh('unknown-token')['status']);
    }

    #[WithoutErrorHandler]
    public function testAccessTokensAreBoundToTheirSessionAndDieWithItBeforeExpiring(): void
    {
        $phone = $this->login($this->email, true);
        $desktop = $this->login($this->email, true);
        $payload = static::getContainer()->get(JWTEncoderInterface::class)->decode($phone['token']);
        self::assertSame($this->row($phone['refresh_token'])->getFamilyId(), $payload[DeviceSessionService::SESSION_CLAIM]);

        // Rotation keeps the session: the access token issued at login keeps working.
        $phone['refresh_token'] = $this->refresh($phone['refresh_token'])['refresh_token'];
        self::assertSame(200, $this->me($phone['token']));

        // Logout of the phone: its (unexpired) access token is refused at once, the desktop is untouched.
        $this->client->request('POST', '/api/auth/logout', server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['refresh_token' => $phone['refresh_token']]));
        self::assertSame(401, $this->me($phone['token']));
        self::assertSame(200, $this->me($desktop['token']));

        // Security revocation of every session (app:auth:revoke-sessions): no access token survives.
        $tablet = $this->login($this->email, false);
        static::getContainer()->get(DeviceSessionService::class)->revokeAllForUser($this->em->getRepository(User::class)->findOneBy(['email' => $this->email]));
        self::assertSame(401, $this->me($desktop['token']));
        self::assertSame(401, $this->me($tablet['token']));
        self::assertSame(401, $this->refresh($desktop['refresh_token'])['status']);
    }

    #[WithoutErrorHandler]
    public function testSuspensionAndReplayInvalidateUnexpiredAccessTokens(): void
    {
        $session = $this->login($this->email, true);
        $adminEmail = $this->createUser('ROLE_ADMIN');
        $users = $this->em->getRepository(User::class);
        static::getContainer()->get(UserAdministrationService::class)->suspendUser($users->findOneBy(['email' => $this->email]), $users->findOneBy(['email' => $adminEmail]));
        self::assertSame(401, $this->me($session['token']), 'Suspended: refused on the very next request.');

        // Replay of a stolen refresh token: the thief's access token dies with the session.
        $other = $this->createUser('ROLE_SURGEON');
        $stolen = $this->login($other, true)['refresh_token'];
        $thief = $this->refresh($stolen);
        $this->refresh($thief['refresh_token']); // the legitimate device kept going
        $this->clock->modify('+10 minutes');
        self::assertSame(200, $this->me($thief['token']));
        self::assertSame(401, $this->refresh($stolen)['status']);
        self::assertSame(401, $this->me($thief['token']));
    }

    #[WithoutErrorHandler]
    public function testAccessTokensIssuedBeforeTheSessionClaimStillWorkUntilTheyExpire(): void
    {
        $encoder = static::getContainer()->get(JWTEncoderInterface::class);
        $legacy = $encoder->encode(['username' => $this->email, 'roles' => ['ROLE_MANAGER'], 'iat' => time(), 'exp' => time() + 3600]);
        self::assertSame(200, $this->me($legacy));

        $unknownSession = $encoder->encode([
            'username' => $this->email, 'roles' => ['ROLE_MANAGER'], 'iat' => time(), 'exp' => time() + 3600,
            DeviceSessionService::SESSION_CLAIM => 'no-such-session',
        ]);
        self::assertSame(401, $this->me($unknownSession));
    }

    #[WithoutErrorHandler]
    public function testGoogleLoginOpensAPersistentSessionForAnExistingActiveAccountOnly(): void
    {
        $google = static fn (array $claims) => MockHttpClientCallback::push(new MockResponse(json_encode($claims + [
            'aud' => 'test-client.apps.googleusercontent.com', 'iss' => 'https://accounts.google.com',
            'exp' => (string) (time() + 600), 'email_verified' => 'true', 'sub' => 'google-sub-1',
        ]), ['http_code' => 200]));

        $google(['email' => strtoupper($this->email)]);
        $data = $this->google(true);
        self::assertSame(200, $data['status']);
        self::assertTrue($this->row($data['refresh_token'])->isRememberMe());
        self::assertSame(200, $this->me($data['token']));
        self::assertSame(200, $this->refresh($data['refresh_token'])['status']);

        // Unknown address: never an account creation.
        $unknown = 'google-unknown-'.bin2hex(random_bytes(4)).'@surgicalhub.test';
        $google(['email' => $unknown]);
        self::assertSame(401, $this->google(true)['status']);
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => $unknown]));

        // Token for another application, foreign issuer, expired, unverified email: refused.
        foreach ([['aud' => 'other-app.apps.googleusercontent.com'], ['iss' => 'https://evil.example'],
            ['exp' => (string) (time() - 5)], ['email_verified' => 'false']] as $claims) {
            $google($claims + ['email' => $this->email]);
            self::assertSame(401, $this->google(true)['status'], (string) json_encode($claims));
        }

        // Google itself rejects the token; Google unreachable.
        MockHttpClientCallback::push(new MockResponse('{"error":"invalid_token"}', ['http_code' => 400]));
        self::assertSame(401, $this->google(true)['status']);
        MockHttpClientCallback::push(new MockResponse('', ['http_code' => 503]));
        self::assertSame(503, $this->google(true)['status']);

        $this->em->createQuery('UPDATE App\Entity\User u SET u.active = false WHERE u.email = :e')->setParameter('e', $this->email)->execute();
        $this->em->clear();
        $google(['email' => $this->email]);
        self::assertSame(401, $this->google(true)['status'], 'Suspended account.');
    }

    /** @return array{status: int, token?: string, refresh_token?: string} */
    private function google(bool $rememberMe): array
    {
        $this->client->request('POST', '/api/auth/google', server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['credential' => 'fake-id-token', 'rememberMe' => $rememberMe]));
        $response = $this->client->getResponse();

        return ['status' => $response->getStatusCode()] + (json_decode((string) $response->getContent(), true) ?? []);
    }

    private function me(string $accessToken): int
    {
        $this->client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken]);

        return $this->client->getResponse()->getStatusCode();
    }

    /** @return array{status: int, token?: string, refresh_token?: string} */
    private function login(string $email, bool $rememberMe): array
    {
        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $email, 'password' => self::PASSWORD, 'rememberMe' => $rememberMe]));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return ['status' => 200] + json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return array{status: int, token?: string, refresh_token?: string} */
    private function refresh(string $refreshToken, ?string $bearer = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($bearer !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearer;
        }
        $this->client->request('POST', '/api/auth/refresh', server: $server, content: json_encode(['refresh_token' => $refreshToken]));
        $response = $this->client->getResponse();

        return ['status' => $response->getStatusCode()] + (json_decode((string) $response->getContent(), true) ?? []);
    }

    private function row(string $token): RefreshToken
    {
        $this->em->clear();
        $row = $this->em->getRepository(RefreshToken::class)->findOneBy(['refreshToken' => $token]);
        self::assertNotNull($row);

        return $row;
    }

    /** @return list<RefreshToken> */
    private function rows(string $email): array
    {
        $this->em->clear();

        return $this->em->getRepository(RefreshToken::class)->findBy(['username' => $email]);
    }

    private function createUser(string $role): string
    {
        $email = 'device-session-'.bin2hex(random_bytes(5)).'@surgicalhub.test';
        $this->emails[] = $email;
        $user = (new User())->setEmail($email)->setFirstname('Session')->setLastname('Test')->setRoles([$role])->setActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();

        return $email;
    }
}
