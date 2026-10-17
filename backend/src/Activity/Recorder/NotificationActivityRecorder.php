<?php

namespace App\Activity\Recorder;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Entity\NotificationDispatch;
use App\Entity\OutboundNotification;
use App\Enum\ActivityEventType;
use App\Enum\ActivityOutcome;
use App\Enum\OutboundNotificationChannel;
use Doctrine\ORM\EntityManagerInterface;

/**
 * H2 (D-128) — timeline entries of the notification pipeline:
 *
 *   NOTIFICATION_DISPATCHED     — the decision + the immediate outcome of each channel
 *                                 (in-app created, channels SKIPPED and why);
 *   NOTIFICATION_CHANNEL_QUEUED — an email handed to the async queue;
 *   NOTIFICATION_CHANNEL_SENT   — email accepted by the SMTP relay / push accepted by the
 *                                 push service. NEVER "received" or "read";
 *   NOTIFICATION_CHANNEL_FAILED — definitive failure (retries exhausted).
 *
 * Idempotent per row and status (idempotencyKey), so a Messenger retry never writes a
 * second "sent". Causality is explicit: the dispatch points to the mission event that
 * caused it (anchor "mission:{id}", registered by the audit mirror), each channel event
 * to its dispatch (anchor "notification_dispatch:{id}") or its queued event
 * ("outbound:{id}"). Callers run inside a transaction (ActivityJournal rule).
 */
class NotificationActivityRecorder
{
    private const TYPE_LABELS = [
        'SURGEON_POST_COVERED'        => 'Mission couverte',
        'SURGEON_POST_UNCOVERED'      => 'Mission sans instrumentiste',
        'PLANNING_MISSION_REASSIGNED' => 'Mission réassignée',
        'PLANNING_MISSION_CANCELLED'  => 'Mission annulée',
        'PLANNING_MISSION_RELEASED'   => 'Retrait d\'une mission',
        'OPEN_MISSION_AVAILABLE'      => 'Mission disponible',
        'MISSION_OFFERED'             => 'Mission proposée',
        'MISSION_ASSIGNED_DIRECTLY'   => 'Mission attribuée',
        'MISSION_OFFER_DECLINED'      => 'Proposition refusée',
        // D-151 — managers only (the instrumentist's own notification stays PLANNING_MISSION_RELEASED).
        'ASSIGNMENT_REFUSED_BY_SURGEON' => "Refus d'affectation par le chirurgien",
        'ASSIGNMENT_REVIEW_REQUESTED'   => 'Intervention demandée sur une affectation',
        'ABSENCE_MISSION_CANCELLED'   => 'Mission annulée (absence)',
        'PLANNING_CHANGE_SUMMARY'     => 'Modification de votre planning',
        // H4 / H5a — every type the dispatcher now carries reads as words in the timeline.
        'PLANNING_DEPLOYED_INSTRUMENTIST'  => 'Planning publié',
        'PLANNING_DEPLOYED_SURGEON'        => 'Planning publié',
        'PLANNING_DEPLOYED_MANAGER'        => 'Confirmation de déploiement',
        'PLANNING_RESENT_MANUAL'           => 'Planning renvoyé',
        'PLANNING_ALERT'                   => 'Alerte planning',
        'MISSION_UNCOVERED_ESCALATION'     => 'Instrumentiste toujours recherchée',
        'SURGEON_MISSION_OPEN_PUBLISHED'   => 'Mission ouverte',
        'ABSENCE_SELF_DECLARED'            => 'Absence déclarée',
        'ABSENCE_OCCURRENCE_CANCELLED'     => 'Créneau impacté par une indisponibilité',
        'ABSENCE_OCCURRENCE_UNCOVERED'     => 'Créneau à recouvrir',
        'ABSENCE_OCCURRENCE_RESTORED'      => 'Créneau à nouveau disponible',
        'ABSENCE_SURGEON_MISSION_OPENED'   => 'Mission à recouvrir (indisponibilité)',
        'ABSENCE_INSTRUMENTIST_REASSIGNED' => 'Mission réattribuée (indisponibilité)',
        'ABSENCE_IMPACT_SUMMARY'           => 'Résumé d\'impact d\'une absence',
        'MISSION_RESTORED'                 => 'Mission rétablie',
        'ENCODING_REMINDER_D1'             => 'Rappel d\'encodage',
        'ENCODING_ANOMALY_RESOLVED'        => 'Signalement traité',
        'CATALOGUE_REQUEST_RESOLVED'       => 'Proposition catalogue acceptée',
        'CATALOGUE_REQUEST_IGNORED'        => 'Proposition catalogue non retenue',
        'CATALOGUE_REQUEST_CREATED'        => 'Nouvelle proposition catalogue',
        'SURGEON_MISSION_REQUEST_ACCEPTED' => 'Demande de mission acceptée',
        'SURGEON_MISSION_REQUEST_REJECTED' => 'Demande de mission refusée',
        'ABSENCE_REQUEST_MISSING'          => 'Demande des congés à venir',
        'ABSENCE_ENCODED_RECAP'            => 'Récapitulatif des congés encodés',
        'ACCOUNT_INVITATION'               => 'Invitation au compte',
        'ACCOUNT_EMAIL_CHANGED'            => 'Changement d\'adresse email',
        // H5b — former direct in-app / push, now through the dispatcher.
        'MISSION_DECLARED'                 => 'Mission déclarée à valider',
        'MISSION_DECLARED_APPROVED'        => 'Mission déclarée validée',
        'MISSION_DECLARED_REJECTED'        => 'Mission déclarée refusée',
        'ENCODING_ANOMALY_REPORTED'        => 'Anomalie d\'encodage signalée',
        'ENCODING_CORRECTION_REQUESTED'    => 'Correction d\'encodage demandée',
        'SURGEON_MISSION_REQUEST_CREATED'  => 'Nouvelle demande de mission',
        'ROOM_TAKEOVER_MISSION_OFFER'      => 'Salle reprise — mission disponible',
        // H5b — transactional deliveries on the common low-level layer (no user notification).
        'FIRM_INVOICE_DOCUMENT'            => 'Facture firme',
        'INSTRUMENTIST_STATEMENT_DOCUMENT' => 'Décompte instrumentiste',
        'ABSENCE_COMMUNICATION_ROOM_RELEASE'     => 'Libération de salle (collègues)',
        'ABSENCE_COMMUNICATION_BLOCK_MANAGEMENT' => 'Communication gestion du bloc',
    ];

    private const CHANNEL_LABELS = ['IN_APP' => 'in-app', 'EMAIL' => 'email', 'PUSH' => 'push'];

    private const SKIP_LABELS = [
        'DISABLED_BY_PREFERENCE' => 'désactivé par préférence',
        'NO_PUSH_SUBSCRIPTION'   => 'aucun appareil push',
        'NO_EMAIL_ADDRESS'       => 'aucune adresse email',
        'COVERED_BY_PUSH'        => 'non nécessaire (push accepté)',
        'NOT_APPLICABLE'         => 'non applicable',
    ];

    public function __construct(
        private readonly ActivityJournal $journal,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->journal->isEnabled();
    }

    /** @param array<string, array{status: string, skipReason: ?string, outboundNotificationId: ?int, grouped?: bool}> $channels */
    public function dispatched(NotificationDispatch $dispatch, array $channels): ?int
    {
        $name  = $dispatch->getRecipientNameSnapshot() ?? 'destinataire inconnu';
        $parts = [];
        foreach (['IN_APP', 'EMAIL', 'PUSH'] as $channel) {
            if (!isset($channels[$channel])) {
                continue;
            }
            $parts[] = self::CHANNEL_LABELS[$channel] . ' : ' . self::statusText($channel, $channels[$channel]);
        }

        $missionId = $dispatch->getMission()?->getId();

        return $this->journal->record(new ActivityRecord(
            eventType: ActivityEventType::NOTIFICATION_DISPATCHED,
            subjectType: 'NotificationDispatch',
            subjectId: $dispatch->getId(),
            subjectLabel: sprintf('« %s » → %s', self::label($dispatch->getNotificationType()), $name),
            summary: sprintf('Notification « %s » pour %s — %s', self::label($dispatch->getNotificationType()), $name, implode(' ; ', $parts)),
            payload: [
                'dispatchId'       => $dispatch->getId(),
                'notificationType' => $dispatch->getNotificationType(),
                'recipientUserId'  => $dispatch->getRecipient()?->getId(),
                'recipientName'    => $name,
                'missionId'        => $missionId,
                'mode'             => $dispatch->getMode()->value,
                'episodeRef'       => $dispatch->getEpisodeRef(),
                'channelsSource'   => $dispatch->getChannelsResolved()['source'] ?? null,
                'channels'         => $channels,
            ],
            missionId: $missionId,
            siteId: $dispatch->getMission()?->getSite()?->getId(),
            userId: $dispatch->getRecipient()?->getId(),
            idempotencyKey: 'notification_dispatch:' . $dispatch->getId(),
            causeAnchors: $missionId !== null ? ['mission:' . $missionId] : [],
            anchorKey: 'notification_dispatch:' . $dispatch->getId(),
        ), $this->em->getConnection());
    }

    /** @param list<string> $causeAnchors H5b — the business event a transactional email (document, D-114) belongs to */
    public function channelQueued(OutboundNotification $delivery, ?int $dispatchId, array $causeAnchors = []): ?int
    {
        if ($causeAnchors !== []) {
            return $this->channelEvent(ActivityEventType::NOTIFICATION_CHANNEL_QUEUED, $delivery, $dispatchId, ActivityOutcome::SUCCESS,
                sprintf('%s « %s » mis en file pour %s', ucfirst(self::channel($delivery)), self::label($delivery->getNotificationType()), self::recipient($delivery)),
                [], $causeAnchors, 'outbound:' . $delivery->getId());
        }

        return $this->channelEvent(ActivityEventType::NOTIFICATION_CHANNEL_QUEUED, $delivery, $dispatchId, ActivityOutcome::SUCCESS,
            sprintf('%s « %s » mis en file pour %s', ucfirst(self::channel($delivery)), self::label($delivery->getNotificationType()), self::recipient($delivery)),
            // NOTIFICATION_DISPATCHED is written once every channel is settled (it carries
            // their outcomes), i.e. after this event: the queued email is therefore linked to
            // the root cause it shares with the dispatch (the mission event), and to the
            // dispatch itself through payload.dispatchId — never to an event that does not
            // exist yet.
            [], ['notification_dispatch:' . $dispatchId, 'mission:' . $delivery->getMission()?->getId()], 'outbound:' . $delivery->getId());
    }

    public function channelSent(OutboundNotification $delivery, ?int $dispatchId = null): ?int
    {
        $accepted = $delivery->getChannel() === OutboundNotificationChannel::PUSH ? 'accepté par le service push' : 'accepté par le serveur d\'envoi';

        return $this->channelEvent(ActivityEventType::NOTIFICATION_CHANNEL_SENT, $delivery, $dispatchId, ActivityOutcome::SUCCESS,
            sprintf('%s « %s » pour %s : %s', ucfirst(self::channel($delivery)), self::label($delivery->getNotificationType()), self::recipient($delivery), $accepted),
            [], ['outbound:' . $delivery->getId(), 'notification_dispatch:' . $dispatchId]);
    }

    public function channelFailed(OutboundNotification $delivery, ?string $reason, ?int $dispatchId = null): ?int
    {
        return $this->channelEvent(ActivityEventType::NOTIFICATION_CHANNEL_FAILED, $delivery, $dispatchId, ActivityOutcome::FAILED,
            sprintf('Échec %s « %s » pour %s%s', self::channel($delivery), self::label($delivery->getNotificationType()), self::recipient($delivery), $reason !== null ? ' : ' . $reason : ''),
            ['failureReason' => $reason, 'final' => true], ['outbound:' . $delivery->getId(), 'notification_dispatch:' . $dispatchId]);
    }

    /**
     * @param array<string, mixed> $extra
     * @param list<string>         $causeAnchors
     */
    private function channelEvent(
        ActivityEventType $type,
        OutboundNotification $delivery,
        ?int $dispatchId,
        ActivityOutcome $outcome,
        string $summary,
        array $extra,
        array $causeAnchors,
        ?string $anchorKey = null,
    ): ?int {
        $missionId = $delivery->getMission()?->getId();

        return $this->journal->record(new ActivityRecord(
            eventType: $type,
            subjectType: 'OutboundNotification',
            subjectId: $delivery->getId(),
            subjectLabel: sprintf('%s « %s » → %s', ucfirst(self::channel($delivery)), self::label($delivery->getNotificationType()), self::recipient($delivery)),
            summary: $summary,
            payload: [
                'outboundNotificationId' => $delivery->getId(),
                'dispatchId'             => $dispatchId,
                'notificationType'       => $delivery->getNotificationType(),
                'channel'                => $delivery->getChannel()?->value,
                'recipientUserId'        => $delivery->getRecipientUser()?->getId(),
                'recipientName'          => self::recipient($delivery),
                'missionId'              => $missionId,
                'attemptCount'           => $delivery->getAttemptCount(),
                'grouped'                => $delivery->getGroupKey() !== null,
            ] + $extra,
            missionId: $missionId,
            userId: $delivery->getRecipientUser()?->getId(),
            outcome: $outcome,
            idempotencyKey: sprintf('outbound:%d:%s', $delivery->getId(), substr($type->value, strlen('NOTIFICATION_CHANNEL_'))),
            causeAnchors: array_values(array_filter($causeAnchors, static fn (string $a) => !str_ends_with($a, ':'))),
            anchorKey: $anchorKey,
        ), $this->em->getConnection());
    }

    /** @param array{status: string, skipReason: ?string} $outcome */
    private static function statusText(string $channel, array $outcome): string
    {
        return match ($outcome['status']) {
            'SENT'    => $channel === 'IN_APP' ? 'créée' : 'accepté par le service',
            'QUEUED'  => 'en file d\'envoi',
            'FAILED'  => 'échec',
            'SKIPPED' => 'non envoyé (' . (self::SKIP_LABELS[$outcome['skipReason'] ?? ''] ?? 'raison inconnue') . ')',
            default   => strtolower($outcome['status']),
        };
    }

    public static function label(?string $type): string
    {
        return self::TYPE_LABELS[$type ?? ''] ?? (string) $type;
    }

    private static function channel(OutboundNotification $delivery): string
    {
        return self::CHANNEL_LABELS[$delivery->getChannel()?->value ?? ''] ?? 'canal';
    }

    private static function recipient(OutboundNotification $delivery): string
    {
        // H5b — a delivery to a non-user address (billing, D-114) carries a readable label; the
        // address itself never appears in the journal.
        return ActivityJournal::displayName($delivery->getRecipientUser()) ?? $delivery->getRecipientLabel() ?? 'destinataire externe';
    }
}
