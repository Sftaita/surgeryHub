<?php

namespace App\Notification;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionCommunicationSource;
use App\Enum\MissionType;
use App\Enum\NotificationType;
use App\Service\NotificationService;
use App\Service\NotificationTargetResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * H2 (D-128) — the wording of each mission-lifecycle notification, per recipient role,
 * for the three channels. Pure content: it never decides WHO is notified nor WHICH channel
 * is used (MissionLifecycleChangedMessageHandler / NotificationDispatcher do).
 *
 * Vocabulary follows D-127: these are level-3 (published planning) changes, so they say
 * plainly that the recipient's planning changed. Wording is gender-neutral ("cette mission
 * ne fait plus partie de votre planning") and carries no patient data — date, time, site,
 * surgeon, type only.
 */
class MissionLifecycleNotificationFactory
{
    public const ROLE_SURGEON       = 'SURGEON';
    public const ROLE_INSTRUMENTIST = 'INSTRUMENTIST';
    public const ROLE_NEW_INSTRUMENTIST = 'NEW_INSTRUMENTIST';

    public function __construct(
        private readonly NotificationTargetResolver $targetResolver,
        private readonly NotificationService $notificationService,
        #[Autowire('%env(string:FRONTEND_URL)%')]
        private readonly string $frontendUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $inAppPayload the existing NotificationEvent payload
     * @param array<string, mixed> $facts        names/reason taken from the message snapshot
     */
    public function intent(
        User $recipient,
        NotificationType $type,
        string $role,
        Mission $mission,
        array $inAppPayload,
        array $facts,
        string $episodeRef,
    ): NotificationIntent {
        [$headline, $intro, $pushTitle, $pushBody, $pushType] = $this->wording($type, $role, $mission, $facts);

        $path = $this->targetResolver->resolve($type, $mission, $recipient);
        $url  = $path !== null ? rtrim($this->frontendUrl, '/') . $path : null;

        $start = $mission->getStartAt();
        $end   = $mission->getEndAt();
        $date  = $start?->format('d/m/Y') ?? '';

        $email = new EmailContent(
            subject: sprintf('SurgicalHub — %s%s', $headline, $date !== '' ? ' (' . $date . ')' : ''),
            htmlTemplate: 'emails/mission_lifecycle_update.html.twig',
            context: [
                'greeting'          => $this->notificationService->greetingFor($recipient),
                'headline'          => $headline,
                'intro'             => $intro,
                'dateLabel'         => $date ?: null,
                'timeLabel'         => $start !== null && $end !== null ? $start->format('H:i') . ' – ' . $end->format('H:i') : null,
                'siteName'          => $mission->getSite()?->getName(),
                'surgeonName'       => $role === self::ROLE_SURGEON ? null : self::name($mission->getSurgeon()),
                'instrumentistName' => $role === self::ROLE_SURGEON ? ($facts['instrumentistName'] ?? null) : null,
                'typeLabel'         => $mission->getType() === MissionType::CONSULTATION ? 'Consultation' : 'Bloc opératoire',
                'reason'            => $facts['reason'] ?? null,
                'missionUrl'        => $url,
                'notificationPreferencesUrl' => $this->notificationService->notificationPreferencesUrl($recipient),
            ],
        );

        $push = new PushContent($pushTitle, $pushBody, [
            'type' => $pushType, 'missionId' => $mission->getId(), 'url' => $path,
        ]);

        return new NotificationIntent(
            recipient: $recipient,
            type: $type,
            inAppPayload: $inAppPayload,
            mission: $mission,
            email: $email,
            push: $push,
            episodeRef: $episodeRef,
            // H4 (D-130) — telling the new instrumentist that the mission is now hers is what
            // makes it communicated to her (once a channel reaches her).
            communicates: $role === self::ROLE_NEW_INSTRUMENTIST && $mission->getId() !== null
                ? new MissionCommunicationSpec(MissionCommunicationSource::ASSIGN, MissionCommunicationSpec::ROLE_INSTRUMENTIST, [(int) $mission->getId()])
                : null,
        );
    }

    /**
     * @param array<string, mixed> $facts
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string} headline, intro, push title, push body, push type
     */
    private function wording(NotificationType $type, string $role, Mission $mission, array $facts): array
    {
        $site = $mission->getSite()?->getName() ?? '';

        return match (true) {
            $type === NotificationType::SURGEON_POST_COVERED => [
                'Votre mission est couverte',
                sprintf('%s a pris en charge votre mission.', $facts['instrumentistName'] ?? 'Une instrumentiste'),
                'Mission couverte',
                sprintf('%s a pris en charge votre mission à %s.', $facts['instrumentistName'] ?? 'Un instrumentiste', $site),
                'MISSION_COVERED',
            ],
            $type === NotificationType::SURGEON_POST_UNCOVERED => [
                'Votre mission doit être recouverte',
                ($facts['fromInstrumentistName'] ?? null) !== null
                    ? sprintf('%s n\'est plus assigné·e à votre mission. Elle est remise à disposition des instrumentistes pour trouver un remplacement.', $facts['fromInstrumentistName'])
                    : 'Votre mission n\'a plus d\'instrumentiste. Elle est remise à disposition des instrumentistes pour trouver un remplacement.',
                'Mission non couverte',
                sprintf('Votre mission à %s n\'a plus d\'instrumentiste.', $site),
                'MISSION_UNCOVERED',
            ],
            $type === NotificationType::PLANNING_MISSION_RELEASED => [
                'Une mission a été retirée de votre planning',
                'Le manager a libéré cette mission : elle ne fait plus partie de votre planning.',
                'Mission retirée de votre planning',
                sprintf('La mission du %s à %s ne fait plus partie de votre planning.', $mission->getStartAt()?->format('d/m') ?? '', $site),
                'MISSION_RELEASED',
            ],
            $type === NotificationType::PLANNING_MISSION_REASSIGNED && $role === self::ROLE_NEW_INSTRUMENTIST => [
                'Une mission a été ajoutée à votre planning',
                'Cette mission vous a été attribuée : elle fait désormais partie de votre planning.',
                'Mission assignée',
                sprintf('Vous avez été assigné·e à une mission à %s.', $site),
                'MISSION_REASSIGNED',
            ],
            $type === NotificationType::PLANNING_MISSION_REASSIGNED => [
                'Une mission a été retirée de votre planning',
                'Cette mission a été confiée à une autre personne : elle ne fait plus partie de votre planning.',
                'Mission réassignée',
                sprintf('Une mission à %s ne fait plus partie de votre planning.', $site),
                'MISSION_REASSIGNED',
            ],
            $type === NotificationType::PLANNING_MISSION_CANCELLED && $role === self::ROLE_SURGEON => [
                'Votre mission est annulée',
                'Cette mission est annulée.',
                'Mission annulée',
                sprintf('Une mission à %s a été annulée.', $site),
                'MISSION_CANCELLED',
            ],
            $type === NotificationType::PLANNING_MISSION_CANCELLED => [
                'Une mission de votre planning est annulée',
                'Cette mission, qui faisait partie de votre planning, est annulée.',
                'Mission annulée',
                sprintf('Une mission de votre planning à %s a été annulée.', $site),
                'MISSION_CANCELLED',
            ],
            default => throw new \InvalidArgumentException(sprintf('No lifecycle wording for %s.', $type->value)),
        };
    }

    private static function name(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));

        return $name !== '' ? $name : null;
    }
}
