<?php

namespace App\MessageHandler;

use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\CatalogueRequestKind;
use App\Enum\NotificationType;
use App\Enum\PublicationChannel;
use App\Message\CatalogueRequestCreatedMessage;
use App\Repository\UserRepository;
use App\Service\NotificationChannels;
use App\Service\NotificationPreferenceResolver;
use App\Service\NotificationService;
use App\Service\NotificationTargetResolver;
use App\Service\OutboundNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Follow-up to D-093 — prévient les managers/admins actifs qu'un instrumentiste vient de
 * proposer une intervention ou un matériel absent du catalogue (InterventionTypeRequest
 * ou MaterialItemRequest), pendant son encodage.
 *
 * Destinataires : tous les managers/admins actifs (UserRepository::findManagersAndAdmins(
 * true)) — même ciblage que PlanningAlertRaisedMessageHandler/AbsenceImpactService (Batch
 * 7), pas de scoping par site : une proposition catalogue impacte le référentiel global,
 * pas seulement le site de la mission.
 *
 * D-113 (amende D-094) — in-app + push + **email désormais**, les trois gouvernés
 * indépendamment par NotificationPreferenceResolver (CATALOGUE_REQUEST_CREATED ajouté à
 * EMAIL_ON_BY_DEFAULT). D-094 excluait volontairement l'email ("bruit pur") ; le besoin
 * produit a changé — une proposition catalogue ne doit plus pouvoir rester sans
 * traitement faute d'avoir été vue dans l'app. L'email n'est PAS un repli du push
 * (contrairement à CatalogueRequestProcessedMessageHandler) : c'est un canal indépendant,
 * désactivable individuellement dans les préférences.
 *
 * Failure isolation par destinataire (comme MissionPublishedMessageHandler::
 * notifySiteInstrumentists) — l'échec d'un manager (ou d'un seul canal) ne doit jamais
 * empêcher les autres destinataires/canaux d'être notifiés.
 */
#[AsMessageHandler]
final class CatalogueRequestCreatedMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly OutboundNotificationService $outboundNotificationService,
        private readonly NotificationPreferenceResolver $preferenceResolver,
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

        foreach ($this->userRepository->findManagersAndAdmins(true) as $manager) {
            $this->notifyManager($manager, $mission, $message, $kindLabel);
        }
    }

    private function notifyManager(User $manager, Mission $mission, CatalogueRequestCreatedMessage $message, string $kindLabel): void
    {
        try {
            $channels = $this->resolveChannelsSafely($manager, NotificationType::CATALOGUE_REQUEST_CREATED);

            if ($channels->inApp) {
                $evt = (new NotificationEvent())
                    ->setUser($manager)
                    ->setMission($mission)
                    ->setEventType(NotificationType::CATALOGUE_REQUEST_CREATED->value)
                    ->setChannel(PublicationChannel::IN_APP)
                    ->setSentAt(new \DateTimeImmutable())
                    ->setPayload([
                        'missionId' => $mission->getId(),
                        'requestId' => $message->requestId,
                        'label'     => $message->label,
                        'kind'      => $message->kind->value,
                    ]);
                $this->em->persist($evt);
                $this->em->flush();
            }

            if ($channels->push) {
                $title = $message->kind === CatalogueRequestKind::INTERVENTION_TYPE
                    ? 'Nouvelle proposition d\'intervention'
                    : 'Nouvelle proposition de matériel';
                $body = sprintf('Un instrumentiste a proposé un(e) %s qui n\'existe pas encore dans le catalogue : « %s ».', $kindLabel, $message->label);
                $data = [
                    'missionId' => $mission->getId(),
                    'url' => $this->targetResolver->resolve(
                        NotificationType::CATALOGUE_REQUEST_CREATED,
                        $mission,
                        $manager,
                        $message->requestId,
                        $message->kind,
                    ),
                ];

                $this->outboundNotificationService->recordPushSend(
                    $manager,
                    NotificationType::CATALOGUE_REQUEST_CREATED->value,
                    $title,
                    $body,
                    $data,
                    $mission,
                );
            }

            // D-113 (amende D-094) — canal indépendant du push, jamais un repli.
            if ($channels->email) {
                $this->notificationService->catalogueRequestCreatedNotifyManager(
                    $mission,
                    $manager,
                    $message->requestId,
                    $message->label,
                    $kindLabel,
                    $message->kind,
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('CatalogueRequestCreated: manager notification failed', [
                'requestId' => $message->requestId,
                'managerId' => $manager->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveChannelsSafely(User $user, NotificationType $type): NotificationChannels
    {
        try {
            return $this->preferenceResolver->resolve($user, $type);
        } catch (\Throwable) {
            return new NotificationChannels(inApp: true, email: false, push: false);
        }
    }
}
