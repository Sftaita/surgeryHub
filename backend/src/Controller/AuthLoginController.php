<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AuthLoginController
{
    #[Route('/api/auth/login', name: 'api_auth_login', methods: ['POST'])]
    public function __invoke(): Response
    {
        // Cette méthode ne sera jamais appelée.
        // Le firewall "login" intercepte la requête avant.
        return new Response('', Response::HTTP_UNAUTHORIZED);
    }
}
