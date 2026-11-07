<?php

namespace App\EventListener;

use App\Entity\User;
use App\Security\Session\DeviceSessionService;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuthenticationSuccessListener
{
    private const LOGIN_PATH = '/api/auth/login';

    /** D-157 — fenêtres d'inactivité glissantes (voir DeviceSessionService). */
    public const TTL_DEFAULT = DeviceSessionService::TTL_DEFAULT; // 1 jour sans utilisation
    public const TTL_REMEMBER_ME = DeviceSessionService::TTL_REMEMBER_ME; // 30 jours sans utilisation

    public function __construct(
        private DeviceSessionService $sessions,
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

        // On ne crée un refresh token qu'au login (D-157 : le refresh a son propre contrôleur,
        // AuthSessionController ; la garde reste pour tout autre déclencheur de cet événement).
        if (null === $request || self::LOGIN_PATH !== $request->getPathInfo()) {
            return;
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $rememberMe = (bool) ($payload['rememberMe'] ?? false);

        // D-157 — ouvre une session d'appareil (famille de refresh tokens, rotation au refresh).
        $refreshToken = $this->sessions->open($user, $rememberMe);

        $this->logger->info('Connexion réussie', [
            'username' => $user->getUserIdentifier(),
            'rememberMe' => $rememberMe,
        ]);

        $data = $event->getData();
        // D-157 — jeton d'accès lié à la session d'appareil (claim `sid`) : la révocation de la
        // session l'invalide immédiatement (SessionAccessTokenListener).
        $data['token'] = $this->sessions->accessToken($user, $refreshToken);
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
