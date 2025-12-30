<?php

namespace App\EventListener;

use App\Entity\User;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;

final class AuthenticationSuccessListener
{
    public function __construct(
        private RefreshTokenManagerInterface $refreshTokenManager,
    ) {}

    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        // Génère un refresh token
        $refreshToken = $this->refreshTokenManager->create();
        $refreshToken->setUsername($user->getUserIdentifier());
        $refreshToken->setRefreshToken(bin2hex(random_bytes(64)));
        $refreshToken->setValid((new \DateTimeImmutable())->modify('+30 days'));

        $this->refreshTokenManager->save($refreshToken);

        // Ajoute refresh_token à la réponse JSON
        $data = $event->getData();
        $data['refresh_token'] = $refreshToken->getRefreshToken();
        $event->setData($data);
    }
}
