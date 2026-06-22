<?php

namespace App\EventListener;

use App\Entity\User;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuthenticationSuccessListener
{
    private const LOGIN_PATH = '/api/auth/login';

    public const TTL_DEFAULT = 86400; // 1 jour
    public const TTL_REMEMBER_ME = 2592000; // 30 jours

    public function __construct(
        private RefreshTokenManagerInterface $refreshTokenManager,
        private RefreshTokenGeneratorInterface $refreshTokenGenerator,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
    ) {}

    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();

        // Cet événement est aussi déclenché par le firewall refresh_jwt lors de
        // POST /api/auth/refresh : on ne doit créer un refresh token qu'au login,
        // sinon on accumule des refresh tokens orphelins en base à chaque refresh.
        if (null === $request || self::LOGIN_PATH !== $request->getPathInfo()) {
            return;
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $rememberMe = (bool) ($payload['rememberMe'] ?? false);
        $ttl = $rememberMe ? self::TTL_REMEMBER_ME : self::TTL_DEFAULT;

        $refreshToken = $this->refreshTokenGenerator->createForUserWithTtl($user, $ttl);
        $refreshToken->setRememberMe($rememberMe);

        $this->refreshTokenManager->save($refreshToken);

        $this->logger->info('Connexion réussie', [
            'username' => $user->getUserIdentifier(),
            'rememberMe' => $rememberMe,
        ]);

        $data = $event->getData();
        $data['refresh_token'] = $refreshToken->getRefreshToken();
        $event->setData($data);

        // Gesdinet\JWTRefreshTokenBundle\EventListener\AttachRefreshTokenOnSuccessListener
        // écoute aussi cet événement (priorité égale, exécuté après ce listener — voir
        // bin/console debug:event-dispatcher). À défaut de refresh_token dans la requête
        // courante, il en créerait un second avec le TTL global du bundle (en ignorant
        // rememberMe) et écraserait $data['refresh_token'] ci-dessus. En plaçant le token
        // qu'on vient de créer dans les attributs de la requête, son ExtractorInterface
        // (RequestParameterExtractor, via Request::get()) le retrouve et réutilise le
        // même token au lieu d'en créer un second.
        $request->attributes->set('refresh_token', $refreshToken->getRefreshToken());
    }
}
