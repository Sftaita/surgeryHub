<?php

namespace App\Security\MedVueIntegration;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * D-140 — authentification machine-à-machine de MedVue sur `^/api/integrations/medvue/`.
 *
 * `Authorization: Bearer <secret MedVue→SurgicalHub>`. SurgicalHub ne stocke que l'empreinte
 * SHA-256 du secret (MEDVUE_INBOUND_TOKEN_SHA256 ; plusieurs empreintes séparées par des virgules
 * pendant une rotation), comparée en temps constant. Variable vide = toute requête refusée.
 * Le secret n'est jamais journalisé ; un JWT utilisateur présenté ici n'est qu'un secret invalide.
 */
final class MedVueIntegrationAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    /** @var list<string> */
    private readonly array $acceptedHashes;

    public function __construct(
        #[Autowire('%env(MEDVUE_INBOUND_TOKEN_SHA256)%')] string $acceptedHashes,
    ) {
        $this->acceptedHashes = array_values(array_filter(array_map(
            static fn (string $h): string => strtolower(trim($h)),
            explode(',', $acceptedHashes),
        ), static fn (string $h): bool => preg_match('/^[0-9a-f]{64}$/', $h) === 1));
    }

    public function supports(Request $request): ?bool
    {
        // Toujours : aucune requête de ce firewall ne passe sans secret valide.
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            throw new AuthenticationException('Missing bearer token.');
        }

        $presented = hash('sha256', substr($header, 7));
        $valid = false;
        foreach ($this->acceptedHashes as $expected) {
            // Pas de court-circuit : chaque empreinte configurée est comparée.
            $valid = hash_equals($expected, $presented) || $valid;
        }
        if (!$valid) {
            throw new AuthenticationException('Invalid integration token.');
        }

        return new SelfValidatingPassport(new UserBadge('medvue-integration', static fn () => new MedVueIntegrationClient()));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return self::unauthorized();
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return self::unauthorized();
    }

    private static function unauthorized(): JsonResponse
    {
        return new JsonResponse(['apiVersion' => 1, 'error' => 'unauthorized'], 401);
    }
}
