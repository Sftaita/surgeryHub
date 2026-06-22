<?php

namespace App\Tests\Unit\EventListener;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\EventListener\AuthenticationSuccessListener;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGenerator;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticationSuccessListenerTest extends TestCase
{
    private function buildEvent(Request $request, array $data = ['token' => 'jwt']): AuthenticationSuccessEvent
    {
        $user = new User();
        $user->setEmail('user@example.com');

        return new AuthenticationSuccessEvent($data, $user, new Response());
    }

    private function buildListener(RequestStack $requestStack): array
    {
        $manager = $this->createMock(RefreshTokenManagerInterface::class);
        $manager->method('get')->willReturn(null);
        $manager->method('getClass')->willReturn(RefreshToken::class);
        $saved = null;
        $manager->method('save')->willReturnCallback(function (RefreshToken $token) use (&$saved) {
            $saved = $token;
        });

        $generator = new RefreshTokenGenerator($manager);
        $listener = new AuthenticationSuccessListener($manager, $generator, $requestStack, new NullLogger());

        return [$listener, $manager, function () use (&$saved) {
            return $saved;
        }];
    }

    public function testLoginWithoutRememberMeIssuesShortLivedRefreshToken(): void
    {
        $request = Request::create('/api/auth/login', 'POST', [], [], [], [], json_encode([
            'email' => 'user@example.com',
            'password' => 'secret',
        ]));
        $requestStack = new RequestStack();
        $requestStack->push($request);

        [$listener, , $getSaved] = $this->buildListener($requestStack);

        $event = $this->buildEvent($request);
        $listener->onAuthenticationSuccess($event);

        $saved = $getSaved();
        self::assertNotNull($saved, 'A refresh token should be created on login');
        self::assertFalse($saved->isRememberMe());

        $expectedValid = (new \DateTimeImmutable())->modify('+'.AuthenticationSuccessListener::TTL_DEFAULT.' seconds');
        self::assertEqualsWithDelta($expectedValid->getTimestamp(), $saved->getValid()->getTimestamp(), 5);

        self::assertArrayHasKey('refresh_token', $event->getData());
    }

    public function testLoginWithRememberMeIssuesLongLivedRefreshToken(): void
    {
        $request = Request::create('/api/auth/login', 'POST', [], [], [], [], json_encode([
            'email' => 'user@example.com',
            'password' => 'secret',
            'rememberMe' => true,
        ]));
        $requestStack = new RequestStack();
        $requestStack->push($request);

        [$listener, , $getSaved] = $this->buildListener($requestStack);

        $event = $this->buildEvent($request);
        $listener->onAuthenticationSuccess($event);

        $saved = $getSaved();
        self::assertNotNull($saved);
        self::assertTrue($saved->isRememberMe());

        $expectedValid = (new \DateTimeImmutable())->modify('+'.AuthenticationSuccessListener::TTL_REMEMBER_ME.' seconds');
        self::assertEqualsWithDelta($expectedValid->getTimestamp(), $saved->getValid()->getTimestamp(), 5);
    }

    public function testRememberMeAbsentDefaultsToFalse(): void
    {
        $request = Request::create('/api/auth/login', 'POST', [], [], [], [], json_encode([
            'email' => 'user@example.com',
            'password' => 'secret',
        ]));
        $requestStack = new RequestStack();
        $requestStack->push($request);

        [$listener, , $getSaved] = $this->buildListener($requestStack);

        $listener->onAuthenticationSuccess($this->buildEvent($request));

        self::assertFalse($getSaved()->isRememberMe());
    }

    public function testRefreshRequestDoesNotCreateANewRefreshToken(): void
    {
        $request = Request::create('/api/auth/refresh', 'POST', [], [], [], [], json_encode([
            'refresh_token' => 'some-existing-token',
        ]));
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $manager = $this->createMock(RefreshTokenManagerInterface::class);
        $manager->expects(self::never())->method('save');
        $generator = new RefreshTokenGenerator($manager);

        $listener = new AuthenticationSuccessListener($manager, $generator, $requestStack, new NullLogger());

        $originalData = ['token' => 'jwt'];
        $event = new AuthenticationSuccessEvent($originalData, (new User())->setEmail('user@example.com'), new Response());
        $listener->onAuthenticationSuccess($event);

        // Le listener ne doit pas toucher à la réponse lors d'un refresh :
        // c'est le listener du bundle Gesdinet qui réinjecte le même refresh_token.
        self::assertSame($originalData, $event->getData());
    }
}
