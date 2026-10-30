<?php

namespace App\Push;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Entity\PushEndpointInvalidation;
use App\Entity\PushSubscription;
use App\Enum\ActivityActorType;
use App\Enum\ActivityEventType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * D-175 — trace technique de chaque envoi push vers un appareil et état de fiabilité de
 * l'appareil. Écrit en DBAL (jamais via l'UnitOfWork de l'appelant : WebPushService est appelé
 * au milieu de flux métier qui gèrent leurs propres flush) et ne lève jamais : la télémétrie ne
 * doit jamais faire échouer un envoi.
 *
 * Suspicion d'échec silencieux : seuls comptent les envois acceptés APRÈS que l'appareil a
 * déclaré un service worker capable d'accuser réception (receipts_expected), sinon un appareil
 * resté sur l'ancien service worker serait déclaré suspect à tort. La détection est journalisée
 * (PUSH_SUBSCRIPTION_SUSPECTED) ; elle ne supprime jamais rien et ne renouvelle rien d'elle-même.
 */
class PushDeliveryTracker
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PushReliabilityPolicy $policy,
        #[Autowire(service: 'monolog.logger.push')]
        private readonly LoggerInterface $logger,
        private readonly ?ActivityJournal $journal = null,
    ) {
    }

    /** @return array{key: string, hash: string} clé d'accusé (dans le contenu chiffré) et son empreinte (en base) */
    public function newReceiptKey(): array
    {
        $key = PushEndpoint::randomToken(16);

        return ['key' => $key, 'hash' => PushEndpoint::tokenHash($key)];
    }

    /** @return int|null id push_delivery */
    public function recordAccepted(PushSubscription $subscription, string $receiptHash, string $provider, ?int $statusCode, ?string $notificationType): ?int
    {
        $subscriptionId = $subscription->getId();
        if ($subscriptionId === null) {
            return null;
        }

        try {
            $connection = $this->connection();
            $now = new \DateTimeImmutable();
            $capableAt = $connection->fetchOne('SELECT receipts_capable_at FROM push_subscription WHERE id = ?', [$subscriptionId]);
            $expected = $capableAt !== false && $capableAt !== null;

            $connection->insert('push_delivery', [
                'push_subscription_id' => $subscriptionId,
                'user_id'              => $subscription->getUser()?->getId(),
                'provider'             => $provider,
                'receipt_key_hash'     => $receiptHash,
                'notification_type'    => $notificationType !== null ? substr($notificationType, 0, 64) : null,
                'status_code'          => $statusCode,
                'receipts_expected'    => $expected ? 1 : 0,
                'accepted_at'          => self::sql($now),
            ]);
            $deliveryId = (int) $connection->lastInsertId();
            $connection->executeStatement('UPDATE push_subscription SET last_accepted_at = ? WHERE id = ?', [self::sql($now), $subscriptionId]);

            if ($expected) {
                $this->refreshReliability($subscriptionId, $now);
            }

            return $deliveryId;
        } catch (\Throwable $e) {
            $this->logger->error('push.delivery_tracking_failed', ['stage' => 'accepted', 'error' => $e::class]);

            return null;
        }
    }

    /** @param list<int> $deliveryIds */
    public function linkToNotification(array $deliveryIds, int $outboundNotificationId): void
    {
        $deliveryIds = array_values(array_filter($deliveryIds, 'is_int'));
        if ($deliveryIds === []) {
            return;
        }

        try {
            $this->connection()->executeStatement(
                'UPDATE push_delivery SET outbound_notification_id = ? WHERE id IN (?)',
                [$outboundNotificationId, $deliveryIds],
                [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::INTEGER],
            );
        } catch (\Throwable $e) {
            $this->logger->error('push.delivery_tracking_failed', ['stage' => 'link', 'error' => $e::class]);
        }
    }

    /**
     * Mémorise un endpoint définitivement mort (404/410, ou remplacé par un renouvellement).
     * Idempotent : une seule ligne par empreinte, la plus récente raison l'emporte.
     */
    public function recordInvalidated(string $endpoint, string $reason, ?int $userId, ?int $subscriptionId, ?string $provider, ?int $statusCode, ?Connection $connection = null): void
    {
        $connection ??= $this->connection();
        $connection->executeStatement(
            'INSERT INTO push_endpoint_invalidation (endpoint_hash, user_id, push_subscription_id, reason, status_code, provider, invalidated_at, renewal_request_count)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), push_subscription_id = VALUES(push_subscription_id),
                 reason = VALUES(reason), status_code = VALUES(status_code), provider = VALUES(provider), invalidated_at = VALUES(invalidated_at)',
            [PushEndpoint::fingerprint($endpoint), $userId, $subscriptionId, $reason, $statusCode, $provider, self::sql(new \DateTimeImmutable())],
        );
    }

    public function findInvalidation(string $endpoint): ?PushEndpointInvalidation
    {
        return $this->em->getRepository(PushEndpointInvalidation::class)->findOneBy(['endpointHash' => PushEndpoint::fingerprint($endpoint)]);
    }

    /**
     * Recalcule l'état de fiabilité d'un appareil à partir de ses envois : nombre d'envois
     * attendant un accusé depuis le plus récent envoi accusé, et suspicion. Un accusé tardif
     * d'un ancien envoi ne masque donc pas des pertes plus récentes.
     */
    public function refreshReliability(int $subscriptionId, \DateTimeImmutable $now): void
    {
        $connection = $this->connection();
        $row = $connection->fetchAssociative(
            'SELECT s.user_id, s.suspected_at, s.endpoint,
                    (SELECT MAX(d.accepted_at) FROM push_delivery d WHERE d.push_subscription_id = s.id AND d.received_at IS NOT NULL) AS last_acked_sent,
                    (SELECT MAX(d.received_at) FROM push_delivery d WHERE d.push_subscription_id = s.id) AS last_receipt
             FROM push_subscription s WHERE s.id = ?',
            [$subscriptionId],
        );
        if ($row === false) {
            return;
        }

        $pending = $connection->fetchAssociative(
            'SELECT COUNT(*) AS n, MIN(d.accepted_at) AS first_at FROM push_delivery d
             WHERE d.push_subscription_id = ? AND d.receipts_expected = 1 AND d.received_at IS NULL'
            . ($row['last_acked_sent'] !== null ? ' AND d.accepted_at > ?' : ''),
            $row['last_acked_sent'] !== null ? [$subscriptionId, $row['last_acked_sent']] : [$subscriptionId],
        );
        $count = (int) ($pending['n'] ?? 0);
        $firstAt = ($pending['first_at'] ?? null) !== null ? new \DateTimeImmutable($pending['first_at']) : null;
        $suspect = $this->policy->isSuspect($count, $firstAt, $now);
        $wasSuspect = $row['suspected_at'] !== null;

        $connection->transactional(function (Connection $c) use ($subscriptionId, $row, $count, $firstAt, $suspect, $wasSuspect, $now): void {
            $c->executeStatement(
                'UPDATE push_subscription SET accepted_since_receipt = ?, first_unacknowledged_at = ?, last_receipt_at = ?, suspected_at = ? WHERE id = ?',
                [
                    $count,
                    $firstAt !== null ? self::sql($firstAt) : null,
                    $row['last_receipt'],
                    $suspect ? ($wasSuspect ? $row['suspected_at'] : self::sql($now)) : null,
                    $subscriptionId,
                ],
            );

            if ($suspect && !$wasSuspect) {
                $this->logger->warning('push.subscription_suspected', ['subscription_id' => $subscriptionId, 'unacknowledged' => $count]);
                $this->journalSuspected($c, $subscriptionId, $row['user_id'] !== null ? (int) $row['user_id'] : null, $count, $row['endpoint']);
            } elseif (!$suspect && $wasSuspect) {
                $this->logger->info('push.subscription_recovered', ['subscription_id' => $subscriptionId]);
            }
        });
    }

    private function journalSuspected(Connection $connection, int $subscriptionId, ?int $userId, int $count, ?string $endpoint): void
    {
        if ($this->journal === null || !$this->journal->isEnabled()) {
            return;
        }
        $owner = $userId !== null ? $this->em->find(\App\Entity\User::class, $userId) : null;
        $name = ActivityJournal::displayName($owner) ?? '—';
        $provider = \App\Service\WebPushService::providerForEndpoint($endpoint);

        $this->journal->record(new ActivityRecord(
            eventType: ActivityEventType::PUSH_SUBSCRIPTION_SUSPECTED,
            subjectType: 'User',
            subjectId: $subscriptionId,
            subjectLabel: $name,
            summary: sprintf('Appareil push #%d de %s : %d envois acceptés sans accusé de réception (%s)', $subscriptionId, $name, $count, $provider),
            payload: [
                'entityId' => $subscriptionId, 'userId' => $userId, 'userName' => $name, 'provider' => $provider,
                'reason' => 'NO_RECEIPT', 'deliveryCount' => $count,
            ],
            actorType: ActivityActorType::SYSTEM,
            userId: $userId,
        ), $connection);
    }

    private function connection(): Connection
    {
        return $this->em->getConnection();
    }

    private static function sql(\DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s');
    }
}
