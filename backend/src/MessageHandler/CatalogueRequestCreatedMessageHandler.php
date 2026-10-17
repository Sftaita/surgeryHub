<?php

namespace App\MessageHandler;

use App\Entity\InterventionTypeRequest;
use App\Entity\MaterialItemRequest;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\CatalogueRequestKind;
use App\Enum\NotificationType;
use App\Message\CatalogueRequestCreatedMessage;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Notification\PushContent;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use App\Service\NotificationTargetResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Follow-up to D-093 — prévient les managers/admins actifs qu'une intervention ou un
 * matériel absent du catalogue vient d'être proposé (InterventionTypeRequest ou
 * MaterialItemRequest) pendant un encodage. D-154 — l'auteur annoncé est l'auteur réel de
 * la demande (instrumentiste, chirurgien de la mission ou manager), jamais supposé.
 *
 * Destinataires : les managers/admins opérationnels (UserRepository::findOperationalManagers(),
 * D-149) — une proposition catalogue impacte le référentiel global, pas seulement le site.
 *
 * D-113 (amende D-094) — in-app + push + email, chacun gouverné indépendamment par les
 * préférences (l'email n'est PAS un repli du push : politique WHEN_ENABLED).
 *
 * H5a — via NotificationDispatcher (chaque canal tracé, idempotent par demande et manager).
 * Failure isolation par destinataire : l'échec d'un manager n'empêche jamais les autres.
 */
#[AsMessageHandler]
final class CatalogueRequestCreatedMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationTargetResolver $targetResolver,
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(CatalogueRequestCreatedMessage $message): void
    {
        $mission = $this->em->find(Mission::class, $message->missionId);
        if (!$mission instanceof Mission) {
            $this->logger->warning('CatalogueRequestCreated: mission not found', [
                'missionId' => $message->missionId,
            ]);
            return;
        }

        $kindLabel = $message->kind === CatalogueRequestKind::INTERVENTION_TYPE ? 'intervention' : 'matériel';
        $request = $this->em->find(
            $message->kind === CatalogueRequestKind::INTERVENTION_TYPE ? InterventionTypeRequest::class : MaterialItemRequest::class,
            $message->requestId,
        );
        $requester = $request?->getCreatedBy();

        foreach ($this->userRepository->findOperationalManagers() as $manager) {
            $this->notifyManager($manager, $mission, $message, $kindLabel, $requester);
        }
    }

    private function notifyManager(User $manager, Mission $mission, CatalogueRequestCreatedMessage $message, string $kindLabel, ?User $requester): void
    {
        $role = NotificationService::catalogueRequesterRole($requester, $mission);
        $body = $role !== null
            ? sprintf('%s a proposé un(e) %s qui n\'existe pas encore dans le catalogue : « %s ».', $role, $kindLabel, $message->label)
            : sprintf('Un(e) %s qui n\'existe pas encore dans le catalogue a été proposé(e) : « %s ».', $kindLabel, $message->label);

        try {
            $type = NotificationType::CATALOGUE_REQUEST_CREATED;
            $this->dispatcher->dispatch(new NotificationIntent(
                recipient: $manager,
                type: $type,
                inAppPayload: [
                    'missionId' => $mission->getId(),
                    'requestId' => $message->requestId,
                    'label'     => $message->label,
                    'kind'      => $message->kind->value,
                ],
                mission: $mission,
                email: $this->notificationService->catalogueRequestCreatedEmail($mission, $manager, $message->requestId, $message->label, $kindLabel, $message->kind, $requester),
                push: new PushContent(
                    $message->kind === CatalogueRequestKind::INTERVENTION_TYPE ? 'Nouvelle proposition d\'intervention' : 'Nouvelle proposition de matériel',
                    $body,
                    [
                        'missionId' => $mission->getId(),
                        'url' => $this->targetResolver->resolve($type, $mission, $manager, $message->requestId, $message->kind),
                    ],
                ),
                episodeRef: sprintf('catalogue_request_created:%s:%d', $message->kind->value, $message->requestId),
            ));
        } catch (\Throwable $e) {
            $this->logger->error('CatalogueRequestCreated: manager notification failed', [
                'requestId' => $message->requestId,
                'managerId' => $manager->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
