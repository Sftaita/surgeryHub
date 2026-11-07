<?php

namespace App\Controller\Api;

use App\Repository\MedVueAccountLinkRepository;
use App\Security\Voter\MedVueIntegrationVoter;
use App\Service\MedVue\MedVueAbsenceExportService;
use App\Service\MedVue\MedVueAccountLinkService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * D-140 — API machine-à-machine MedVue, contrat v1 (docs/api.md « Intégration MedVue »).
 *
 * Firewall `medvue_integration` (secret Bearer MedVue → SurgicalHub, jamais un JWT) +
 * MedVueIntegrationVoter::MACHINE_READ. Lecture seule sur les congés : la seule écriture
 * possible est la révocation de la liaison technique elle-même (DELETE), qui ne touche
 * aucune absence.
 *
 * Erreurs de liaison distinguées (MedVue ne révoque que sur ces corps JSON exacts) :
 * 404 link_not_found (jamais connu), 410 link_revoked (connu, plus aucune ligne active).
 */
#[Route('/api/integrations/medvue/v1')]
final class MedVueIntegrationApiController extends AbstractController
{
    private const API_VERSION = 1;

    public function __construct(
        private readonly MedVueAccountLinkRepository $links,
        private readonly MedVueAbsenceExportService $export,
        private readonly MedVueAccountLinkService $linkService,
        #[Autowire(service: 'limiter.medvue_integration_api')] private readonly RateLimiterFactory $apiLimiter,
    ) {}

    #[Route('/links/{linkId}/absences', name: 'api_medvue_integration_absences', methods: ['GET'])]
    public function absences(string $linkId, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(MedVueIntegrationVoter::MACHINE_READ);
        if ($limited = $this->rateLimited()) {
            return $limited;
        }

        $link = $this->links->findActiveByLinkId($linkId);
        if ($link === null) {
            return $this->linkError($linkId);
        }

        // all() plutôt que get() : un paramètre tableau (from[]=…) est une fenêtre invalide (400
        // du contrat), pas une erreur générique de requête.
        $query = $request->query->all();
        $from = self::parseDate($query['from'] ?? null);
        $to = self::parseDate($query['to'] ?? null);
        if ($from === null || $to === null || $to < $from || $from->diff($to)->days > MedVueAbsenceExportService::MAX_WINDOW_DAYS) {
            return $this->error('invalid_window', 400);
        }

        try {
            $absences = $this->export->snapshot($link->getUser(), $from, $to);
        } catch (\OverflowException) {
            return $this->error('window_too_large', 422);
        }

        $response = $this->json([
            'apiVersion' => self::API_VERSION,
            'linkId' => $link->getMedvueLinkId(),
            'window' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'complete' => true,
            'absences' => $absences,
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** Dissociation demandée par MedVue. Idempotente ; ne touche aucune absence. */
    #[Route('/links/{linkId}', name: 'api_medvue_integration_link_delete', methods: ['DELETE'])]
    public function revoke(string $linkId): Response
    {
        $this->denyAccessUnlessGranted(MedVueIntegrationVoter::MACHINE_READ);
        if ($limited = $this->rateLimited()) {
            return $limited;
        }

        $link = $this->links->findActiveByLinkId($linkId);
        if ($link !== null) {
            $this->linkService->revokeFromMedVue($link);

            return new Response(null, 204);
        }

        // Déjà révoquée : succès idempotent. Jamais connue : 404.
        return $this->links->hasAnyWithLinkId($linkId) ? new Response(null, 204) : $this->linkError($linkId);
    }

    private function linkError(string $linkId): JsonResponse
    {
        return $this->links->hasAnyWithLinkId($linkId)
            ? $this->error('link_revoked', 410)
            : $this->error('link_not_found', 404);
    }

    private function rateLimited(): ?JsonResponse
    {
        $limit = $this->apiLimiter->create('medvue')->consume();
        if ($limit->isAccepted()) {
            return null;
        }

        $response = $this->error('rate_limited', 429);
        $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));

        return $response;
    }

    private function error(string $code, int $status): JsonResponse
    {
        return $this->json(['apiVersion' => self::API_VERSION, 'error' => $code], $status);
    }

    private static function parseDate(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return $date !== false && $date->format('Y-m-d') === $raw ? $date : null;
    }
}
