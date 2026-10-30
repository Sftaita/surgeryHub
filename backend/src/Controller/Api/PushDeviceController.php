<?php

namespace App\Controller\Api;

use App\Push\PushDeviceRenewalService;
use App\Push\PushReceiptService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * D-175 — routes appelées par le SERVICE WORKER, sans session utilisateur (firewall `push_device`,
 * aucun authentificateur JWT) : le push arrive aussi quand la page est fermée ou la session
 * expirée. Chacune a son propre mécanisme d'authentification dédié (voir les services) et un
 * plafond de débit GLOBAL : sans SYMFONY_TRUSTED_PROXIES (traefik → nginx → php en production),
 * getClientIp() rend l'adresse du proxy pour tout le monde — un plafond « par IP » serait en
 * réalité global, autant le dire. Le plafond protège la base, pas l'authentification (jeton de
 * 256 bits, clé d'accusé de 128 bits).
 */
#[Route('/api/push')]
class PushDeviceController extends AbstractController
{
    public function __construct(
        private readonly PushReceiptService $receipts,
        private readonly PushDeviceRenewalService $renewal,
        #[Autowire(service: 'limiter.push_device_api')]
        private readonly RateLimiterFactory $limiter,
    ) {
    }

    /**
     * Accusés {receipts: [{receipt, event: RECEIVED|DISPLAY_REQUESTED|DISPLAY_FAILED|OPENED, at?}]}.
     * Toujours 204 (clé inconnue comprise) : aucune information n'est rendue au client.
     */
    #[Route('/receipts', name: 'api_push_receipts', methods: ['POST'])]
    public function receipts(Request $request): Response
    {
        if (!$this->limiter->create('receipts')->consume()->isAccepted()) {
            return new JsonResponse(['code' => 'RATE_LIMITED'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        $body = json_decode($request->getContent(), true);
        $list = is_array($body) && is_array($body['receipts'] ?? null) ? array_values($body['receipts']) : null;
        if ($list === null) {
            return new JsonResponse(['message' => 'receipts must be a list'], Response::HTTP_BAD_REQUEST);
        }
        $this->receipts->record($list);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** Remplacement d'abonnement depuis `pushsubscriptionchange` (jeton d'appareil). */
    #[Route('/device/renew', name: 'api_push_device_renew', methods: ['POST'])]
    public function renew(Request $request): JsonResponse
    {
        if (!$this->limiter->create('renew')->consume()->isAccepted()) {
            return new JsonResponse(['code' => 'RATE_LIMITED'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        $body = json_decode($request->getContent(), true);
        $result = $this->renewal->renew(is_array($body) ? $body : []);

        return new JsonResponse($result['body'], $result['status']);
    }
}
