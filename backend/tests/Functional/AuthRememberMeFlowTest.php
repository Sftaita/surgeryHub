<?php

namespace App\Tests\Functional;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\EventListener\AuthenticationSuccessListener;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Test fonctionnel de bout en bout (vrai noyau HTTP Symfony, vraie base de test
 * `*_test`) de la chaîne login / refresh / logout avec "Se souvenir de moi".
 *
 * Contrairement aux tests unitaires (AuthenticationSuccessListenerTest,
 * LoginRefreshTokenOrphanRegressionTest) qui câblent les listeners directement,
 * ce test passe par le vrai client HTTP Symfony : firewalls, listeners (le nôtre
 * ET celui de Gesdinet), Doctrine, tout le pipeline réel.
 *
 * #[IgnoreDeprecations] : ce test est le seul de la suite à booter un vrai noyau
 * HTTP et à traverser du code vendor non lié à remember-me (mapping Doctrine de
 * SiteMembership, Request::get() interne à l'extracteur Gesdinet) — déclenchant
 * des dépréciations préexistantes, hors périmètre de cette fonctionnalité.
 * #[WithoutErrorHandler] : WebTestCase installe son propre gestionnaire
 * d'erreurs/exceptions pour la durée de la requête HTTP simulée ; PHPUnit le
 * signale comme "risky" par défaut, ce qui est un faux positif ici.
 */
#[IgnoreDeprecations]
final class AuthRememberMeFlowTest extends WebTestCase
{
    private const PASSWORD = 'SmokeTest123!';

    private EntityManagerInterface $em;
    private string $email;

    protected function setUp(): void
    {
        parent::setUp();

        $this->email = 'remember-me-functional-'.bin2hex(random_bytes(4)).'@surgicalhub.test';

        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        // Nettoyage total : l'utilisateur de test et tous ses refresh tokens,
        // pour ne laisser aucune trace en base (le test ne doit dépendre
        // d'aucun état existant, ni en laisser).
        if (isset($this->em) && $this->em->isOpen()) {
            $tokens = $this->em->getRepository(RefreshToken::class)->findBy(['username' => $this->email]);
            foreach ($tokens as $token) {
                $this->em->remove($token);
            }

            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $this->email]);
            if (null !== $user) {
                $this->em->remove($user);
            }

            $this->em->flush();
        }

        parent::tearDown();
    }

    private function createTestUser(): void
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($this->email);
        $user->setFirstname('Functional');
        $user->setLastname('Test');
        $user->setRoles(['ROLE_MANAGER']);
        $user->setActive(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));

        $this->em->persist($user);
        $this->em->flush();
    }

    private function findRefreshTokens(): array
    {
        $this->em->clear();

        return $this->em->getRepository(RefreshToken::class)->findBy(['username' => $this->email]);
    }

    #[WithoutErrorHandler]
    public function testLoginWithoutRememberMeIssuesExactlyOneShortLivedRefreshToken(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->createTestUser();

        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'email' => $this->email,
                'password' => self::PASSWORD,
                'rememberMe' => false,
            ])
        );

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $data = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('refresh_token', $data);
        self::assertNotEmpty($data['refresh_token']);

        $tokens = $this->findRefreshTokens();
        self::assertCount(1, $tokens, 'Exactement un refresh token doit être créé en base pour ce login (pas d\'orphelin).');

        $token = $tokens[0];
        self::assertSame($data['refresh_token'], $token->getRefreshToken());
        self::assertFalse($token->isRememberMe());

        $expected = (new \DateTimeImmutable())->modify('+'.AuthenticationSuccessListener::TTL_DEFAULT.' seconds');
        self::assertEqualsWithDelta($expected->getTimestamp(), $token->getValid()->getTimestamp(), 10);
    }

    #[WithoutErrorHandler]
    public function testLoginWithRememberMeIssuesExactlyOneLongLivedRefreshToken(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->createTestUser();

        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'email' => $this->email,
                'password' => self::PASSWORD,
                'rememberMe' => true,
            ])
        );

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $data = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('refresh_token', $data);

        $tokens = $this->findRefreshTokens();
        self::assertCount(1, $tokens, 'Exactement un refresh token doit être créé en base pour ce login (pas d\'orphelin).');

        $token = $tokens[0];
        self::assertSame($data['refresh_token'], $token->getRefreshToken());
        self::assertTrue($token->isRememberMe());

        $expected = (new \DateTimeImmutable())->modify('+'.AuthenticationSuccessListener::TTL_REMEMBER_ME.' seconds');
        self::assertEqualsWithDelta($expected->getTimestamp(), $token->getValid()->getTimestamp(), 10);
    }

    #[WithoutErrorHandler]
    public function testRefreshDoesNotCreateAnotherRefreshTokenAndLogoutInvalidatesIt(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->createTestUser();

        // 1) Login
        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'email' => $this->email,
                'password' => self::PASSWORD,
                'rememberMe' => false,
            ])
        );
        $loginData = json_decode((string) $client->getResponse()->getContent(), true);
        $refreshToken = $loginData['refresh_token'];

        self::assertCount(1, $this->findRefreshTokens());

        // 2) Refresh : ne doit créer aucun second refresh token, ni en base ni dans la réponse.
        $client->request(
            'POST',
            '/api/auth/refresh',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['refresh_token' => $refreshToken])
        );

        $refreshResponse = $client->getResponse();
        self::assertSame(Response::HTTP_OK, $refreshResponse->getStatusCode(), (string) $refreshResponse->getContent());

        $refreshData = json_decode((string) $refreshResponse->getContent(), true);
        self::assertSame($refreshToken, $refreshData['refresh_token'], 'Le refresh token ne doit pas être rotaté en V1.');

        $tokensAfterRefresh = $this->findRefreshTokens();
        self::assertCount(1, $tokensAfterRefresh, 'Un refresh ne doit créer aucun refresh token orphelin en base.');
        self::assertSame($refreshToken, $tokensAfterRefresh[0]->getRefreshToken());

        // 3) Logout : doit invalider/supprimer le refresh token en base.
        $client->request(
            'POST',
            '/api/auth/logout',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['refresh_token' => $refreshToken])
        );

        $logoutResponse = $client->getResponse();
        self::assertSame(Response::HTTP_OK, $logoutResponse->getStatusCode(), (string) $logoutResponse->getContent());

        self::assertCount(0, $this->findRefreshTokens(), 'Le refresh token doit être supprimé de la base après logout.');

        // 4) Refresh après logout avec le même token : doit échouer en 401.
        $client->request(
            'POST',
            '/api/auth/refresh',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['refresh_token' => $refreshToken])
        );

        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent()
        );
    }
}
