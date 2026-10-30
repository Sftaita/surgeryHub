<?php

namespace App\Service;

use App\Activity\ActivityJournal;
use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionStatus;
use App\Enum\ReleasedRoomSlotStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-177 — « Planning → Salles libérées » : the permanent, chronological history of ONE released
 * room, and its current operational state. Read-only, rebuilt on every call from what already
 * records the chain — never a second journal:
 *
 *   absence (source of the room) → ROOM_SLOT_MADE_AVAILABLE → SURGEON_MISSION_REQUEST_* →
 *   ROOM_SLOT_TAKEN_OVER → mission audit trail (MISSION_ADDED_POST_DEPLOY,
 *   ROOM_TAKEOVER_PRIORITY_OFFERED / _BYPASSED / _ENDED, MISSION_PUBLISHED_TO_POOL,
 *   MISSION_CLAIMED_FROM_POOL, assignments, cancellation…) → ROOM_SLOT_REOPENED → next take-over…
 *   plus the delivery of the priority offer and of the pool solicitations (outbound_notification).
 *
 * Every mission ever attached to the room (each take-over, even cancelled, even after a new
 * take-over) is found through the room's own events (`roomSlotId` in their payload) and the
 * current takeover_mission — nothing disappears from the manager's follow-up.
 */
class ReleasedRoomSlotHistoryService
{
    /** Encoding / finance events of the mission are not part of the room's story. */
    private const EXCLUDED_CATEGORIES = [AuditEventType::CATEGORY_ENCODING, AuditEventType::CATEGORY_FINANCE];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return array{slotId: int, current: array<string, mixed>, events: list<array<string, mixed>>} */
    public function history(ReleasedOperatingRoomSlot $slot): array
    {
        $connection = $this->em->getConnection();
        $slotId = (int) $slot->getId();
        $events = [];

        $absence = $slot->getSourceAbsence();
        if ($absence !== null) {
            $events[] = [
                'key'       => 'absence-' . $absence->getId(),
                'at'        => $absence->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'type'      => 'ABSENCE_DECLARED',
                'actor'     => $this->actor($absence->getCreatedBy()),
                'missionId' => null,
                'details'   => [
                    'absenceId'   => $absence->getId(),
                    'dateStart'   => $absence->getDateStart()->format('Y-m-d'),
                    'dateEnd'     => $absence->getDateEnd()->format('Y-m-d'),
                    'reason'      => $absence->getReason(),
                    'surgeonId'   => $absence->getUser()?->getId(),
                    'surgeonName' => $absence->getUser()?->getDrName(),
                ],
            ];
        }

        // The room's own events (made available, taken over, reopened, regularized).
        $slotRows = $connection->fetchAllAssociative(
            "SELECT id, actor_id, mission_id, event_type, payload, created_at FROM audit_event
              WHERE event_type LIKE 'ROOM\\_SLOT\\_%' AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.roomSlotId')) = ?
              ORDER BY id",
            [(string) $slotId],
        );

        $missionIds = [];
        foreach ($slotRows as $row) {
            if ($row['mission_id'] !== null) {
                $missionIds[(int) $row['mission_id']] = true;
            }
        }
        if ($slot->getTakeoverMission()?->getId() !== null) {
            $missionIds[(int) $slot->getTakeoverMission()->getId()] = true;
        }
        $missionIds = array_keys($missionIds);

        $missionRows = $missionIds === [] ? [] : $connection->fetchAllAssociative(
            'SELECT id, actor_id, mission_id, event_type, payload, created_at FROM audit_event
              WHERE mission_id IN (?) ORDER BY id',
            [$missionIds],
            [ArrayParameterType::INTEGER],
        );

        // Requests that became one of these missions: their creation (global event).
        $requestIds = [];
        foreach ($missionRows as $row) {
            $payload = json_decode((string) $row['payload'], true) ?: [];
            if (isset($payload['surgeonMissionRequestId'])) {
                $requestIds[(int) $payload['surgeonMissionRequestId']] = true;
            }
        }
        $requestRows = [];
        foreach (array_keys($requestIds) as $requestId) {
            $requestRows = array_merge($requestRows, $connection->fetchAllAssociative(
                "SELECT id, actor_id, mission_id, event_type, payload, created_at FROM audit_event
                  WHERE event_type = 'SURGEON_MISSION_REQUEST_CREATED' AND mission_id IS NULL
                    AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.surgeonMissionRequestId')) = ?",
                [(string) $requestId],
            ));
        }

        $seen = [];
        $hasMadeAvailable = false;
        foreach ([...$slotRows, ...$requestRows, ...$missionRows] as $row) {
            if (isset($seen[$row['id']])) {
                continue;
            }
            $seen[$row['id']] = true;
            $type = AuditEventType::tryFrom((string) $row['event_type']);
            if ($type === null || in_array($type->category(), self::EXCLUDED_CATEGORIES, true)) {
                continue;
            }
            $hasMadeAvailable = $hasMadeAvailable || $type === AuditEventType::ROOM_SLOT_MADE_AVAILABLE;
            $events[] = [
                'key'       => 'audit-' . $row['id'],
                'at'        => $this->atom((string) $row['created_at']),
                'type'      => $type->value,
                'actor'     => $this->actorById((int) $row['actor_id']),
                'missionId' => $row['mission_id'] !== null ? (int) $row['mission_id'] : null,
                'details'   => json_decode((string) $row['payload'], true) ?: [],
            ];
        }

        if (!$hasMadeAvailable) {
            // Rooms created before the audit existed (backfills): the row itself is the trace.
            $events[] = [
                'key'       => 'slot-' . $slotId,
                'at'        => $slot->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'type'      => AuditEventType::ROOM_SLOT_MADE_AVAILABLE->value,
                'actor'     => null,
                'missionId' => null,
                'details'   => ['roomSlotId' => $slotId],
            ];
        }

        // Who was solicited, and how it reached them.
        if ($missionIds !== []) {
            foreach ($connection->fetchAllAssociative(
                "SELECT n.mission_id, n.notification_type, n.recipient_user_id, n.channel, n.status, n.skip_reason,
                        n.created_at, u.firstname, u.lastname
                   FROM outbound_notification n LEFT JOIN user u ON u.id = n.recipient_user_id
                  WHERE n.mission_id IN (?) AND n.notification_type IN ('ROOM_TAKEOVER_MISSION_OFFER', 'OPEN_MISSION_AVAILABLE')
                  ORDER BY n.id",
                [$missionIds],
                [ArrayParameterType::INTEGER],
            ) as $n) {
                $events[] = $this->notificationEvent($n);
            }
            $events = $this->mergeNotificationEvents($events);
        }

        usort($events, static fn (array $a, array $b) => [$a['at'], $a['key']] <=> [$b['at'], $b['key']]);

        return [
            'slotId'  => $slotId,
            'current' => self::operationalState($slot),
            'events'  => $events,
        ];
    }

    /**
     * Current operational state of a released room, decided here only (list + history):
     * AVAILABLE · PRIORITY_PENDING (recipient, deadline, next automatic action) · POOL_OPEN ·
     * INSTRUMENTIST_CONFIRMED · IN_PROGRESS (or later) · MISSION_CANCELLED.
     *
     * @return array<string, mixed>
     */
    public static function operationalState(ReleasedOperatingRoomSlot $slot): array
    {
        $mission = $slot->getTakeoverMission();
        if ($slot->getStatus() === ReleasedRoomSlotStatus::AVAILABLE || $mission === null) {
            return ['state' => 'AVAILABLE', 'priority' => null, 'nextAutomaticAction' => null];
        }

        $priority = RoomTakeoverPriorityService::pendingPriority($mission);
        if ($priority !== null) {
            $target = $priority->getTargetInstrumentist();

            return [
                'state'    => 'PRIORITY_PENDING',
                'priority' => [
                    'publicationId'      => $priority->getId(),
                    'instrumentist'      => $target !== null ? ['id' => $target->getId(), 'name' => ActivityJournal::displayName($target)] : null,
                    'offeredAt'          => $priority->getPublishedAt()?->format(\DateTimeInterface::ATOM),
                    'expiresAt'          => $priority->getExpiresAt()?->format(\DateTimeInterface::ATOM),
                    'startsBeforeExpiry' => RoomTakeoverPriorityService::startsBeforeExpiry($mission, $priority),
                ],
                'nextAutomaticAction' => ['type' => 'PRIORITY_EXPIRY', 'at' => $priority->getExpiresAt()?->format(\DateTimeInterface::ATOM)],
            ];
        }

        $state = match (true) {
            $mission->getStatus() === MissionStatus::CANCELLED => 'MISSION_CANCELLED',
            $mission->getStatus() === MissionStatus::OPEN => 'POOL_OPEN',
            $mission->getStatus() === MissionStatus::DRAFT => 'DRAFT',
            $mission->getStatus() === MissionStatus::ASSIGNED => 'INSTRUMENTIST_CONFIRMED',
            default => 'IN_PROGRESS_OR_DONE',
        };

        return ['state' => $state, 'priority' => null, 'nextAutomaticAction' => null];
    }

    /**
     * @param array<string, mixed> $n
     * @return array<string, mixed>
     */
    private function notificationEvent(array $n): array
    {
        $isOffer = $n['notification_type'] === 'ROOM_TAKEOVER_MISSION_OFFER';

        return [
            // One row per offer recipient; one per pool solicitation minute (merged below).
            'key'       => $isOffer
                ? sprintf('notif-offer-%d-%d', $n['mission_id'], $n['recipient_user_id'])
                : sprintf('notif-pool-%d-%s', $n['mission_id'], substr((string) $n['created_at'], 0, 16)),
            'at'        => $this->atom((string) $n['created_at']),
            'type'      => $isOffer ? 'PRIORITY_OFFER_DELIVERY' : 'POOL_SOLICITED',
            'actor'     => null,
            'missionId' => (int) $n['mission_id'],
            'details'   => [
                'recipientId'   => (int) $n['recipient_user_id'],
                'recipientName' => trim(($n['firstname'] ?? '') . ' ' . ($n['lastname'] ?? '')),
                'channel'       => $n['channel'],
                'status'        => $n['status'],
                'skipReason'    => $n['skip_reason'],
            ],
        ];
    }

    /**
     * Delivery rows → one event per offer recipient (channels listed) and one per pool
     * solicitation (distinct recipients counted).
     *
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private function mergeNotificationEvents(array $events): array
    {
        $merged = [];
        foreach ($events as $event) {
            if (!in_array($event['type'], ['PRIORITY_OFFER_DELIVERY', 'POOL_SOLICITED'], true)) {
                $merged[$event['key']] = $event;
                continue;
            }
            $key = $event['key'];
            $d = $event['details'];
            if (!isset($merged[$key])) {
                $merged[$key] = $event;
                $merged[$key]['details'] = $event['type'] === 'PRIORITY_OFFER_DELIVERY'
                    ? ['recipientId' => $d['recipientId'], 'recipientName' => $d['recipientName'], 'channels' => []]
                    : ['recipients' => [], 'recipientCount' => 0];
            }
            if ($event['type'] === 'PRIORITY_OFFER_DELIVERY') {
                $merged[$key]['details']['channels'][] = ['channel' => $d['channel'], 'status' => $d['status'], 'skipReason' => $d['skipReason']];
            } else {
                $merged[$key]['details']['recipients'][$d['recipientId']] = $d['recipientName'];
                $merged[$key]['details']['recipientCount'] = count($merged[$key]['details']['recipients']);
            }
        }

        foreach ($merged as &$event) {
            if ($event['type'] === 'POOL_SOLICITED') {
                $event['details']['recipients'] = array_values($event['details']['recipients']);
            }
        }

        return array_values($merged);
    }

    /** @return array{id: int, name: string, system: bool}|null */
    private function actor(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id'     => (int) $user->getId(),
            'name'   => ActivityJournal::displayName($user),
            'system' => $user->getEmail() === ActivityJournal::SYSTEM_USER_EMAIL,
        ];
    }

    /** @return array{id: int, name: string, system: bool}|null */
    private function actorById(int $id): ?array
    {
        return $this->actor($this->em->find(User::class, $id));
    }

    /** DB datetimes are stored in the server's default timezone (as written by the app). */
    private function atom(string $dbDateTime): string
    {
        return (new \DateTimeImmutable($dbDateTime))->format(\DateTimeInterface::ATOM);
    }
}
