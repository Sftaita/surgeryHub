<?php

namespace App\Controller\Api;

use App\Entity\PushSubscription;
use App\Entity\User;
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
    ) {}

    #[Route('/vapid-public-key', name: 'api_push_vapid_public_key', methods: ['GET'])]
    public function vapidPublicKey(): JsonResponse
    {
        return $this->json(['publicKey' => $this->vapidPublicKey]);
    }

    #[Route('/subscribe', name: 'api_push_subscribe', methods: ['POST'])]
    public function subscribe(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $body   = json_decode($request->getContent(), true);
        $endpoint = $body['endpoint'] ?? null;
        $keys     = $body['keys'] ?? [];
        $p256dh   = $keys['p256dh'] ?? null;
        $auth     = $keys['auth'] ?? null;

        if (!$endpoint || !$p256dh || !$auth) {
            return $this->json(['message' => 'Invalid subscription data'], Response::HTTP_BAD_REQUEST);
        }

        $repo     = $this->em->getRepository(PushSubscription::class);
        $existing = $repo->findOneBy(['endpoint' => $endpoint]);

        if ($existing) {
            $existing->setUser($user)->setPublicKey($p256dh)->setAuthToken($auth);
            $this->logger->info('push.subscription_updated', ['user_id' => $user->getId()]);
        } else {
            $sub = (new PushSubscription())
                ->setUser($user)
                ->setEndpoint($endpoint)
                ->setPublicKey($p256dh)
                ->setAuthToken($auth)
                ->setContentEncoding('aes128gcm');
            $this->em->persist($sub);
            $this->logger->info('push.subscription_created', ['user_id' => $user->getId()]);
        }

        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/unsubscribe', name: 'api_push_unsubscribe', methods: ['DELETE'])]
    public function unsubscribe(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $body     = json_decode($request->getContent(), true);
        $endpoint = $body['endpoint'] ?? null;

        if (!$endpoint) {
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
