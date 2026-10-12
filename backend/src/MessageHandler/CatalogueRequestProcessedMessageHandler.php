<?php

namespace App\MessageHandler;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\CatalogueRequestKind;
use App\Enum\NotificationEmailPolicy;
use App\Enum\NotificationType;
use App\Message\CatalogueRequestProcessedMessage;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Notification\PushContent;
use App\Service\NotificationService;
use App\Service\NotificationTargetResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * D-093 — prévient l'instrumentiste de l'issue d'une proposition catalogue
 * (InterventionTypeRequest ou MaterialItemRequest) qu'il a soumise pendant l'encodage,
 * une fois le manager passé par CatalogueRequestsPage.
 *
 * H5a — via NotificationDispatcher : in-app, push d'abord, repli email uniquement si le push
 * n'a pas été accepté ou est désactivé (politique FALLBACK_WHEN_PUSH_NOT_SENT, l'orchestration
 * D-083 inchangée) — chaque canal tracé (NotificationDispatch + OutboundNotification), idempotent
 * par demande traitée.
 */
#[AsMessageHandler]
final class CatalogueRequestProcessedMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationService $notificationService,
        private readonly NotificationTargetResolver $targetResolver,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(CatalogueRequestProcessedMessage $message): void
    {
        $recipient = $this->em->find(User::class, $message->recipientUserId);
        if (!$recipient instanceof User) {
            $this->logger->warning('CatalogueRequestProcessed: recipient not found', [
                'recipientUserId' => $message->recipientUserId,
            ]);
            return;
        }

        $mission = $this->em->find(Mission::class, $message->missionId);
        if (!$mission instanceof Mission) {
            $this->logger->warning('CatalogueRequestProcessed: mission not found', [
                'missionId' => $message->missionId,
            ]);
            return;
        }

        try {
            $type = $message->accepted ? NotificationType::CATALOGUE_REQUEST_RESOLVED : NotificationType::CATALOGUE_REQUEST_IGNORED;
            $kindLabel = $message->kind === CatalogueRequestKind::INTERVENTION_TYPE ? 'intervention' : 'matériel';
            // D-146 bis — résolution acceptée avec l'instantané de ce qui a été DÉFINITIVEMENT
            // enregistré : e-mail d'accusé (nom exact à rechercher) envoyé selon les
            // préférences de l'instrumentiste (activé par défaut), plus seulement en repli du
            // push. Un message sans instantané (émis avant D-146 bis) garde l'ancien
            // comportement — jamais d'envoi rétroactif ni de champ inventé.
            $withResolution = $message->accepted && $message->resolution !== [];
            $finalName = $withResolution ? (string) ($message->resolution['name'] ?? $message->label) : $message->label;

            $this->dispatcher->dispatch(new NotificationIntent(
                recipient: $recipient,
                type: $type,
                inAppPayload: [
                    'missionId' => $mission->getId(),
                    'requestId' => $message->requestId,
                    'label'     => $message->label,
                    'kind'      => $message->kind->value,
                ] + ($withResolution ? ['finalName' => $finalName] : []),
                mission: $mission,
                email: $withResolution
                    ? $this->notificationService->catalogueRequestResolutionEmail($mission, $recipient, $message->kind, $message->label, $message->resolution)
                    : ($message->accepted
                    ? $this->notificationService->catalogueRequestResolvedEmail($mission, $recipient, $message->label, $kindLabel)
                    : $this->notificationService->catalogueRequestIgnoredEmail($mission, $recipient, $message->label, $kindLabel, $message->ignoreReason, $message->explanation)),
                push: new PushContent(
                    $message->accepted ? 'Proposition acceptée' : 'Proposition non retenue',
                    $withResolution
                        ? sprintf('Votre demande de %s a été acceptée : recherchez désormais « %s » dans SurgicalHub.', $kindLabel, $finalName)
                        : ($message->accepted
                        ? sprintf('Votre proposition de %s « %s » a été acceptée et ajoutée au catalogue.', $kindLabel, $message->label)
                        : sprintf('Votre proposition de %s « %s » n\'a pas été retenue. Motif : %s.', $kindLabel, $message->label, $message->ignoreReason?->label() ?? 'non précisé')),
                    ['missionId' => $mission->getId(), 'url' => $this->targetResolver->resolve($type, $mission, $recipient)],
                ),
                episodeRef: sprintf('catalogue_request:%s:%d', $message->kind->value, $message->requestId),
                emailPolicy: $withResolution ? NotificationEmailPolicy::WHEN_ENABLED : NotificationEmailPolicy::FALLBACK_WHEN_PUSH_NOT_SENT,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('CatalogueRequestProcessed: notification failed', [
                'requestId' => $message->requestId,
                'recipientUserId' => $message->recipientUserId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
