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
    /** D-151 — operational managers told the real origin of a surgeon refusal / review request. */
    public const ROLE_MANAGER = 'MANAGER';

    /** D-151 — subject imposed for every neutral "affectation not maintained" email. */
    public const NEUTRAL_REMOVAL_SUBJECT = 'SurgicalHub — Mise à jour de votre mission';

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

        // D-151 — an instrumentist taken off a published mission (surgeon refusal, manager
        // release or reassignment) only ever reads the imposed neutral text: no author, no
        // reason, never "mission annulée".
        $email = self::isNeutralRemoval($type, $role) ? new EmailContent(
            subject: self::NEUTRAL_REMOVAL_SUBJECT,
            htmlTemplate: 'emails/assignment_not_maintained.html.twig',
            context: [
                'greeting'   => $this->notificationService->greetingFor($recipient),
                'dateLabel'  => $date,
                'timeLabel'  => $start?->format('H:i'),
                'siteName'   => $mission->getSite()?->getName(),
                'notificationPreferencesUrl' => $this->notificationService->notificationPreferencesUrl($recipient),
            ],
        ) : new EmailContent(
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
                'instrumentistName' => in_array($role, [self::ROLE_SURGEON, self::ROLE_MANAGER], true) ? ($facts['instrumentistName'] ?? null) : null,
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
        $day  = $mission->getStartAt()?->format('d/m/Y') ?? '';
        $hour = $mission->getStartAt()?->format('H:i') ?? '';

        if (self::isNeutralRemoval($type, $role)) {
            $sentence = sprintf("Votre affectation à la mission du %s à %s, prévue à %s, n'a pas pu être maintenue.", $day, $hour, $site);

            return ['Affectation annulée', $sentence, 'Mise à jour de votre mission', $sentence, 'ASSIGNMENT_NOT_MAINTAINED'];
        }

        // D-151 — a voluntary acceptance (pool / nominative offer): the surgeon is told who,
        // and until when he may refuse (only while a direct refusal is still possible).
        if ($type === NotificationType::SURGEON_POST_COVERED && ($facts['acceptedVoluntarily'] ?? false) === true) {
            $text = sprintf('%s a accepté votre mission du %s à %s, à %s.', $facts['instrumentistName'] ?? 'Un instrumentiste', $day, $hour, $site);
            $deadline = $facts['refusalDeadline'] ?? null;
            if ($deadline instanceof \DateTimeImmutable) {
                $text .= sprintf(" Vous pouvez signaler un refus d'affectation jusqu'au %s.", self::brussels($deadline));
            }

            return ['Instrumentiste affecté à votre mission', $text, 'Instrumentiste affecté à votre mission', $text, 'MISSION_COVERED'];
        }

        return match (true) {
            $type === NotificationType::ASSIGNMENT_REFUSED_BY_SURGEON => [
                "Refus d'affectation par le chirurgien",
                sprintf("Dr %s a refusé l'affectation de %s à sa mission du %s à %s, à %s. La mission est remise à disposition des autres instrumentistes ; %s ne peut plus la reprendre.",
                    self::name($mission->getSurgeon()) ?? '', $facts['instrumentistName'] ?? "l'instrumentiste", $day, $hour, $site, $facts['instrumentistName'] ?? 'cette personne'),
                "Refus d'affectation",
                sprintf("Dr %s a refusé l'affectation de %s (mission du %s, %s).", self::name($mission->getSurgeon()) ?? '', $facts['instrumentistName'] ?? "l'instrumentiste", $day, $site),
                'ASSIGNMENT_REFUSED_BY_SURGEON',
            ],
            $type === NotificationType::ASSIGNMENT_REVIEW_REQUESTED => [
                'Intervention demandée sur une affectation',
                sprintf("Dr %s demande votre intervention sur l'affectation de %s à sa mission du %s à %s, à %s.%s",
                    self::name($mission->getSurgeon()) ?? '', $facts['instrumentistName'] ?? "l'instrumentiste", $day, $hour, $site,
                    self::reviewContext($facts['refusalBlockedReason'] ?? null)),
                'Intervention demandée',
                sprintf("Dr %s demande votre intervention sur une affectation (mission du %s, %s).", self::name($mission->getSurgeon()) ?? '', $day, $site),
                'ASSIGNMENT_REVIEW_REQUESTED',
            ],
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
            $type === NotificationType::PLANNING_MISSION_REASSIGNED && $role === self::ROLE_NEW_INSTRUMENTIST => [
                'Une mission a été ajoutée à votre planning',
                'Cette mission vous a été attribuée : elle fait désormais partie de votre planning.',
                'Mission assignée',
                sprintf('Vous avez été assigné·e à une mission à %s.', $site),
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

    /**
     * D-151 — PLANNING_MISSION_RELEASED (to the removed instrumentist: surgeon refusal or
     * manager release) and PLANNING_MISSION_REASSIGNED to the former instrumentist share the
     * single neutral wording. Absence-driven removals have their own types (D-127), untouched.
     */
    public static function isNeutralRemoval(NotificationType $type, string $role): bool
    {
        return $type === NotificationType::PLANNING_MISSION_RELEASED
            || ($type === NotificationType::PLANNING_MISSION_REASSIGNED && $role === self::ROLE_INSTRUMENTIST);
    }

    private static function brussels(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('Europe/Brussels'))->format('d/m/Y à H:i');
    }

    private static function reviewContext(?string $blockedReason): string
    {
        return match ($blockedReason) {
            'REFUSAL_WINDOW_EXPIRED'   => ' Le délai de refus de 24 heures est écoulé.',
            'REFUSAL_REQUIRES_MANAGER' => ' La mission est imminente, commencée ou déjà en cours d\'encodage.',
            'ASSIGNMENT_NOT_REFUSABLE' => " L'affectation n'a pas été acceptée par l'instrumentiste (attribution manager ou planning).",
            default                    => '',
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
