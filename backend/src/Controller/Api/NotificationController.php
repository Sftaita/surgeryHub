<?php

namespace App\Controller\Api;

use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\PublicationChannel;
use App\Notification\NotificationPresenter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
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
 *
 * Centre de notifications — chaque item porte son texte, sa catégorie, sa cible et son
 * état métier calculés côté serveur (NotificationPresenter). `filter` : all (défaut) |
 * unread | action (« À traiter » = état métier ACTION_REQUIRED, jamais déduit de seenAt).
 */
#[Route('/api/notifications')]
final class NotificationController extends AbstractController
{
    private const DEFAULT_LIMIT = 30;
    private const MAX_LIMIT = 100;
    /** Fenêtre examinée pour « À traiter » : les notifications actionnables les plus récentes. */
    private const ACTION_WINDOW = 2000;
    private const FILTERS = ['all', 'unread', 'action'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationPresenter $presenter,
    ) {
    }

    #[Route('', name: 'api_notifications_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $limit = max(1, min(self::MAX_LIMIT, (int) $request->query->get('limit', self::DEFAULT_LIMIT)));
        $filter = (string) $request->query->get('filter', 'all');
        if (!in_array($filter, self::FILTERS, true)) {
            return $this->json(['error' => 'filter must be one of: ' . implode(', ', self::FILTERS)], 422);
        }

        $offset = max(0, (int) $request->query->get('offset', 0));

        $actionItems = $this->actionRequiredItems($user);
        if ($filter === 'action') {
            $items = array_slice($actionItems, $offset, $limit);
            $hasMore = count($actionItems) > $offset + $limit;
        } else {
            // One row more than asked: tells whether a next page exists, without a COUNT.
            $qb = $this->baseQuery($user)->orderBy('n.sentAt', 'DESC')->addOrderBy('n.id', 'DESC')
                ->setFirstResult($offset)->setMaxResults($limit + 1);
            if ($filter === 'unread') {
                $qb->andWhere('n.seenAt IS NULL');
            }
            $rows = $qb->getQuery()->getResult();
            $hasMore = count($rows) > $limit;
            $items = $this->presenter->presentMany(array_slice($rows, 0, $limit), $user);
        }

        return $this->json([
            'items' => $items,
            'hasMore' => $hasMore,
            'unreadCount' => $this->countUnread($user),
            'actionRequiredCount' => count($actionItems),
        ]);
    }

    #[Route('/{id}', name: 'api_notifications_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, #[CurrentUser] User $user): JsonResponse
    {
        return $this->json($this->presenter->presentMany([$this->ownedOr404($id, $user)], $user)[0]);
    }

    #[Route('/unread-count', name: 'api_notifications_unread_count', methods: ['GET'])]
    public function unreadCount(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json(['unreadCount' => $this->countUnread($user)]);
    }

    #[Route('/{id}/seen', name: 'api_notifications_mark_seen', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function markSeen(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $notification = $this->ownedOr404($id, $user);

        if ($notification->getSeenAt() === null) {
            $notification->setSeenAt(new \DateTimeImmutable());
            $this->em->flush();
        }

        return $this->json($this->presenter->presentMany([$notification], $user)[0]);
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
        return (int) $this->baseQuery($user)
            ->select('COUNT(n.id)')
            ->andWhere('n.seenAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<array<string, mixed>> presented items whose business state is ACTION_REQUIRED, most recent first */
    private function actionRequiredItems(User $user): array
    {
        $candidates = $this->baseQuery($user)
            ->andWhere('n.eventType IN (:types)')
            ->setParameter('types', NotificationPresenter::ACTIONABLE_TYPES)
            ->orderBy('n.sentAt', 'DESC')->addOrderBy('n.id', 'DESC')
            ->setMaxResults(self::ACTION_WINDOW)
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $this->presenter->presentMany($candidates, $user),
            static fn (array $item) => $item['businessState'] === NotificationPresenter::STATE_ACTION_REQUIRED,
        ));
    }

    private function baseQuery(User $user): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->select('n')
            ->from(NotificationEvent::class, 'n')
            ->where('n.user = :user')
            ->andWhere('n.channel = :channel')
            ->setParameter('user', $user)
            ->setParameter('channel', PublicationChannel::IN_APP);
    }

    private function ownedOr404(int $id, User $user): NotificationEvent
    {
        $notification = $this->em->getRepository(NotificationEvent::class)->find($id);
        if (!$notification instanceof NotificationEvent || $notification->getUser()?->getId() !== $user->getId()
            || $notification->getChannel() !== PublicationChannel::IN_APP) {
            throw $this->createNotFoundException('Notification introuvable.');
        }

        return $notification;
    }
}
