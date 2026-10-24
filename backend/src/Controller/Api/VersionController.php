<?php

namespace App\Controller\Api;

use App\Release\ReleaseRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * D-162 — version de SurgicalHub affichée en bas des paramètres utilisateur (trois rôles).
 * Tout utilisateur authentifié (access_control ^/api) ; jamais mise en cache (une PWA ou un
 * proxy ne doit jamais afficher une version obsolète après un déploiement ou un rollback).
 * Ne renvoie qu'un tag et un SHA court — aucun secret, aucun chemin, aucune donnée serveur.
 */
final class VersionController extends AbstractController
{
    public function __construct(private readonly ReleaseRegistry $registry)
    {
    }

    #[Route('/api/version', name: 'api_version', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        try {
            $current = $this->registry->current();
        } catch (\RuntimeException) {
            // Registre illisible : jamais une version officielle supposée.
            $commit = $this->registry->runningCommit();
            $current = [
                'status' => $commit !== null ? ReleaseRegistry::STATUS_PENDING : ReleaseRegistry::STATUS_DEVELOPMENT,
                'version' => null,
                'commit' => $commit !== null ? substr($commit, 0, 7) : null,
            ];
        }

        $response = $this->json(['application' => 'SurgicalHub'] + $current);
        $response->headers->set('Cache-Control', 'no-store, max-age=0, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
