<?php

namespace App\Notification;

use App\Activity\CorrelationContext;
use App\Activity\Recorder\NotificationActivityRecorder;
use App\Entity\Mission;
use App\Entity\OutboundNotification;
use App\Entity\User;
use App\Enum\OutboundNotificationChannel;
use App\Enum\OutboundNotificationStatus;
use App\Message\SendTemplatedEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * H5b (D-132) — the COMMON LOW-LEVEL email delivery for the transactional communications that
 * are not a user notification: financial documents (to a firm's billing address, an explicit
 * address) and D-114 absence communications (colleagues, external block management).
 *
 * Same delivery record as every notification email (H2/H5a): one OutboundNotification QUEUED
 * → attempts → SENT (accepted by the relay, never "received") / FAILED after the retries,
 * correlationId, NOTIFICATION_CHANNEL_* timeline events, documents by reference — and the same
 * transport (SendTemplatedEmailMessage, SendTemplatedEmailMessageHandler, failure listener).
 *
 * What it deliberately does NOT do: no NotificationPreferenceResolver (a document or an
 * organisational communication is mandatory, and an external address has no preferences), no
 * NotificationDispatch (no user decision to record), no in-app item, no fake User.
 */
class TransactionalEmailSender
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationActivityRecorder $activity,
        private readonly CorrelationContext $correlation,
        private readonly MessageBusInterface $bus,
        #[Autowire('%env(string:MAILER_FROM_ADDRESS)%')]
        private readonly string $fromAddress,
        #[Autowire('%env(string:MAILER_FROM_NAME)%')]
        private readonly string $fromName,
    ) {
    }

    /**
     * Records the QUEUED delivery, its timeline event and the message in ONE transaction
     * (the Doctrine transport shares the connection): never an email without its record.
     *
     * @param list<DocumentReference> $documents
     * @param list<string>            $cc
     * @param list<string>            $causeAnchors the business event this email belongs to
     */
    public function queue(
        string $notificationType,
        string $to,
        string $recipientLabel,
        EmailContent $content,
        ?User $recipientUser = null,
        array $cc = [],
        ?string $replyTo = null,
        ?Mission $mission = null,
        array $causeAnchors = [],
        ?int $absenceCommunicationDeliveryId = null,
    ): OutboundNotification {
        return $this->em->getConnection()->transactional(function () use (
            $notificationType, $to, $recipientLabel, $content, $recipientUser, $cc, $replyTo, $mission, $causeAnchors, $absenceCommunicationDeliveryId,
        ): OutboundNotification {
            $documents = array_map(static fn (DocumentReference $d) => $d->toArray(), $content->attachments);
            $row = (new OutboundNotification())
                ->setRecipientUser($recipientUser)
                ->setRecipientLabel($recipientLabel)
                ->setRecipientAddress($to)
                ->setChannel(OutboundNotificationChannel::EMAIL)
                ->setNotificationType($notificationType)
                ->setStatus(OutboundNotificationStatus::QUEUED)
                ->setSubject($content->subject)
                ->setMission($mission)
                ->setQueuedAt(new \DateTimeImmutable())
                ->setCorrelationId($this->correlation->correlationId())
                ->setPayload(array_filter([
                    'notificationType' => $notificationType,
                    'missionId' => $mission?->getId(),
                    'attachments' => $documents ?: null,
                ], static fn ($v) => $v !== null));
            $this->em->persist($row);
            $this->em->flush();

            $this->activity->channelQueued($row, null, $causeAnchors);

            $this->bus->dispatch(new SendTemplatedEmailMessage(
                to: $to,
                subject: $content->subject,
                fromAddress: $this->fromAddress,
                fromName: $this->fromName,
                htmlTemplate: $content->htmlTemplate,
                context: $content->context,
                textTemplate: $content->textTemplate,
                outboundNotificationId: $row->getId(),
                absenceCommunicationDeliveryId: $absenceCommunicationDeliveryId,
                cc: $cc,
                replyTo: $replyTo,
                documents: $documents,
            ));

            return $row;
        });
    }
}
