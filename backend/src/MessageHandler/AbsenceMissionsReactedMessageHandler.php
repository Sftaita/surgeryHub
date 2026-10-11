<?php

namespace App\MessageHandler;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionCommunicationSource;
use App\Enum\NotificationType;
use App\Message\AbsenceMissionsReactedMessage;
use App\Notification\EmailContent;
use App\Notification\MissionCommunicationSpec;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Notification\PushContent;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Level 3 (published / operational missions) of an absence — D-062, CAS B (D-118), H4 (D-127 /
 * D-130). Sends the absence-specific notifications of the missions AbsenceMissionReactionService
 * actually mutated, through NotificationDispatcher (BATCH: one dispatch and one in-app item per
 * mission, ONE email and ONE push per recipient and type for the whole run):
 *
 *   instrumentist absent (missions RELEASED → OPEN)
 *     - (never the absent instrumentist herself: no notification about the consequences of
 *       one's own absence — final H4 rule; ABSENCE_INSTRUMENTIST_RELEASED is no longer sent)
 *     - each surgeon concerned     ABSENCE_SURGEON_MISSION_OPENED   (the pool is solicited by
 *       OpenMissionPoolNotifier from the RELEASED lifecycle message — never here)
 *   surgeon absent (missions CANCELLED)
 *     - the instrumentist          ABSENCE_MISSION_CANCELLED        ("votre mission … est annulée")
 *       or, when CAS B moved her to another mission, ABSENCE_INSTRUMENTIST_REASSIGNED (never
 *       both for one mission) — the new mission counts as communicated to her (ASSIGN) once
 *       a channel reaches her.
 *
 * These N3 types never overlap with the N1 / N2 types (ABSENCE_OCCURRENCE_*): one occurrence
 * is qualified once. The lifecycle handler leaves these people to this recap (causedByAbsenceId).
 * Idempotent: the episode is (absence, mission, type).
 */
#[AsMessageHandler]
final class AbsenceMissionsReactedMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(AbsenceMissionsReactedMessage $message): void
    {
        if (empty($message->missions)) {
            return;
        }

        /** @var array<string, list<NotificationIntent>> $byType */
        $byType = [];
        $add = function (int $recipientId, NotificationType $type, array $mission, ?MissionCommunicationSpec $communicates = null) use (&$byType, $message): void {
            if ($recipientId === $message->absentUserId) {
                return; // H4 — never the absent person about the consequences of her own absence
            }
            $recipient = $this->em->find(User::class, $recipientId);
            if ($recipient === null) {
                $this->logger->warning('AbsenceMissionsReacted: recipient not found', ['userId' => $recipientId, 'absenceId' => $message->absenceId]);
                return;
            }
            $byType[$type->value][] = new NotificationIntent(
                recipient: $recipient,
                type: $type,
                inAppPayload: $mission + ['level' => 'PUBLISHED', 'absenceId' => $message->absenceId, 'missionDate' => $mission['date'] ?? null],
                mission: $this->em->getReference(Mission::class, $mission['missionId']),
                episodeRef: sprintf('absence:%d:PUBLISHED:mission:%d:%s', $message->absenceId, $mission['missionId'], $type->value),
                communicates: $communicates,
            );
        };

        if ($message->absentUserRole === 'INSTRUMENTIST') {
            foreach ($message->missions as $m) {
                if ($m['surgeonId'] !== null) {
                    $add((int) $m['surgeonId'], NotificationType::ABSENCE_SURGEON_MISSION_OPENED, $m);
                }
            }
        } else {
            foreach ($message->missions as $m) {
                if ($m['instrumentistId'] === null) {
                    continue; // an OPEN mission had nobody to tell
                }
                if (!empty($m['reassignedTo'])) {
                    $add((int) $m['instrumentistId'], NotificationType::ABSENCE_INSTRUMENTIST_REASSIGNED, $m, new MissionCommunicationSpec(
                        MissionCommunicationSource::ASSIGN, MissionCommunicationSpec::ROLE_INSTRUMENTIST,
                        array_map(static fn (array $t) => (int) $t['missionId'], $m['reassignedTo']),
                    ));
                } else {
                    $add((int) $m['instrumentistId'], NotificationType::ABSENCE_MISSION_CANCELLED, $m);
                }
            }
        }

        foreach ($byType as $typeValue => $intents) {
            $type = NotificationType::from($typeValue);
            try {
                $this->dispatcher->dispatchBatch(
                    $intents,
                    fn (User $u, array $its) => $this->email($u, $type, $its),
                    fn (User $u, array $its) => self::push($type, $its),
                );
            } catch (\Throwable $e) {
                $this->logger->error('AbsenceMissionsReacted: dispatch failed', ['type' => $typeValue, 'absenceId' => $message->absenceId, 'error' => $e->getMessage()]);
            }
        }
    }

    /** @param list<NotificationIntent> $intents */
    private function email(User $recipient, NotificationType $type, array $intents): EmailContent
    {
        $missions = array_map(static fn (NotificationIntent $i) => $i->inAppPayload, $intents);
        $count = count($missions);
        [$template, $subject] = match ($type) {
            NotificationType::ABSENCE_INSTRUMENTIST_RELEASED => ['emails/absence_instrumentist_released.html.twig',
                $count > 1 ? sprintf('%d missions retirées suite à votre absence', $count) : 'Une mission retirée suite à votre absence'],
            NotificationType::ABSENCE_SURGEON_MISSION_OPENED => ['emails/absence_surgeon_mission_opened.html.twig',
                $count > 1 ? sprintf('%d de vos missions sont désormais à pourvoir', $count) : 'Une de vos missions est désormais à pourvoir'],
            NotificationType::ABSENCE_INSTRUMENTIST_REASSIGNED => ['emails/absence_instrumentist_reassigned.html.twig',
                $count > 1 ? sprintf('%d missions réaffectées suite à une absence chirurgien', $count) : 'Mission réaffectée suite à une absence chirurgien'],
            default => ['emails/absence_mission_cancelled.html.twig',
                $count > 1 ? sprintf('%d missions annulées suite à une absence chirurgien', $count) : 'Une mission annulée suite à une absence chirurgien'],
        };

        return new EmailContent($subject, $template, [
            'recipientName' => self::name($recipient),
            'missions'      => $missions,
            'missionCount'  => $count,
            'notificationPreferencesUrl' => $this->notificationService->notificationPreferencesUrl($recipient),
        ]);
    }

    /** @param list<NotificationIntent> $intents */
    private static function push(NotificationType $type, array $intents): PushContent
    {
        $m = $intents[0]->inAppPayload;
        $count = count($intents);
        $one = sprintf('%s · %s', $m['date'] ?? '', $m['siteName'] ?? '');

        return match ($type) {
            NotificationType::ABSENCE_INSTRUMENTIST_RELEASED => new PushContent('Mission retirée suite à votre absence', $count > 1 ? sprintf('%d missions retirées de votre planning.', $count) : $one, ['type' => $type->value, 'url' => '/app/i/planning']),
            NotificationType::ABSENCE_SURGEON_MISSION_OPENED => new PushContent('Mission à pourvoir', $count > 1 ? sprintf('%d de vos missions sont à pourvoir (absence de l\'instrumentiste).', $count) : $one . ' — instrumentiste indisponible', ['type' => $type->value, 'url' => '/app/s/planning']),
            NotificationType::ABSENCE_INSTRUMENTIST_REASSIGNED => new PushContent('Mission réaffectée', $count > 1 ? sprintf('%d missions réaffectées.', $count) : $one, ['type' => $type->value, 'url' => '/app/i/planning']),
            default => new PushContent('Mission annulée', $count > 1 ? sprintf('%d de vos missions sont annulées (absence du chirurgien).', $count) : sprintf('Votre mission du %s avec le Dr %s est annulée en raison de son absence.', $m['date'] ?? '', $m['surgeonName'] ?? ''), ['type' => $type->value, 'url' => '/app/i/planning']),
        };
    }

    private static function name(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));

        return $name !== '' ? $name : (string) $user->getEmail();
    }
}
