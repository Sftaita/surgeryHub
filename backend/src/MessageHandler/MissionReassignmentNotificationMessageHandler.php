<?php

namespace App\MessageHandler;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionCommunicationSource;
use App\Enum\NotificationType;
use App\Message\MissionReassignmentNotificationMessage;
use App\Notification\EmailContent;
use App\Notification\MissionCommunicationSpec;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Notification\PushContent;
use App\Service\MissionReplacementService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * D-181 — tells the people concerned by a manager reassignment operation, through
 * NotificationDispatcher in BATCH mode (MISSION_REASSIGNMENT_NOTICE):
 *
 *   - the previous instrumentist: the mission (or, for a relay, its remaining part) was taken
 *     off her planning;
 *   - the new instrumentist: the mission was attributed to her (it then counts as
 *     communicated to her, H4);
 *   - the surgeon: who was planned, who is now — his programme itself is unchanged;
 *   - D-182 (mode REPLACED, MissionReplacementService): the replacement whose own declared
 *     mission was kept and approved instead of the cancelled duplicate (see roles()).
 *
 * One in-app item per mission and role; ONE email (and push) per recipient for the whole
 * operation, listing only her missions, with every role she has in it (a person who loses one
 * mission and gets another receives a single email with both). Preferences decide every
 * channel (email on by default); every outcome is traced (status read back by the manager).
 *
 * Idempotency: episodeRef = operation + mission + role, groupKey = type + recipient +
 * correlation (inherited from the operation): a Messenger retry never sends a second email.
 *
 * Content: date, hours (Europe/Brussels), site, surgeon, instrumentists' names only — never a
 * patient, a financial figure, a reason, nor the manager's internal comment (not in the message).
 */
#[AsMessageHandler]
final class MissionReassignmentNotificationMessageHandler
{
    public const ROLE_PREVIOUS = 'PREVIOUS_INSTRUMENTIST';
    public const ROLE_NEW      = 'NEW_INSTRUMENTIST';
    public const ROLE_SURGEON  = 'SURGEON';
    /** D-182 — the replacement instrumentist whose own (declared) mission was kept AND approved. */
    public const ROLE_CONFIRMED = 'CONFIRMED_INSTRUMENTIST';

    private const TZ = 'Europe/Brussels';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(string:FRONTEND_URL)%')]
        private readonly string $frontendUrl = '',
    ) {
    }

    public function __invoke(MissionReassignmentNotificationMessage $message): void
    {
        $intents = [];
        foreach ($message->changes as $change) {
            $mission = $this->em->find(Mission::class, (int) $change['missionId']);
            if ($mission === null) {
                $this->logger->warning('MissionReassignmentNotification: mission not found', ['missionId' => $change['missionId'], 'operationId' => $message->operationId]);
                continue;
            }
            $item = $this->item($change, $mission);

            foreach (self::roles($change) as $role => $recipientId) {
                $recipient = $this->em->find(User::class, $recipientId);
                if ($recipient === null) {
                    continue;
                }
                $intents[] = new NotificationIntent(
                    recipient: $recipient,
                    type: NotificationType::MISSION_REASSIGNMENT_NOTICE,
                    inAppPayload: $item + ['role' => $role, 'sentence' => self::sentence($role, $item)],
                    // The previous instrumentist of a relay keeps the original mission (her part).
                    mission: $role === self::ROLE_PREVIOUS ? ($this->em->find(Mission::class, (int) $change['originalMissionId']) ?? $mission) : $mission,
                    episodeRef: sprintf('reassignment:%s:%d:%s', $message->operationId, $change['missionId'], $role),
                    communicates: $role === self::ROLE_NEW
                        ? new MissionCommunicationSpec(MissionCommunicationSource::ASSIGN, MissionCommunicationSpec::ROLE_INSTRUMENTIST, [(int) $mission->getId()])
                        : null,
                );
            }
        }

        if ($intents === []) {
            return;
        }

        try {
            $this->dispatcher->dispatchBatch(
                $intents,
                fn (User $u, array $its) => $this->email($u, $its),
                static fn (User $u, array $its) => self::push($its),
            );
        } catch (\Throwable $e) {
            // The reassignment itself is committed: a notification failure never undoes it.
            $this->logger->error('MissionReassignmentNotification: dispatch failed', ['operationId' => $message->operationId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Who is told what about one change (role => user id).
     *
     * DIRECT / RELAY (D-181): previous instrumentist, new instrumentist, surgeon.
     * REPLACED (D-182): the planned mission was cancelled as a duplicate of the replacement's
     * own mission — the planned instrumentist (mission taken off her planning), the replacement
     * only when her declaration was really approved now (« conservée et validée »), the surgeon
     * (change of instrumentist). Same person on both missions: only the confirmation.
     *
     * @param array<string,mixed> $change
     * @return array<string,int>
     */
    public static function roles(array $change): array
    {
        $from    = ($change['fromInstrumentistId'] ?? null) !== null ? (int) $change['fromInstrumentistId'] : null;
        $to      = ($change['toInstrumentistId'] ?? null) !== null ? (int) $change['toInstrumentistId'] : null;
        $surgeon = ($change['surgeonId'] ?? null) !== null ? (int) $change['surgeonId'] : null;

        if (($change['mode'] ?? null) !== MissionReplacementService::MODE_REPLACED) {
            return array_filter([self::ROLE_PREVIOUS => $from, self::ROLE_NEW => $to, self::ROLE_SURGEON => $surgeon], static fn (?int $id) => $id !== null);
        }

        $samePerson = $from !== null && $from === $to;

        return array_filter([
            self::ROLE_PREVIOUS  => $samePerson ? null : $from,
            self::ROLE_CONFIRMED => ($change['approved'] ?? false) === true ? $to : null,
            self::ROLE_SURGEON   => $samePerson ? null : $surgeon,
        ], static fn (?int $id) => $id !== null);
    }

    /**
     * @param array<string,mixed> $change
     * @return array<string,mixed>
     */
    private function item(array $change, Mission $mission): array
    {
        $tz    = new \DateTimeZone(self::TZ);
        $start = isset($change['startAt']) ? (new \DateTimeImmutable($change['startAt']))->setTimezone($tz) : $mission->getStartAt()?->setTimezone($tz);
        $end   = isset($change['endAt']) ? (new \DateTimeImmutable($change['endAt']))->setTimezone($tz) : $mission->getEndAt()?->setTimezone($tz);
        $from  = ($change['fromInstrumentistId'] ?? null) !== null ? $this->em->find(User::class, (int) $change['fromInstrumentistId']) : null;
        $to    = $this->em->find(User::class, (int) $change['toInstrumentistId']);

        return [
            'missionId'             => $mission->getId(),
            'originalMissionId'     => (int) $change['originalMissionId'],
            'mode'                  => $change['mode'],
            'missionDate'           => $start?->format('d/m/Y'),
            'startTime'             => $start?->format('H:i'),
            'endTime'               => $end?->format('H:i'),
            'siteName'              => $mission->getSite()?->getName(),
            'surgeonName'           => self::name($mission->getSurgeon()),
            'fromInstrumentistName' => self::name($from),
            'toInstrumentistName'   => self::name($to),
        ];
    }

    /** @param array<string,mixed> $item */
    private static function sentence(string $role, array $item): string
    {
        $when = sprintf('%s, %s – %s, %s', $item['missionDate'] ?? '', $item['startTime'] ?? '', $item['endTime'] ?? '', $item['siteName'] ?? '');

        return match ($role) {
            self::ROLE_PREVIOUS => sprintf('Mission retirée de votre planning : %s (Dr %s).', $when, $item['surgeonName'] ?? ''),
            self::ROLE_NEW      => sprintf('Nouvelle mission attribuée : %s (Dr %s).', $when, $item['surgeonName'] ?? ''),
            self::ROLE_CONFIRMED => sprintf('Mission conservée et validée : %s (Dr %s).', $when, $item['surgeonName'] ?? ''),
            default             => sprintf("Changement d'instrumentiste le %s : %s remplace %s.", $when, $item['toInstrumentistName'] ?? '—', $item['fromInstrumentistName'] ?? '—'),
        };
    }

    /** @param list<NotificationIntent> $intents */
    private function email(User $recipient, array $intents): EmailContent
    {
        $sections = [self::ROLE_PREVIOUS => [], self::ROLE_NEW => [], self::ROLE_CONFIRMED => [], self::ROLE_SURGEON => []];
        foreach ($intents as $intent) {
            $sections[$intent->inAppPayload['role']][] = $intent->inAppPayload;
        }
        $roles = array_keys(array_filter($sections));

        $subject = match (true) {
            $roles === [self::ROLE_NEW]     => 'SurgicalHub – Nouvelle mission attribuée',
            $roles === [self::ROLE_CONFIRMED] => 'SurgicalHub – Mission confirmée',
            $roles === [self::ROLE_SURGEON] => "SurgicalHub – Changement d'instrumentiste pour votre programme",
            default                         => 'SurgicalHub – Modification de votre planning',
        };

        $onlySurgeon = $roles === [self::ROLE_SURGEON];
        $greeting = $onlySurgeon
            ? sprintf('Bonjour Docteur %s,', $recipient->getLastname() ?: '')
            : sprintf('Bonjour %s,', $recipient->getFirstname() ?: (trim(($recipient->getLastname() ?? '')) ?: ''));

        $all   = array_merge(...array_values($sections));
        $base  = rtrim($this->frontendUrl, '/');
        $space = $onlySurgeon ? '/app/s' : '/app/i';
        $link  = count($all) === 1 && $roles !== [self::ROLE_PREVIOUS]
            ? sprintf('%s%s/missions/%d', $base, $space, $all[0]['missionId'])
            : $base . $space . '/planning';

        return new EmailContent(
            $subject,
            'emails/mission_reassignment_notice.html.twig',
            [
                'greeting'   => preg_replace('/\s+,$/', ',', $greeting),
                'removed'    => $sections[self::ROLE_PREVIOUS],
                'added'      => $sections[self::ROLE_NEW],
                'confirmed'  => $sections[self::ROLE_CONFIRMED],
                'surgeon'    => $sections[self::ROLE_SURGEON],
                'planningUrl' => $link,
                'notificationPreferencesUrl' => $this->notificationService->notificationPreferencesUrl($recipient),
            ],
        );
    }

    /** @param list<NotificationIntent> $intents */
    private static function push(array $intents): PushContent
    {
        $count = count($intents);
        $first = $intents[0]->inAppPayload;

        $onlyConfirmed = array_unique(array_map(static fn (NotificationIntent $i) => $i->inAppPayload['role'], $intents)) === [self::ROLE_CONFIRMED];

        return new PushContent(
            $onlyConfirmed ? 'Mission confirmée' : "Changement d'instrumentiste",
            $count > 1 ? sprintf('%d changements concernent votre planning.', $count) : (string) $first['sentence'],
            ['type' => NotificationType::MISSION_REASSIGNMENT_NOTICE->value, 'missionId' => $count === 1 ? $first['missionId'] : null],
        );
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
