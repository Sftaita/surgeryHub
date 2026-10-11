<?php

namespace App\Service;

use App\Entity\SurgeonAbsenceCommunicationDelivery;
use App\Enum\AbsenceCommunicationType;
use App\Notification\EmailContent;
use App\Notification\TransactionalEmailSender;
use Doctrine\ORM\EntityManagerInterface;

/**
 * H5b (D-132) — hands ONE D-114 delivery to the common low-level email layer: the same
 * transport as before (SendTemplatedEmailMessage, carrying the D-114 delivery id so its own
 * journal still moves SCHEDULED → SENT / FAILED), plus the OutboundNotification record
 * (attempts, honest status, correlationId, NOTIFICATION_CHANNEL_* events) linked to the
 * delivery. No preference resolver (an organisational communication; block management is an
 * external address), no fake User: a colleague delivery belongs to the colleague, an external
 * one carries a readable label.
 */
class AbsenceCommunicationDeliverySender
{
    public function __construct(
        private readonly TransactionalEmailSender $sender,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @param list<string>          $cc
     */
    public function send(SurgeonAbsenceCommunicationDelivery $delivery, string $to, string $subject, string $htmlTemplate, array $context, array $cc = [], ?string $replyTo = null): void
    {
        $communication = $delivery->getCommunication();
        $isRoomRelease = $communication?->getType() === AbsenceCommunicationType::ROOM_RELEASE;
        $label = $delivery->getRecipient() !== null
            ? (string) \App\Activity\ActivityJournal::displayName($delivery->getRecipient())
            : sprintf('Gestion du bloc — %s', $communication?->getSite()?->getName() ?? 'site');

        $outbound = $this->sender->queue(
            $isRoomRelease ? 'ABSENCE_COMMUNICATION_ROOM_RELEASE' : 'ABSENCE_COMMUNICATION_BLOCK_MANAGEMENT',
            $to,
            $label,
            new EmailContent($subject, $htmlTemplate, $context),
            $delivery->getRecipient(),
            $cc,
            $replyTo,
            causeAnchors: array_values(array_filter([
                $communication !== null ? 'surgeon_absence_communication:' . $communication->getId() : null,
                $communication?->getAbsence() !== null ? 'absence:' . $communication->getAbsence()->getId() : null,
            ])),
            absenceCommunicationDeliveryId: $delivery->getId(),
        );

        $this->em->getConnection()->executeStatement(
            'UPDATE surgeon_absence_communication_delivery SET outbound_notification_id = ? WHERE id = ?',
            [$outbound->getId(), $delivery->getId()],
        );
    }
}
