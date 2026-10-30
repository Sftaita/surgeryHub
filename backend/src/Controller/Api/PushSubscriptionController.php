<?php

namespace App\Controller\Api;

use App\Entity\PushSubscription;
use App\Entity\User;
use App\Push\PushDeliveryTracker;
use App\Push\PushSubscriptionSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/push')]
class PushSubscriptionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $vapidPublicKey,
        #[Autowire(service: 'monolog.logger.push')]
        private readonly LoggerInterface $logger,
        private readonly PushSubscriptionSyncService $sync,
        private readonly PushDeliveryTracker $tracker,
    ) {}

    #[Route('/vapid-public-key', name: 'api_push_vapid_public_key', methods: ['GET'])]
    public function vapidPublicKey(): JsonResponse
    {
        if ($this->vapidPublicKey === '') {
            $this->logger->error('push.vapid_not_configured');

            return $this->json(['message' => 'Push notifications are not configured on this server'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json(['publicKey' => $this->vapidPublicKey]);
    }

    /**
     * Idempotent upsert keyed by `endpoint` (one row per browser subscription).
     *
     * Three distinct cases, each logged distinctly (Lot 1 / audit PWA-push 24-07-2026):
     *  - endpoint unknown            → create.
     *  - endpoint owned by $user     → refresh keys, no-op if identical (idempotent resubscribe).
     *  - endpoint owned by another   → explicit, logged reassignment (never silent). Expected to
     *    become rare now that logout() detaches server-side first (see AuthContext.tsx); this
     *    remains the self-healing path for sessions that expired without an explicit logout.
     */
    #[Route('/subscribe', name: 'api_push_subscribe', methods: ['POST'])]
    public function subscribe(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $body     = json_decode($request->getContent(), true);
        $endpoint = $body['endpoint'] ?? null;
        $keys     = $body['keys'] ?? [];
        $p256dh   = is_array($keys) ? ($keys['p256dh'] ?? null) : null;
        $auth     = is_array($keys) ? ($keys['auth'] ?? null) : null;

        $error = PushSubscriptionSyncService::validateSubscription($endpoint, $p256dh, $auth);
        if ($error !== null) {
            return $this->json(['message' => $error], Response::HTTP_BAD_REQUEST);
        }

        // D-175 — un endpoint que le fournisseur a déclaré mort (404/410) n'est jamais réenregistré :
        // le renvoyer n'est pas une réparation. Le client doit se réabonner (POST /api/push/sync).
        if ($this->tracker->findInvalidation($endpoint) !== null) {
            $this->logger->info('push.subscribe_invalidated_endpoint', ['user_id' => $user->getId()]);

            return $this->json(['code' => 'PUSH_SUBSCRIPTION_INVALIDATED', 'message' => 'This push subscription is no longer valid: renew it'], Response::HTTP_CONFLICT);
        }

        $repo     = $this->em->getRepository(PushSubscription::class);
        $existing = $repo->findOneBy(['endpoint' => $endpoint]);

        if ($existing === null) {
            $sub = (new PushSubscription())
                ->setUser($user)
                ->setEndpoint($endpoint)
                ->setPublicKey($p256dh)
                ->setAuthToken($auth)
                ->setContentEncoding('aes128gcm');
            $this->em->persist($sub);
            $this->logger->info('push.subscription_created', ['user_id' => $user->getId()]);
        } elseif ($existing->getUser()?->getId() === $user->getId()) {
            $existing->setPublicKey($p256dh)->setAuthToken($auth);
            $this->logger->info('push.subscription_updated', ['user_id' => $user->getId()]);
        } else {
            $previousUserId = $existing->getUser()?->getId();
            $existing->setUser($user)->setPublicKey($p256dh)->setAuthToken($auth);
            $this->logger->warning('push.subscription_reassigned', [
                'from_user_id' => $previousUserId,
                'to_user_id'   => $user->getId(),
            ]);
        }

        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * D-175 — réconciliation navigateur ↔ serveur (voir PushSubscriptionSyncService et docs/api.md).
     * Corps : {endpoint, keys: {p256dh, auth}, swVersion?, receipts?, hasDeviceToken?, replacesEndpoint?}.
     */
    #[Route('/sync', name: 'api_push_sync', methods: ['POST'])]
    public function syncSubscription(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];
        $keys = is_array($body['keys'] ?? null) ? $body['keys'] : [];

        $error = PushSubscriptionSyncService::validateSubscription($body['endpoint'] ?? null, $keys['p256dh'] ?? null, $keys['auth'] ?? null);
        if ($error === null && isset($body['replacesEndpoint']) && (!is_string($body['replacesEndpoint']) || strlen($body['replacesEndpoint']) > PushSubscriptionSyncService::MAX_ENDPOINT_LENGTH)) {
            $error = 'Invalid subscription data: replacesEndpoint must be an endpoint';
        }
        if ($error !== null) {
            return $this->json(['message' => $error], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->sync->sync($user, $body + ['keys' => $keys]));
    }

    /**
     * Idempotent: succeeds whether or not the endpoint currently exists for this user.
     * Never deletes an endpoint belonging to another user (scoped by `user` in the query).
     */
    #[Route('/unsubscribe', name: 'api_push_unsubscribe', methods: ['DELETE'])]
    public function unsubscribe(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $body     = json_decode($request->getContent(), true);
        $endpoint = $body['endpoint'] ?? null;

        if (!is_string($endpoint) || $endpoint === '') {
            return $this->json(['message' => 'Missing endpoint'], Response::HTTP_BAD_REQUEST);
        }

        $sub = $this->em->getRepository(PushSubscription::class)->findOneBy([
            'endpoint' => $endpoint,
            'user'     => $user,
        ]);

        if ($sub) {
            $this->em->remove($sub);
            $this->em->flush();
            $this->logger->info('push.subscription_removed', ['user_id' => $user->getId()]);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
