<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\OutboundNotification;
use App\Entity\OutboundNotificationAttempt;
use App\Entity\User;
use App\Enum\OutboundNotificationChannel;
use App\Enum\OutboundNotificationFallbackReason;
use App\Enum\OutboundNotificationStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-084 — historique centralisé des communications sortantes (Push + email). Chaque
 * canal crée sa PROPRE OutboundNotification (jamais une ligne partagée) ; le repli
 * Push → email est exprimé par la relation fallbackOf, pas par un statut composite.
 *
 * `SENT` signifie uniquement "accepté par le fournisseur/transport" — jamais "lu".
 *
 * N'écrit jamais : endpoint Push complet, p256dh, auth, JWT, clé VAPID, secret SMTP,
 * mot de passe, donnée patient. `payload` est toujours nettoyé (cleanPayload()) avant
 * persistance — jamais un tableau arbitraire.
 */
class OutboundNotificationService
{
    private const PAYLOAD_ALLOWLIST = ['missionId', 'planningVersionId', 'url', 'notificationType'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WebPushService $webPushService,
    ) {
    }

    /**
     * @param array<string, mixed> $rawData Push "data" payload before cleaning (title/body already separate).
     */
    public function recordPushSend(
        User $recipient,
        string $notificationType,
        string $title,
        string $body,
        array $rawData = [],
        ?Mission $mission = null,
    ): OutboundNotification {
        $result = $this->webPushService->sendToUserWithAttempts($recipient, $title, $body, $rawData);
        $attempts = $result['attempts'];
        $now = new \DateTimeImmutable();

        $notification = (new OutboundNotification())
            ->setRecipientUser($recipient)
            ->setChannel(OutboundNotificationChannel::PUSH)
            ->setNotificationType($notificationType)
            ->setTitle($title)
            ->setBodyText($body)
            ->setPayload(self::cleanPayload($rawData))
            ->setMission($mission)
            ->setAttemptCount(count($attempts));

        if (empty($attempts)) {
            $notification->setStatus(OutboundNotificationStatus::SKIPPED);
        } elseif ($result['sent'] > 0) {
            $notification->setStatus(OutboundNotificationStatus::SENT)->setSentAt($now);
        } else {
            $notification->setStatus(OutboundNotificationStatus::FAILED)
                ->setFailedAt($now)
                ->setFailureMessage($attempts[0]['reason'] ?? null);
        }

        $this->em->persist($notification);

        foreach ($attempts as $i => $attempt) {
            $attemptEntity = (new OutboundNotificationAttempt())
                ->setAttemptNumber($i + 1)
                ->setStartedAt($now)
                ->setFinishedAt($now)
                ->setSuccess($attempt['success'])
                ->setStatusCode($attempt['statusCode'])
                ->setReason($attempt['reason'])
                ->setProvider($attempt['provider']);
            $notification->addAttempt($attemptEntity);
            $this->em->persist($attemptEntity);
        }

        $this->em->flush();

        return $notification;
    }

    /**
     * Determines the fallback reason from a completed Push attempt's OutboundNotification
     * — call after recordPushSend() when its status isn't SENT, before queuing the email.
     */
    public static function fallbackReasonFor(OutboundNotification $pushNotification): OutboundNotificationFallbackReason
    {
        $attempts = $pushNotification->getAttempts();
        if ($attempts->isEmpty()) {
            return OutboundNotificationFallbackReason::NO_SUBSCRIPTION;
        }

        foreach ($attempts as $attempt) {
            if ($attempt->getReason() !== 'expired') {
                return OutboundNotificationFallbackReason::ALL_FAILED;
            }
        }

        return OutboundNotificationFallbackReason::EXPIRED;
    }

    /**
     * Creates the OutboundNotification row BEFORE dispatching SendTemplatedEmailMessage —
     * its id must be embedded in the message so the handler/listener can update the same
     * row rather than creating a new one (never a new OutboundNotification per retry).
     *
     * $bodyText/$bodyHtml are usually still null here: this codebase renders Twig inside
     * the async handler, not at dispatch time, so the true sent content isn't known yet.
     * recordEmailAttempt() backfills them from the handler once actually rendered.
     *
     * @param array<string, mixed> $rawData
     */
    public function recordEmailQueued(
        User $recipient,
        string $notificationType,
        string $subject,
        ?string $bodyText = null,
        ?string $bodyHtml = null,
        array $rawData = [],
        ?Mission $mission = null,
        ?OutboundNotification $fallbackOf = null,
        ?OutboundNotificationFallbackReason $fallbackReason = null,
    ): OutboundNotification {
        $notification = (new OutboundNotification())
            ->setRecipientUser($recipient)
            ->setChannel(OutboundNotificationChannel::EMAIL)
            ->setNotificationType($notificationType)
            ->setStatus(OutboundNotificationStatus::QUEUED)
            ->setSubject($subject)
            ->setBodyText($bodyText)
            ->setBodyHtml($bodyHtml)
            ->setPayload(self::cleanPayload($rawData))
            ->setMission($mission)
            ->setQueuedAt(new \DateTimeImmutable())
            ->setFallbackOf($fallbackOf)
            ->setFallbackReason($fallbackReason);

        $this->em->persist($notification);
        $this->em->flush();

        return $notification;
    }

    /**
     * Called once per real transport attempt: once on eventual success (from the
     * handler), once per Messenger retry that fails (from the failure listener). Never
     * creates a new OutboundNotification — always appends an Attempt to the existing one
     * and updates its aggregate status.
     *
     * $final: for a failure, whether Messenger has exhausted retries (no more will come).
     * A failure that will still retry leaves status at QUEUED — marking FAILED early
     * would be dishonest (D-084: statuses must be honest).
     */
    public function recordEmailAttempt(
        int $notificationId,
        bool $success,
        ?string $reason,
        bool $final = true,
        ?string $bodyText = null,
        ?string $bodyHtml = null,
    ): void {
        $notification = $this->em->find(OutboundNotification::class, $notificationId);
        if (!$notification instanceof OutboundNotification) {
            return;
        }

        $now = new \DateTimeImmutable();
        $notification->incrementAttemptCount();

        $attemptEntity = (new OutboundNotificationAttempt())
            ->setAttemptNumber($notification->getAttemptCount())
            ->setStartedAt($now)
            ->setFinishedAt($now)
            ->setSuccess($success)
            ->setReason($reason)
            ->setProvider('SMTP');
        $notification->addAttempt($attemptEntity);
        $this->em->persist($attemptEntity);

        if ($success) {
            $notification->setStatus(OutboundNotificationStatus::SENT)->setSentAt($now);
            // Backfill the true rendered content — unknown at queue time (Twig renders
            // inside the handler, not at dispatch), known now.
            if ($bodyText !== null) {
                $notification->setBodyText($bodyText);
            }
            if ($bodyHtml !== null) {
                $notification->setBodyHtml($bodyHtml);
            }
        } elseif ($final) {
            $notification->setStatus(OutboundNotificationStatus::FAILED)
                ->setFailedAt($now)
                ->setFailureMessage($reason);
        }

        $this->em->flush();
    }

    /**
     * Strict allowlist — never persist an uncontrolled payload. Silently drops any key
     * not in the allowlist (including anything patient-related that might otherwise ride
     * along in a caller's "data" array).
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function cleanPayload(array $raw): array
    {
        return array_intersect_key($raw, array_flip(self::PAYLOAD_ALLOWLIST));
    }
}
