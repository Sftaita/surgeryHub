<?php

namespace App\Controller\Api;

use App\Entity\OutboundNotification;
use App\Entity\OutboundNotificationAttempt;
use App\Enum\OutboundNotificationChannel;
use App\Enum\OutboundNotificationStatus;
use App\Repository\OutboundNotificationRepository;
use App\Security\Voter\OutboundNotificationVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * D-084 — historique ADMIN des communications sortantes (Push + email). Réservé à
 * ROLE_ADMIN (OutboundNotificationVoter). Ne retourne jamais un endpoint Push, une clé,
 * un secret, un token, ni de donnée patient — le contenu renvoyé est exactement le
 * snapshot déjà nettoyé persisté par OutboundNotificationService.
 */
#[Route('/api/admin/outbound-notifications')]
final class AdminOutboundNotificationController extends AbstractController
{
    use AdminResponseTrait;

    public function __construct(
        private readonly OutboundNotificationRepository $notifications,
    ) {
    }

    /**
     * GET /api/admin/outbound-notifications
     *
     * Filtres : from, to (ISO 8601 ou Y-m-d), recipientUserId, channel (PUSH|EMAIL),
     * notificationType, status (QUEUED|SENT|FAILED|SKIPPED), missionId, search
     * (email/prénom/nom du destinataire, sujet, titre, corps texte, type), page, limit.
     */
    #[Route('', name: 'api_admin_outbound_notifications_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(OutboundNotificationVoter::LIST);

        $from = $this->parseDate($request->query->get('from'));
        $to = $this->parseDate($request->query->get('to'));
        $recipientUserId = $request->query->getInt('recipientUserId', 0) ?: null;
        $channelParam = $request->query->getString('channel', '');
        $channel = $channelParam !== '' ? $this->parseChannel($channelParam) : null;
        $notificationType = $request->query->getString('notificationType', '') ?: null;
        $statusParam = $request->query->getString('status', '');
        $status = $statusParam !== '' ? $this->parseStatus($statusParam) : null;
        $missionId = $request->query->getInt('missionId', 0) ?: null;
        $search = $request->query->getString('search', '') ?: null;
        $page = max($request->query->getInt('page', 1), 1);
        $limit = min(max($request->query->getInt('limit', 25), 1), 100);

        $result = $this->notifications->findForAdmin(
            from: $from,
            to: $to,
            recipientUserId: $recipientUserId,
            channel: $channel,
            notificationType: $notificationType,
            status: $status,
            missionId: $missionId,
            search: $search,
            page: $page,
            limit: $limit,
        );

        return $this->json([
            'items' => array_map([$this, 'toListPayload'], $result['items']),
            'page'  => $page,
            'limit' => $limit,
            'total' => $result['total'],
        ]);
    }

    #[Route('/{id}', name: 'api_admin_outbound_notifications_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(OutboundNotificationVoter::VIEW);

        $notification = $this->notifications->find($id);
        if (!$notification instanceof OutboundNotification) {
            throw $this->createNotFoundException();
        }

        return $this->json($this->toDetailPayload($notification));
    }

    /** @return array<string, mixed> */
    private function toListPayload(OutboundNotification $n): array
    {
        return [
            'id'               => $n->getId(),
            'createdAt'        => $n->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'recipient'        => $this->recipientPayload($n),
            'channel'          => $n->getChannel()?->value,
            'notificationType' => $n->getNotificationType(),
            'status'           => $n->getStatus()->value,
            'title'            => $n->getTitle(),
            'subject'          => $n->getSubject(),
            'missionId'        => $n->getMission()?->getId(),
            'attemptCount'     => $n->getAttemptCount(),
            'fallback'         => $n->isFallback(),
        ];
    }

    /** @return array<string, mixed> */
    private function toDetailPayload(OutboundNotification $n): array
    {
        $payload = array_merge($this->toListPayload($n), [
            'bodyText'        => $n->getBodyText(),
            'bodyHtml'        => $n->getBodyHtml(),
            'payload'         => $n->getPayload(),
            'planningVersionId' => $n->getPlanningVersion()?->getId(),
            'queuedAt'        => $n->getQueuedAt()?->format(\DateTimeInterface::ATOM),
            'sentAt'          => $n->getSentAt()?->format(\DateTimeInterface::ATOM),
            'failedAt'        => $n->getFailedAt()?->format(\DateTimeInterface::ATOM),
            'failureCode'     => $n->getFailureCode(),
            'failureMessage'  => $n->getFailureMessage(),
            'fallbackOfId'    => $n->getFallbackOf()?->getId(),
            'fallbackReason'  => $n->getFallbackReason()?->value,
            'attempts'        => array_map([$this, 'attemptPayload'], $n->getAttempts()->toArray()),
        ]);

        return $payload;
    }

    /** @return array<string, mixed> */
    private function attemptPayload(OutboundNotificationAttempt $a): array
    {
        return [
            'attemptNumber' => $a->getAttemptNumber(),
            'startedAt'     => $a->getStartedAt()?->format(\DateTimeInterface::ATOM),
            'finishedAt'    => $a->getFinishedAt()?->format(\DateTimeInterface::ATOM),
            'success'       => $a->isSuccess(),
            'statusCode'    => $a->getStatusCode(),
            'reason'        => $a->getReason(),
            'provider'      => $a->getProvider(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function recipientPayload(OutboundNotification $n): ?array
    {
        $recipient = $n->getRecipientUser();
        if ($recipient === null) {
            return null;
        }

        return [
            'id'    => $recipient->getId(),
            'name'  => $this->buildDisplayName($recipient),
            'email' => $recipient->getEmail(),
        ];
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $value)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        if ($dt === false) {
            throw new BadRequestHttpException(sprintf('Invalid date format: "%s". Use ISO 8601 (e.g. 2026-06-01T00:00:00+00:00).', $value));
        }

        return $dt;
    }

    private function parseChannel(string $value): OutboundNotificationChannel
    {
        $case = OutboundNotificationChannel::tryFrom($value);
        if ($case === null) {
            $valid = implode(', ', array_column(OutboundNotificationChannel::cases(), 'value'));
            throw new BadRequestHttpException(sprintf('Unknown channel "%s". Valid values: %s', $value, $valid));
        }

        return $case;
    }

    private function parseStatus(string $value): OutboundNotificationStatus
    {
        $case = OutboundNotificationStatus::tryFrom($value);
        if ($case === null) {
            $valid = implode(', ', array_column(OutboundNotificationStatus::cases(), 'value'));
            throw new BadRequestHttpException(sprintf('Unknown status "%s". Valid values: %s', $value, $valid));
        }

        return $case;
    }
}
