<?php

namespace App\Notification;

use App\Activity\ActivityJournal;
use App\Activity\CorrelationContext;
use App\Activity\Recorder\NotificationActivityRecorder;
use App\Entity\NotificationDispatch;
use App\Entity\NotificationEvent;
use App\Entity\OutboundNotification;
use App\Entity\User;
use App\Enum\NotificationDeliverySkipReason;
use App\Enum\NotificationDispatchMode;
use App\Enum\NotificationEmailPolicy;
use App\Enum\NotificationType;
use App\Enum\OutboundNotificationChannel;
use App\Enum\OutboundNotificationStatus;
use App\Enum\PublicationChannel;
use App\Message\SendTemplatedEmailMessage;
use App\Service\NotificationChannels;
use App\Service\NotificationPreferenceResolver;
use App\Service\OutboundNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * H2 (D-128) — the single place that turns a NotificationIntent into deliveries.
 *
 *   1. resolve the channels through NotificationPreferenceResolver (the only authority:
 *      no channel is ever hard-coded by a caller);
 *   2. record the decision (NotificationDispatch, idempotent per type + recipient +
 *      subject + episode);
 *   3. handle IN_APP, PUSH and EMAIL independently — a failing channel never prevents the
 *      others — each producing its own OutboundNotification row with an honest status:
 *      SENT (in-app created / push accepted / email accepted by the relay), QUEUED (email
 *      waiting for the async worker), FAILED, or SKIPPED with an explicit reason;
 *   4. trace it in activity_event (NotificationActivityRecorder).
 *
 * Retries: a second dispatch of the same intent finds the existing NotificationDispatch
 * and only completes the channels that have no delivery yet — never a second in-app
 * item, push or email for the same episode.
 *
 * BATCH mode (dispatchBatch): in-app stays one item per intent; email and push are grouped
 * into ONE delivery per (recipient, type, correlation), linked to every dispatch of the
 * recipient (OutboundNotification.groupKey, UNIQUE).
 *
 * "SENT" means accepted by the provider/transport — never delivered, received or read.
 */
class NotificationDispatcher
{
    private const PAYLOAD_KEYS = [
        'missionId', 'missionDate', 'dayLabel', 'startTime', 'endTime', 'siteName', 'periodLabel',
        'instrumentistId', 'instrumentistName', 'fromInstrumentistId', 'fromInstrumentistName',
        'toInstrumentistId', 'toInstrumentistName', 'actorName',
        // H4 (D-130) — absence impacts
        'level', 'status', 'absenceId', 'occurrenceDate', 'surgeonName', 'postId',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationPreferenceResolver $preferenceResolver,
        private readonly OutboundNotificationService $outbound,
        private readonly NotificationActivityRecorder $activity,
        private readonly CorrelationContext $correlation,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly MissionCommunicationService $communications,
        #[Autowire('%env(string:MAILER_FROM_ADDRESS)%')]
        private readonly string $fromAddress,
        #[Autowire('%env(string:MAILER_FROM_NAME)%')]
        private readonly string $fromName,
    ) {
    }

    public function dispatch(NotificationIntent $intent): NotificationDispatch
    {
        $channels = $this->resolveChannels($intent->recipient, $intent->type, $intent->emailPolicy);
        $dispatch = $this->findOrCreateDispatch($intent, $channels, NotificationDispatchMode::ISOLATED);

        $outcomes = [];
        $outcomes['IN_APP'] = $this->guard('IN_APP', $intent, fn () => $this->deliverInApp($dispatch, $intent, $channels));
        $push = null;
        $outcomes['PUSH'] = $this->guard('PUSH', $intent, function () use ($dispatch, $intent, $channels, &$push) {
            $push = $this->deliverPush($dispatch, $intent->recipient, $intent->type, $intent->push, $intent, $channels, null);

            return $push;
        });
        $outcomes['EMAIL'] = $this->guard('EMAIL', $intent, fn () => $this->deliverEmail(
            $dispatch, $intent->recipient, $intent->type, $intent->email, $intent->emailPolicy, $channels, $push, null, [$dispatch], $intent,
        ));

        $this->recordDispatched($dispatch, $outcomes);
        $this->proveCommunication($dispatch, $outcomes);

        return $dispatch;
    }

    /**
     * @param list<NotificationIntent>                                         $intents   same type, any recipients
     * @param callable(User, list<NotificationIntent>): ?EmailContent          $groupEmail
     * @param callable(User, list<NotificationIntent>): ?PushContent           $groupPush
     * @return list<NotificationDispatch>
     */
    public function dispatchBatch(array $intents, callable $groupEmail, callable $groupPush): array
    {
        /** @var array<int, array{user: User, intents: list<NotificationIntent>, dispatches: list<NotificationDispatch>, channels: NotificationChannels}> $byRecipient */
        $byRecipient = [];
        $all = [];

        foreach ($intents as $intent) {
            $uid = (int) $intent->recipient->getId();
            $byRecipient[$uid]['user'] ??= $intent->recipient;
            $byRecipient[$uid]['channels'] ??= $this->resolveChannels($intent->recipient, $intent->type, $intent->emailPolicy);
            $channels = $byRecipient[$uid]['channels'];

            $dispatch = $this->findOrCreateDispatch($intent, $channels, NotificationDispatchMode::BATCH);
            $byRecipient[$uid]['intents'][]    = $intent;
            $byRecipient[$uid]['dispatches'][] = $dispatch;
            $all[] = [$dispatch, $intent];
        }

        $outcomesByDispatch = [];
        foreach ($all as [$dispatch, $intent]) {
            $outcomesByDispatch[spl_object_id($dispatch)]['IN_APP'] = $this->guard('IN_APP', $intent,
                fn () => $this->deliverInApp($dispatch, $intent, $byRecipient[(int) $intent->recipient->getId()]['channels']));
        }

        foreach ($byRecipient as $group) {
            // H4 (D-130) — a dispatch that already has its email decision (an earlier run of
            // the same subject — retry, or an absence updated in a new operation) is never put
            // in a new grouped email / push again: only the fresh ones are.
            $fresh = ['intents' => [], 'dispatches' => []];
            foreach ($group['dispatches'] as $i => $dispatch) {
                $previousEmail = $this->deliveryOf($dispatch, OutboundNotificationChannel::EMAIL);
                if ($previousEmail !== null) {
                    $previousPush = $this->deliveryOf($dispatch, OutboundNotificationChannel::PUSH);
                    $outcomesByDispatch[spl_object_id($dispatch)]['EMAIL'] = self::outcome($previousEmail) + ['grouped' => true];
                    $outcomesByDispatch[spl_object_id($dispatch)]['PUSH']  = ($previousPush !== null ? self::outcome($previousPush) : ['status' => 'SKIPPED', 'skipReason' => 'NOT_APPLICABLE', 'outboundNotificationId' => null]) + ['grouped' => true];
                    continue;
                }
                $fresh['intents'][]    = $group['intents'][$i];
                $fresh['dispatches'][] = $dispatch;
            }
            if ($fresh['dispatches'] === []) {
                continue;
            }

            $first = $fresh['intents'][0];
            $type  = $first->type;
            $push  = null;
            $pushOutcome = $this->guard('PUSH', $first, function () use ($group, $fresh, $type, $first, &$push, $groupPush) {
                $push = $this->deliverPush($fresh['dispatches'][0], $group['user'], $type, $groupPush($group['user'], $fresh['intents']), $first, $group['channels'], $fresh['dispatches']);

                return $push;
            });
            $emailOutcome = $this->guard('EMAIL', $first, fn () => $this->deliverEmail(
                $fresh['dispatches'][0], $group['user'], $type, $groupEmail($group['user'], $fresh['intents']),
                $first->emailPolicy, $group['channels'], $push, $this->groupKey($type, $group['user'], OutboundNotificationChannel::EMAIL),
                $fresh['dispatches'], $first,
            ));
            foreach ($fresh['dispatches'] as $dispatch) {
                $outcomesByDispatch[spl_object_id($dispatch)]['PUSH']  = $pushOutcome + ['grouped' => true];
                $outcomesByDispatch[spl_object_id($dispatch)]['EMAIL'] = $emailOutcome + ['grouped' => true];
            }
        }

        foreach ($all as [$dispatch]) {
            $this->recordDispatched($dispatch, $outcomesByDispatch[spl_object_id($dispatch)]);
            $this->proveCommunication($dispatch, $outcomesByDispatch[spl_object_id($dispatch)]);
        }

        return array_map(static fn (array $pair) => $pair[0], $all);
    }

    // ── Decision ─────────────────────────────────────────────────────────────

    private function resolveChannels(User $user, NotificationType $type, NotificationEmailPolicy $policy = NotificationEmailPolicy::WHEN_ENABLED): NotificationChannels
    {
        try {
            $channels = $this->preferenceResolver->resolve($user, $type);
        } catch (\Throwable $e) {
            // Same conservative fallback the handlers always used: in-app only.
            $this->logger->error('NotificationDispatcher: preference resolution failed — in-app only', [
                'userId' => $user->getId(), 'type' => $type->value, 'error' => $e->getMessage(),
            ]);
            $channels = new NotificationChannels(inApp: true, email: false, push: false, source: 'RESOLVER_ERROR');
        }

        // H5a — a MANDATORY email never depends on the recipient's preferences (identity
        // emails, explicit one-off manager actions); the other channels still do.
        if ($policy === NotificationEmailPolicy::MANDATORY) {
            return new NotificationChannels($channels->inApp, true, $channels->push, NotificationChannels::SOURCE_MANDATORY);
        }

        return $channels;
    }

    private function findOrCreateDispatch(NotificationIntent $intent, NotificationChannels $channels, NotificationDispatchMode $mode): NotificationDispatch
    {
        $episode = $intent->episodeRef ?? ('correlation:' . $this->correlation->correlationId());
        [$subjectType, $subjectId] = $intent->mission !== null
            ? ['Mission', $intent->mission->getId()]
            : [$intent->subjectType, $intent->subjectId];

        $key = hash('sha256', implode('|', [
            $intent->type->value, (string) $intent->recipient->getId(), (string) $subjectType, (string) $subjectId, $episode,
        ]));

        $existing = $this->em->getRepository(NotificationDispatch::class)->findOneBy(['idempotencyKey' => $key]);
        if ($existing instanceof NotificationDispatch) {
            return $existing;
        }

        $dispatch = (new NotificationDispatch())
            ->setRecipient($intent->recipient)
            ->setRecipientNameSnapshot(ActivityJournal::displayName($intent->recipient))
            ->setNotificationType($intent->type->value)
            ->setMission($intent->mission)
            ->setSubject($subjectType, $subjectId)
            ->setEpisodeRef(mb_substr($episode, 0, 150))
            ->setCorrelationId($this->correlation->correlationId())
            ->setMode($mode)
            ->setChannelsResolved([
                'inApp' => $channels->inApp, 'email' => $channels->email, 'push' => $channels->push, 'source' => $channels->source,
            ])
            ->setPayload(array_intersect_key($intent->inAppPayload, array_flip(self::PAYLOAD_KEYS)) ?: null)
            ->setCommunicates($intent->communicates?->toArray())
            ->setIdempotencyKey($key);

        $this->em->persist($dispatch);
        $this->em->flush();

        return $dispatch;
    }

    // ── Channels ─────────────────────────────────────────────────────────────

    /** @return array{status: string, skipReason: ?string, outboundNotificationId: ?int} */
    private function deliverInApp(NotificationDispatch $dispatch, NotificationIntent $intent, NotificationChannels $channels): array
    {
        $existing = $this->deliveryOf($dispatch, OutboundNotificationChannel::IN_APP);
        if ($existing !== null) {
            return self::outcome($existing);
        }

        $row = $this->newDelivery($intent->recipient, $intent->type, OutboundNotificationChannel::IN_APP, $intent);
        $row->setTitle($intent->push?->title ?? $intent->email?->subject);

        if (!$intent->inApp) {
            $row->setStatus(OutboundNotificationStatus::SKIPPED)->setSkipReason(NotificationDeliverySkipReason::NOT_APPLICABLE);
        } elseif (!$channels->inApp) {
            $row->setStatus(OutboundNotificationStatus::SKIPPED)->setSkipReason(NotificationDeliverySkipReason::DISABLED_BY_PREFERENCE);
        } else {
            $event = (new NotificationEvent())
                ->setUser($intent->recipient)
                ->setMission($intent->mission)
                ->setEventType($intent->type->value)
                ->setChannel(PublicationChannel::IN_APP)
                ->setSentAt(new \DateTimeImmutable())
                ->setPayload($intent->inAppPayload);
            $this->em->persist($event);
            $row->setStatus(OutboundNotificationStatus::SENT)->setSentAt(new \DateTimeImmutable())->setNotificationEvent($event);
        }

        $this->em->persist($row);
        $dispatch->addDelivery($row);
        $this->em->flush();

        return self::outcome($row);
    }

    /**
     * @param list<NotificationDispatch>|null $linkTo every dispatch sharing this delivery (BATCH)
     */
    private function deliverPush(
        NotificationDispatch $dispatch,
        User $recipient,
        NotificationType $type,
        ?PushContent $content,
        NotificationIntent $intent,
        NotificationChannels $channels,
        ?array $linkTo,
    ): OutboundNotification {
        $linkTo ??= [$dispatch];
        $groupKey = $linkTo !== [$dispatch] || $dispatch->getMode() === NotificationDispatchMode::BATCH
            ? $this->groupKey($type, $recipient, OutboundNotificationChannel::PUSH)
            : null;

        $existing = $groupKey !== null
            ? $this->em->getRepository(OutboundNotification::class)->findOneBy(['groupKey' => $groupKey])
            : $this->deliveryOf($dispatch, OutboundNotificationChannel::PUSH);
        if ($existing instanceof OutboundNotification) {
            $this->linkAll($existing, $linkTo);

            return $existing;
        }

        if ($content === null || !$channels->push) {
            $row = $this->newDelivery($recipient, $type, OutboundNotificationChannel::PUSH, $intent)
                ->setStatus(OutboundNotificationStatus::SKIPPED)
                ->setSkipReason($content === null ? NotificationDeliverySkipReason::NOT_APPLICABLE : NotificationDeliverySkipReason::DISABLED_BY_PREFERENCE)
                ->setTitle($content?->title)
                ->setGroupKey($groupKey);
            $this->em->persist($row);
            $this->linkAll($row, $linkTo);
            $this->em->flush();

            return $row;
        }

        // External side effect first (the push service call), then its honest record.
        $row = $this->outbound->recordPushSend($recipient, $type->value, $content->title, $content->body, $content->data, $intent->mission);
        $row->setCorrelationId($this->correlation->correlationId())->setGroupKey($groupKey);
        if ($row->getStatus() === OutboundNotificationStatus::SKIPPED) {
            $row->setSkipReason(NotificationDeliverySkipReason::NO_PUSH_SUBSCRIPTION);
        }
        $this->linkAll($row, $linkTo);

        $this->em->getConnection()->transactional(function () use ($row, $dispatch): void {
            $this->em->flush();
            if ($row->getStatus() === OutboundNotificationStatus::SENT) {
                $this->activity->channelSent($row, $dispatch->getId());
            } elseif ($row->getStatus() === OutboundNotificationStatus::FAILED) {
                $this->activity->channelFailed($row, $row->getFailureMessage(), $dispatch->getId());
            }
        });

        return $row;
    }

    /**
     * @param list<NotificationDispatch> $linkTo
     * @return array{status: string, skipReason: ?string, outboundNotificationId: ?int}
     */
    private function deliverEmail(
        NotificationDispatch $dispatch,
        User $recipient,
        NotificationType $type,
        ?EmailContent $content,
        NotificationEmailPolicy $policy,
        NotificationChannels $channels,
        ?OutboundNotification $push,
        ?string $groupKey,
        array $linkTo,
        NotificationIntent $intent,
    ): array {
        $existing = $groupKey !== null
            ? $this->em->getRepository(OutboundNotification::class)->findOneBy(['groupKey' => $groupKey])
            : $this->deliveryOf($dispatch, OutboundNotificationChannel::EMAIL);
        if ($existing instanceof OutboundNotification) {
            $this->linkAll($existing, $linkTo);
            $this->em->flush();

            return self::outcome($existing);
        }

        $address = $intent->emailAddress ?? $recipient->getEmail();
        $skip = match (true) {
            $content === null => NotificationDeliverySkipReason::NOT_APPLICABLE,
            !$channels->email => NotificationDeliverySkipReason::DISABLED_BY_PREFERENCE,
            $policy === NotificationEmailPolicy::FALLBACK_WHEN_PUSH_NOT_SENT
                && $push?->getStatus() === OutboundNotificationStatus::SENT => NotificationDeliverySkipReason::COVERED_BY_PUSH,
            $address === null || $address === '' => NotificationDeliverySkipReason::NO_EMAIL_ADDRESS,
            default => null,
        };

        if ($skip !== null) {
            $row = $this->newDelivery($recipient, $type, OutboundNotificationChannel::EMAIL, $intent)
                ->setStatus(OutboundNotificationStatus::SKIPPED)->setSkipReason($skip)
                ->setSubject($content?->subject)->setGroupKey($groupKey);
            $this->em->persist($row);
            $this->linkAll($row, $linkTo);
            $this->em->flush();

            return self::outcome($row);
        }

        // D-083: the email is a "fallback of" the push only when the push was really
        // attempted and not accepted (failed, or no device) — not when push was disabled
        // or does not exist for this notification.
        $isFallback = $policy === NotificationEmailPolicy::FALLBACK_WHEN_PUSH_NOT_SENT
            && $push !== null
            && $push->getStatus() !== OutboundNotificationStatus::SENT
            && !in_array($push->getSkipReason(), [NotificationDeliverySkipReason::DISABLED_BY_PREFERENCE, NotificationDeliverySkipReason::NOT_APPLICABLE], true);

        // QUEUED row + message + timeline entry commit together (the Doctrine transport
        // shares the connection): no email is ever sent without its delivery record.
        $connection = $this->em->getConnection();
        $row = $connection->transactional(function () use ($recipient, $type, $content, $groupKey, $linkTo, $intent, $isFallback, $push, $dispatch, $address): OutboundNotification {
            $documents = array_map(static fn (DocumentReference $d) => $d->toArray(), $content->attachments);
            $row = $this->outbound->recordEmailQueued(
                $recipient,
                $type->value,
                $content->subject,
                rawData: [
                    'missionId' => $intent->mission?->getId(),
                    // A confidential email's links carry its secret: never recorded.
                    'url' => $intent->confidential ? null : ($content->context['missionUrl'] ?? null),
                    'notificationType' => $type->value,
                    'attachments' => $documents ?: null,
                ],
                mission: $intent->mission,
                fallbackOf: $isFallback ? $push : null,
                fallbackReason: $isFallback ? OutboundNotificationService::fallbackReasonFor($push) : null,
                recipientAddress: $address,
            );
            $row->setCorrelationId($this->correlation->correlationId())->setGroupKey($groupKey);
            $this->linkAll($row, $linkTo);
            $this->em->flush();

            $this->activity->channelQueued($row, $dispatch->getId());

            $this->bus->dispatch(new SendTemplatedEmailMessage(
                to: (string) $address,
                subject: $content->subject,
                fromAddress: $this->fromAddress,
                fromName: $this->fromName,
                htmlTemplate: $content->htmlTemplate,
                context: $content->context,
                textTemplate: $content->textTemplate,
                outboundNotificationId: $row->getId(),
                documents: $documents,
                storeRenderedBody: !$intent->confidential,
            ));

            return $row;
        });

        return self::outcome($row);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * H4 (D-130) — an in-app item created or a push accepted proves the communication now;
     * an email only once the relay accepts it (OutboundNotificationService::recordEmailAttempt).
     *
     * @param array<string, array<string, mixed>> $outcomes
     */
    private function proveCommunication(NotificationDispatch $dispatch, array $outcomes): void
    {
        if ($dispatch->getCommunicates() === null) {
            return;
        }
        if (($outcomes['IN_APP']['status'] ?? null) !== 'SENT' && ($outcomes['PUSH']['status'] ?? null) !== 'SENT') {
            return;
        }
        try {
            $this->communications->activateFromDispatch($dispatch);
        } catch (\Throwable $e) {
            $this->logger->error('NotificationDispatcher: mission communication not recorded', [
                'dispatchId' => $dispatch->getId(), 'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<string, array<string, mixed>> $outcomes */
    private function recordDispatched(NotificationDispatch $dispatch, array $outcomes): void
    {
        if (!$this->activity->isEnabled()) {
            return;
        }
        try {
            $this->em->getConnection()->transactional(fn () => $this->activity->dispatched($dispatch, $outcomes));
        } catch (\Throwable $e) {
            $this->logger->error('NotificationDispatcher: NOTIFICATION_DISPATCHED trace failed', [
                'dispatchId' => $dispatch->getId(), 'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Failure isolation: a channel that throws is logged and reported FAILED for this
     * dispatch, the next channel still runs.
     *
     * @param callable(): (array<string, mixed>|OutboundNotification) $deliver
     * @return array{status: string, skipReason: ?string, outboundNotificationId: ?int}
     */
    private function guard(string $channel, NotificationIntent $intent, callable $deliver): array
    {
        try {
            $result = $deliver();

            return $result instanceof OutboundNotification ? self::outcome($result) : $result;
        } catch (\Throwable $e) {
            $this->logger->error('NotificationDispatcher: channel failed', [
                'channel' => $channel, 'type' => $intent->type->value,
                'userId' => $intent->recipient->getId(), 'missionId' => $intent->mission?->getId(),
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'FAILED', 'skipReason' => null, 'outboundNotificationId' => null];
        }
    }

    private function newDelivery(User $recipient, NotificationType $type, OutboundNotificationChannel $channel, NotificationIntent $intent): OutboundNotification
    {
        return (new OutboundNotification())
            ->setRecipientUser($recipient)
            ->setChannel($channel)
            ->setNotificationType($type->value)
            ->setMission($intent->mission)
            ->setPayload(OutboundNotificationService::cleanPayload(['missionId' => $intent->mission?->getId(), 'notificationType' => $type->value]))
            ->setCorrelationId($this->correlation->correlationId());
    }

    private function deliveryOf(NotificationDispatch $dispatch, OutboundNotificationChannel $channel): ?OutboundNotification
    {
        foreach ($dispatch->getDeliveries() as $delivery) {
            if ($delivery->getChannel() === $channel) {
                return $delivery;
            }
        }

        return null;
    }

    /** @param list<NotificationDispatch> $dispatches */
    private function linkAll(OutboundNotification $delivery, array $dispatches): void
    {
        foreach ($dispatches as $dispatch) {
            $dispatch->addDelivery($delivery);
        }
    }

    private function groupKey(NotificationType $type, User $recipient, OutboundNotificationChannel $channel): string
    {
        return hash('sha256', implode('|', ['group', $type->value, (string) $recipient->getId(), $channel->value, $this->correlation->correlationId()]));
    }

    /** @return array{status: string, skipReason: ?string, outboundNotificationId: ?int} */
    private static function outcome(OutboundNotification $row): array
    {
        return [
            'status'                 => $row->getStatus()->value,
            'skipReason'             => $row->getSkipReason()?->value,
            'outboundNotificationId' => $row->getId(),
        ];
    }
}
