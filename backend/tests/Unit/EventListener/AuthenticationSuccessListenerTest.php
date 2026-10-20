<?php

namespace App\Tests\Unit\EventListener;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\EventListener\AuthenticationSuccessListener;
use App\Repository\UserRepository;
use App\Security\Session\DeviceSessionService;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGenerator;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
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
        // D-157 — the token is opened by DeviceSessionService (persist + flush through the EM).
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity) use (&$saved) {
            if ($entity instanceof RefreshToken) {
                $saved = $entity;
            }
        });
        $jwt = $this->createMock(JWTTokenManagerInterface::class);
        $jwt->method('createFromPayload')->willReturnCallback(static fn ($user, array $payload) => 'jwt-sid-'.$payload['sid']);
        $sessions = new DeviceSessionService($em, $generator, $this->createMock(UserRepository::class), new NullLogger(), $jwt);
        $listener = new AuthenticationSuccessListener($sessions, $requestStack, new NullLogger());

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
        // D-157 — the access token returned is bound to the device session just opened.
        self::assertSame('jwt-sid-'.$saved->getFamilyId(), $event->getData()['token']);
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

        $sessions = $this->createMock(DeviceSessionService::class);
        $sessions->expects(self::never())->method('open');

        $listener = new AuthenticationSuccessListener($sessions, $requestStack, new NullLogger());

        $originalData = ['token' => 'jwt'];
        $event = new AuthenticationSuccessEvent($originalData, (new User())->setEmail('user@example.com'), new Response());
        $listener->onAuthenticationSuccess($event);

        // Le listener ne doit pas toucher à la réponse lors d'un refresh :
        // c'est le listener du bundle Gesdinet qui réinjecte le même refresh_token.
        self::assertSame($originalData, $event->getData());
    }
}
