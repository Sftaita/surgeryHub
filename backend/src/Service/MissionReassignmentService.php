<?php

namespace App\Service;

use App\Activity\CorrelationContext;
use App\Entity\Mission;
use App\Entity\NotificationDispatch;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\OutboundNotificationChannel;
use App\Exception\InstrumentistIneligibleException;
use App\Exception\MissionReassignmentException;
use App\Exception\PrestationConflictException;
use App\Message\MissionReassignmentNotificationMessage;
use App\MessageHandler\MissionReassignmentNotificationMessageHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-181 — one manager operation changing the instrumentist of one or several published
 * missions (« Changer d'instrumentiste », réaffectation multiple, alerte).
 *
 * Every mutation goes through MissionPostDeployService (reassign() / relay(), each under its
 * own mission lock and transaction, audited before any dispatch). Each mission is checked and
 * committed on its own: one refusal never undoes nor hides the others — the result lists, per
 * mission, what was done and what was refused (code, message). Missions are never left half
 * changed.
 *
 * Notifications: ONE MissionReassignmentNotificationMessage per operation, dispatched after
 * every commit, carrying only the real changes (nothing when nothing changed). Its handler
 * sends one recap per recipient (MISSION_REASSIGNMENT_NOTICE, BATCH). The operation id is the
 * request's correlationId, inherited by the message: the manager reads the real delivery
 * status of each recipient through notificationStatus().
 */
class MissionReassignmentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MissionPostDeployService $postDeploy,
        private readonly MessageBusInterface $bus,
        private readonly CorrelationContext $correlation,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $items {missionId, instrumentistId, mode?: DIRECT|RELAY,
     *        relayAt?: ISO 8601, expectedInstrumentistId?, confirmEncodingData?, encodingDataReason?}
     * @param callable(Mission): bool $canReassign the caller's voter decision (MissionVoter::REASSIGN)
     * @param bool $throwOnFailure single-mission callers (legacy /reassign, planning alert): the
     *        refusal is thrown as is (its own HTTP error) instead of being reported in the results
     * @return array{operationId: string, results: list<array<string,mixed>>, recipients: list<array<string,mixed>>}
     */
    public function reassign(User $actor, array $items, ?string $internalComment, callable $canReassign, bool $throwOnFailure = false): array
    {
        $results = [];
        $changes = [];

        foreach ($items as $item) {
            $missionId = isset($item['missionId']) && is_numeric($item['missionId']) ? (int) $item['missionId'] : null;
            try {
                [$result, $change] = $this->applyOne($actor, $item, $missionId, $internalComment, $canReassign);
                $results[] = $result;
                $changes[] = $change;
            } catch (HttpExceptionInterface $e) {
                $this->forget($missionId);
                if ($throwOnFailure) {
                    throw $e;
                }
                $results[] = ['missionId' => $missionId, 'status' => 'FAILED', 'error' => self::error($e)];
            }
        }

        $operationId = $this->correlation->correlationId();
        if ($changes !== []) {
            $this->bus->dispatch(new MissionReassignmentNotificationMessage($operationId, (int) $actor->getId(), $changes));
        }

        return ['operationId' => $operationId, 'results' => $results, 'recipients' => $this->recipients($changes)];
    }

    /**
     * @param array<string,mixed> $item
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
     */
    private function applyOne(User $actor, array $item, ?int $missionId, ?string $internalComment, callable $canReassign): array
    {
        $instrumentistId = isset($item['instrumentistId']) && is_numeric($item['instrumentistId']) ? (int) $item['instrumentistId'] : null;
        if ($missionId === null || $instrumentistId === null) {
            throw new UnprocessableEntityHttpException('missionId et instrumentistId sont requis.');
        }
        $mission = $this->em->find(Mission::class, $missionId);
        if ($mission === null) {
            throw new NotFoundHttpException('Mission introuvable.');
        }
        if (!$canReassign($mission)) {
            throw new AccessDeniedHttpException("Vous n'êtes pas autorisé à réaffecter cette mission.");
        }

        $mode     = ($item['mode'] ?? null) === MissionReassignmentPolicy::MODE_RELAY ? MissionReassignmentPolicy::MODE_RELAY : MissionReassignmentPolicy::MODE_DIRECT;
        $expected = isset($item['expectedInstrumentistId']) && is_numeric($item['expectedInstrumentistId']) ? (int) $item['expectedInstrumentistId'] : null;
        $from     = $mission->getInstrumentist();
        $surgeon  = $mission->getSurgeon();

        if ($mode === MissionReassignmentPolicy::MODE_RELAY) {
            if (!in_array(MissionReassignmentPolicy::MODE_RELAY, MissionReassignmentPolicy::modes($mission, Clock::get()->now()), true)) {
                $blocked = MissionReassignmentPolicy::blockedReason($mission);
                throw new MissionReassignmentException(
                    $blocked['code'] ?? MissionReassignmentPolicy::MODE_NOT_AVAILABLE,
                    $blocked['message'] ?? "Le relais n'est possible que pour une mission commencée ou qui porte déjà un encodage : réaffectez-la directement.",
                    ['missionId' => $missionId],
                );
            }
            $relayAt = self::parseInstant($item['relayAt'] ?? null);
            if ($relayAt === null) {
                throw new MissionReassignmentException(MissionReassignmentPolicy::RELAY_TIME_INVALID, "Indiquez l'heure à laquelle la remplaçante prend le relais.", ['missionId' => $missionId]);
            }
            $relay = $this->postDeploy->relay($mission, $actor, $instrumentistId, $relayAt, $expected, $internalComment);
            $relayMission = $relay['relayMission'];

            return [
                ['missionId' => $missionId, 'status' => 'DONE', 'mode' => $mode, 'relayMissionId' => $relayMission->getId()],
                self::change($mode, $relayMission, $mission, $from?->getId(), $instrumentistId, $surgeon?->getId()),
            ];
        }

        $this->postDeploy->reassign(
            $mission, $actor, $instrumentistId,
            notify: false,
            confirmEncodingData: (bool) ($item['confirmEncodingData'] ?? false),
            encodingDataReason: isset($item['encodingDataReason']) ? (string) $item['encodingDataReason'] : null,
            expectedInstrumentistId: $expected,
            internalComment: $internalComment,
        );

        return [
            ['missionId' => $missionId, 'status' => 'DONE', 'mode' => $mode, 'relayMissionId' => null],
            self::change($mode, $mission, $mission, $from?->getId(), $instrumentistId, $surgeon?->getId()),
        ];
    }

    /** @return array<string,mixed> what the notification handler needs (ids and the changed window only) */
    private static function change(string $mode, Mission $heldByNew, Mission $original, ?int $fromId, int $toId, ?int $surgeonId): array
    {
        return [
            'mode'                => $mode,
            'missionId'           => $heldByNew->getId(),
            'originalMissionId'   => $original->getId(),
            'fromInstrumentistId' => $fromId,
            'toInstrumentistId'   => $toId,
            'surgeonId'           => $surgeonId,
            // The part that changed hands: the whole mission (DIRECT) or the relay window.
            'startAt'             => $heldByNew->getStartAt()?->format(\DateTimeInterface::ATOM),
            'endAt'               => $heldByNew->getEndAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Who will be informed (deduplicated per person, with every role she has in the operation).
     * Same roles exactly as MissionReassignmentNotificationMessageHandler::roles().
     *
     * @param list<array<string,mixed>> $changes
     * @return list<array{userId: int, name: string, roles: list<string>}>
     */
    public function recipients(array $changes): array
    {
        $byUser = [];
        foreach ($changes as $c) {
            foreach (MissionReassignmentNotificationMessageHandler::roles($c) as $role => $id) {
                $byUser[(int) $id]['roles'][$role] = $role;
            }
        }

        $out = [];
        foreach ($byUser as $id => $entry) {
            $user = $this->em->find(User::class, $id);
            $out[] = [
                'userId' => $id,
                'name'   => $user !== null ? (trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? '')) ?: (string) $user->getEmail()) : '#' . $id,
                'roles'  => array_values($entry['roles']),
            ];
        }

        return $out;
    }

    /**
     * Real delivery status of an operation's notifications, per recipient (email + in-app),
     * read from the dispatcher's own records. A recipient whose message is not processed yet
     * simply does not appear (the client shows it as pending).
     *
     * @return list<array<string,mixed>>
     */
    public function notificationStatus(string $operationId): array
    {
        /** @var list<NotificationDispatch> $dispatches */
        $dispatches = $this->em->createQuery(
            'SELECT d, r FROM App\Entity\NotificationDispatch d LEFT JOIN d.recipient r
             WHERE d.episodeRef LIKE :episode AND d.notificationType = :type ORDER BY d.id ASC'
        )->setParameter('episode', 'reassignment:' . addcslashes($operationId, '%_') . ':%')
         ->setParameter('type', NotificationType::MISSION_REASSIGNMENT_NOTICE->value)
         ->getResult();

        $byUser = [];
        foreach ($dispatches as $d) {
            $uid = (int) $d->getRecipient()?->getId();
            $byUser[$uid] ??= [
                'userId'     => $uid,
                'name'       => $d->getRecipientNameSnapshot(),
                'missionIds' => [],
                'email'      => null,
                'inApp'      => null,
            ];
            $byUser[$uid]['missionIds'][] = $d->getMission()?->getId();
            foreach ($d->getDeliveries() as $delivery) {
                $key = match ($delivery->getChannel()) {
                    OutboundNotificationChannel::EMAIL  => 'email',
                    OutboundNotificationChannel::IN_APP => 'inApp',
                    default                             => null,
                };
                if ($key === null) {
                    continue;
                }
                $byUser[$uid][$key] = [
                    'status'     => $delivery->getStatus()->value,
                    'skipReason' => $delivery->getSkipReason()?->value,
                    'at'         => ($delivery->getSentAt() ?? $delivery->getFailedAt() ?? $delivery->getQueuedAt() ?? $delivery->getCreatedAt())?->format(\DateTimeInterface::ATOM),
                ];
            }
        }

        return array_values(array_map(static function (array $row): array {
            $row['missionIds'] = array_values(array_unique(array_filter($row['missionIds'])));

            return $row;
        }, $byUser));
    }

    /** @return list<int> D-181 — the relay missions continuing $mission (MissionMapper). */
    public function relayMissionIdsOf(Mission $mission): array
    {
        if ($mission->getId() === null) {
            return [];
        }

        return array_map('intval', $this->em->getConnection()->fetchFirstColumn(
            'SELECT id FROM mission WHERE relay_of_mission_id = ? ORDER BY start_at, id',
            [$mission->getId()],
        ));
    }

    /** @return list<int> D-182 — the cancelled duplicates $mission was kept in place of (MissionMapper). */
    public function replacedMissionIdsOf(Mission $mission): array
    {
        if ($mission->getId() === null) {
            return [];
        }

        return array_map('intval', $this->em->getConnection()->fetchFirstColumn(
            'SELECT id FROM mission WHERE replaced_by_mission_id = ? ORDER BY id',
            [$mission->getId()],
        ));
    }

    /** @return array<string,mixed> */
    private static function error(HttpExceptionInterface $e): array
    {
        $error = ['status' => $e->getStatusCode(), 'code' => 'CONFLICT', 'message' => $e->getMessage()];
        if ($e instanceof MissionReassignmentException || $e instanceof PrestationConflictException) {
            $error['code'] = $e->getErrorCode();
            $error += $e->getExtra() !== [] ? ['context' => $e->getExtra()] : [];
        } elseif ($e instanceof InstrumentistIneligibleException) {
            $error['code'] = 'INSTRUMENTIST_INCOMPATIBLE';
            $error['reasons'] = array_map(static fn ($r) => $r->value, $e->getReasons());
        } elseif ($e->getStatusCode() === 403) {
            $error['code'] = 'FORBIDDEN';
        } elseif ($e->getStatusCode() === 404) {
            $error['code'] = 'NOT_FOUND';
        } elseif ($e->getStatusCode() === 422) {
            $error['code'] = 'VALIDATION_FAILED';
        }

        return $error;
    }

    /** A refused item never leaves in-memory changes behind for the next item's flush. */
    private function forget(?int $missionId): void
    {
        if ($missionId === null) {
            return;
        }
        $mission = $this->em->getUnitOfWork()->tryGetById($missionId, Mission::class);
        if ($mission instanceof Mission && $this->em->contains($mission)) {
            $this->em->refresh($mission);
        }
    }

    private static function parseInstant(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('Europe/Brussels'));
        } catch (\Exception) {
            return null;
        }
    }
}
