<?php

namespace App\Controller\Api;

use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\PublicationChannel;
use App\Service\NotificationTargetResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Notifications internes (IN_APP) de l'utilisateur courant. Avant ce contrôleur,
 * `NotificationEvent` était écrit en base (voir NotificationService) mais jamais lu ni
 * marqué "vu" par aucune API — l'historique affiché côté frontend (cloche, écran
 * Notifications) ne provenait que d'un cache local alimenté par le service worker,
 * perdu au changement d'appareil/navigateur et jamais synchronisé avec `seenAt`.
 *
 * Toujours scopé à `#[CurrentUser]`, comme PushSubscriptionController — pas de voter
 * dédié nécessaire, une notification n'appartient qu'à son destinataire.
 */
#[Route('/api/notifications')]
final class NotificationController extends AbstractController
{
    private const DEFAULT_LIMIT = 30;
    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationTargetResolver $targetResolver,
    ) {
    }

    #[Route('', name: 'api_notifications_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $limit = max(1, min(self::MAX_LIMIT, (int) $request->query->get('limit', self::DEFAULT_LIMIT)));

        $items = $this->em->createQueryBuilder()
            ->select('n')
            ->from(NotificationEvent::class, 'n')
            ->where('n.user = :user')
            ->andWhere('n.channel = :channel')
            ->setParameter('user', $user)
            ->setParameter('channel', PublicationChannel::IN_APP)
            ->orderBy('n.sentAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $this->json([
            'items' => array_map($this->toArray(...), $items),
            'unreadCount' => $this->countUnread($user),
        ]);
    }

    #[Route('/unread-count', name: 'api_notifications_unread_count', methods: ['GET'])]
    public function unreadCount(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json(['unreadCount' => $this->countUnread($user)]);
    }

    #[Route('/{id}/seen', name: 'api_notifications_mark_seen', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function markSeen(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $notification = $this->em->getRepository(NotificationEvent::class)->find($id);

        if (!$notification || $notification->getUser()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Notification introuvable.');
        }

        if ($notification->getSeenAt() === null) {
            $notification->setSeenAt(new \DateTimeImmutable());
            $this->em->flush();
        }

        return $this->json($this->toArray($notification));
    }

    #[Route('/mark-all-seen', name: 'api_notifications_mark_all_seen', methods: ['POST'])]
    public function markAllSeen(#[CurrentUser] User $user): JsonResponse
    {
        $updated = $this->em->createQueryBuilder()
            ->update(NotificationEvent::class, 'n')
            ->set('n.seenAt', ':now')
            ->where('n.user = :user')
            ->andWhere('n.channel = :channel')
            ->andWhere('n.seenAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user)
            ->setParameter('channel', PublicationChannel::IN_APP)
            ->getQuery()
            ->execute();

        return $this->json(['updated' => $updated]);
    }

    private function countUnread(User $user): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(n.id)')
            ->from(NotificationEvent::class, 'n')
            ->where('n.user = :user')
            ->andWhere('n.channel = :channel')
            ->andWhere('n.seenAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('channel', PublicationChannel::IN_APP)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function toArray(NotificationEvent $n): array
    {
        $recipient = $n->getUser();
        $type = NotificationType::tryFrom($n->getEventType());
        $targetUrl = ($type !== null && $recipient !== null)
            ? $this->targetResolver->resolve($type, $n->getMission(), $recipient)
            : null;

        return [
            'id' => $n->getId(),
            'eventType' => $n->getEventType(),
            'missionId' => $n->getMission()?->getId(),
            // Point 4 (audit UX) — cible de navigation calculée côté serveur, jamais
            // reconstruite côté frontend à partir de missionId/eventType. null = non
            // actionnable (purement informatif, ou pas de cible connue).
            'targetUrl' => $targetUrl,
            'payload' => $n->getPayload(),
            'sentAt' => $n->getSentAt()?->format(DATE_ATOM),
            'seenAt' => $n->getSeenAt()?->format(DATE_ATOM),
        ];
    }
}
