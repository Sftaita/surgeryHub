<?php

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;

/**
 * H5a — every email now leaves its trace (notification_dispatch, outbound_notification +
 * attempts, mission_communication), including invitations: a test removing the users it
 * created must remove these rows first (outbound_notification.recipient_user_id has no
 * cascade, by design — the delivery history is not deleted with a user by accident).
 */
trait PurgesNotificationTraces
{
    /** @param int[] $userIds */
    private function purgeNotificationTraces(EntityManagerInterface $em, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }
        $ids  = implode(',', array_map('intval', $userIds));
        $conn = $em->getConnection();
        $conn->executeStatement("DELETE FROM mission_communication WHERE user_id IN ($ids)");
        $conn->executeStatement("DELETE FROM notification_dispatch WHERE recipient_user_id IN ($ids)");
        $conn->executeStatement("DELETE a FROM outbound_notification_attempt a JOIN outbound_notification o ON o.id = a.notification_id WHERE o.recipient_user_id IN ($ids)");
        $conn->executeStatement("UPDATE outbound_notification SET fallback_of_id = NULL WHERE recipient_user_id IN ($ids)");
        $conn->executeStatement("DELETE FROM outbound_notification WHERE recipient_user_id IN ($ids)");
        $conn->executeStatement("DELETE FROM notification_event WHERE user_id IN ($ids)");
    }
}
