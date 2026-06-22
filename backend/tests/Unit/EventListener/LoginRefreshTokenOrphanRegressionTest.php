<?php

namespace App\Tests\Unit\EventListener;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\EventListener\AuthenticationSuccessListener;
use Gesdinet\JWTRefreshTokenBundle\EventListener\AttachRefreshTokenOnSuccessListener;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGenerator;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Request\Extractor\ChainExtractor;
use Gesdinet\JWTRefreshTokenBundle\Request\Extractor\RequestBodyExtractor;
use Gesdinet\JWTRefreshTokenBundle\Request\Extractor\RequestCookieExtractor;
use Gesdinet\JWTRefreshTokenBundle\Request\Extractor\RequestParameterExtractor;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reproduit le pipeline réel de l'événement lexik_jwt_authentication.on_authentication_success
 * tel que vu par `bin/console debug:event-dispatcher` :
 *
 *   #1 App\EventListener\AuthenticationSuccessListener::onAuthenticationSuccess()
 *   #2 Gesdinet\JWTRefreshTokenBundle\EventListener\AttachRefreshTokenOnSuccessListener::attachRefreshToken()
 *
 * Les deux listeners réels (pas de mock) sont câblés sur un EventDispatcher réel, dans cet
 * ordre, pour prouver qu'un login ne crée bien qu'UN SEUL refresh token persistant en base et
 * que celui renvoyé au client est bien celui-là (pas un second créé par le listener du bundle).
 */
final class LoginRefreshTokenOrphanRegressionTest extends TestCase
{
    private function buildGesdinetListener(RefreshTokenManagerInterface $manager, RequestStack $requestStack): AttachRefreshTokenOnSuccessListener
    {
        $chain = new ChainExtractor();
        $chain->addExtractor(new RequestBodyExtractor());
        $chain->addExtractor(new RequestParameterExtractor());
        $chain->addExtractor(new RequestCookieExtractor());

        return new AttachRefreshTokenOnSuccessListener(
            $manager,
            2592000, // ttl global du bundle (30 jours) — doit être ignoré au login
            $requestStack,
            'refresh_token',
            false, // single_use
            new RefreshTokenGenerator($manager),
            $chain,
            [],
        );
    }

    public function testLoginCreatesExactlyOneUsableRefreshToken(): void
    {
        $request = Request::create('/api/auth/login', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'user@example.com',
            'password' => 'secret',
            'rememberMe' => false,
        ]));
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $createdTokens = [];
        $manager = $this->createMock(RefreshTokenManagerInterface::class);
        $manager->method('save')->willReturnCallback(function (RefreshToken $token) use (&$createdTokens) {
            $createdTokens[] = $token;
        });
        $manager->method('getClass')->willReturn(RefreshToken::class);

        $ourListener = new AuthenticationSuccessListener($manager, new RefreshTokenGenerator($manager), $requestStack, new NullLogger());
        $gesdinetListener = $this->buildGesdinetListener($manager, $requestStack);

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            'lexik_jwt_authentication.on_authentication_success',
            [$ourListener, 'onAuthenticationSuccess'],
            0
        );
        $dispatcher->addListener(
            'lexik_jwt_authentication.on_authentication_success',
            [$gesdinetListener, 'attachRefreshToken'],
            0
        );

        $user = (new User())->setEmail('user@example.com');
        $event = new AuthenticationSuccessEvent(['token' => 'jwt'], $user, new Response());

        $dispatcher->dispatch($event, 'lexik_jwt_authentication.on_authentication_success');

        // Un seul refresh token a été persisté en base pour ce login.
        self::assertCount(1, $createdTokens, 'Exactement un refresh token doit être créé par login (pas un orphelin du listener Gesdinet).');

        // Le refresh token renvoyé au client est bien celui créé par notre listener,
        // avec le TTL court attendu (rememberMe=false), pas le TTL global du bundle (30 jours).
        $returnedToken = $event->getData()['refresh_token'];
        self::assertSame($createdTokens[0]->getRefreshToken(), $returnedToken);

        $expectedValid = (new \DateTimeImmutable())->modify('+'.AuthenticationSuccessListener::TTL_DEFAULT.' seconds');
        self::assertEqualsWithDelta($expectedValid->getTimestamp(), $createdTokens[0]->getValid()->getTimestamp(), 5);
    }

    public function testLoginWithRememberMeStillCreatesExactlyOneTokenWithLongTtl(): void
    {
        $request = Request::create('/api/auth/login', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'user@example.com',
            'password' => 'secret',
            'rememberMe' => true,
        ]));
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $createdTokens = [];
        $manager = $this->createMock(RefreshTokenManagerInterface::class);
        $manager->method('save')->willReturnCallback(function (RefreshToken $token) use (&$createdTokens) {
            $createdTokens[] = $token;
        });
        $manager->method('getClass')->willReturn(RefreshToken::class);

        $ourListener = new AuthenticationSuccessListener($manager, new RefreshTokenGenerator($manager), $requestStack, new NullLogger());
        $gesdinetListener = $this->buildGesdinetListener($manager, $requestStack);

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener('lexik_jwt_authentication.on_authentication_success', [$ourListener, 'onAuthenticationSuccess'], 0);
        $dispatcher->addListener('lexik_jwt_authentication.on_authentication_success', [$gesdinetListener, 'attachRefreshToken'], 0);

        $user = (new User())->setEmail('user@example.com');
        $event = new AuthenticationSuccessEvent(['token' => 'jwt'], $user, new Response());

        $dispatcher->dispatch($event, 'lexik_jwt_authentication.on_authentication_success');

        self::assertCount(1, $createdTokens);
        self::assertTrue($createdTokens[0]->isRememberMe());

        $returnedToken = $event->getData()['refresh_token'];
        self::assertSame($createdTokens[0]->getRefreshToken(), $returnedToken);

        $expectedValid = (new \DateTimeImmutable())->modify('+'.AuthenticationSuccessListener::TTL_REMEMBER_ME.' seconds');
        self::assertEqualsWithDelta($expectedValid->getTimestamp(), $createdTokens[0]->getValid()->getTimestamp(), 5);
    }

    public function testRefreshCallReusesSameTokenWithoutCreatingAnother(): void
    {
        $request = Request::create('/api/auth/refresh', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'refresh_token' => 'existing-token-value',
        ]));
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $createdTokens = [];
        $manager = $this->createMock(RefreshTokenManagerInterface::class);
        $manager->expects(self::never())->method('create');
        $manager->method('save')->willReturnCallback(function (RefreshToken $token) use (&$createdTokens) {
            $createdTokens[] = $token;
        });

        $ourListener = new AuthenticationSuccessListener($manager, new RefreshTokenGenerator($manager), $requestStack, new NullLogger());
        $gesdinetListener = $this->buildGesdinetListener($manager, $requestStack);

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener('lexik_jwt_authentication.on_authentication_success', [$ourListener, 'onAuthenticationSuccess'], 0);
        $dispatcher->addListener('lexik_jwt_authentication.on_authentication_success', [$gesdinetListener, 'attachRefreshToken'], 0);

        $user = (new User())->setEmail('user@example.com');
        $event = new AuthenticationSuccessEvent(['token' => 'jwt'], $user, new Response());

        $dispatcher->dispatch($event, 'lexik_jwt_authentication.on_authentication_success');

        self::assertCount(0, $createdTokens, 'Un refresh ne doit créer aucun nouveau refresh token en base.');
        self::assertSame('existing-token-value', $event->getData()['refresh_token']);
    }
}
