<?php

namespace App\Push;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * D-175 — accusés envoyés par le service worker, sans session utilisateur (le push arrive même
 * quand la page est fermée ou le JWT expiré).
 *
 * Authentification : la clé d'accusé est propre à UN envoi, aléatoire (128 bits) et n'existe que
 * dans le contenu chiffré de bout en bout du push — la connaître prouve avoir reçu cet envoi. Le
 * serveur n'en garde que l'empreinte. Une clé inconnue est ignorée sans le dire (réponse
 * identique) : aucun oracle. Idempotence : chaque état n'est écrit qu'une fois (premier arrivé).
 *
 * Un accusé n'est que de la télémétrie : il n'autorise aucune suppression ni aucun renouvellement.
 */
class PushReceiptService
{
    public const MAX_RECEIPTS_PER_REQUEST = 20;

    /** état accusé => colonne push_delivery ; tout accusé implique « reçu ». */
    private const EVENT_COLUMNS = [
        'RECEIVED'          => 'received_at',
        'DISPLAY_REQUESTED' => 'display_requested_at',
        'DISPLAY_FAILED'    => 'display_failed_at',
        'OPENED'            => 'opened_at',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PushDeliveryTracker $tracker,
        #[Autowire(service: 'monolog.logger.push')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<mixed> $receipts [{receipt: string, event: string, at?: int (ms epoch, accusé différé hors ligne)}]
     * @return int accusés appliqués (pour les tests ; jamais renvoyé au client)
     */
    public function record(array $receipts, ?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        $connection = $this->em->getConnection();
        $applied = 0;
        $touched = [];

        foreach (array_slice($receipts, 0, self::MAX_RECEIPTS_PER_REQUEST) as $receipt) {
            if (!is_array($receipt) || !PushEndpoint::isWellFormedToken($receipt['receipt'] ?? null)) {
                continue;
            }
            $column = self::EVENT_COLUMNS[$receipt['event'] ?? ''] ?? null;
            if ($column === null) {
                continue;
            }

            $delivery = $connection->fetchAssociative(
                'SELECT id, push_subscription_id, accepted_at FROM push_delivery WHERE receipt_key_hash = ?',
                [PushEndpoint::tokenHash($receipt['receipt'])],
            );
            if ($delivery === false) {
                continue;
            }

            // Heure déclarée par l'appareil (accusé mis en file hors ligne), bornée entre
            // l'acceptation par le fournisseur et maintenant : jamais dans le futur ni avant l'envoi.
            $at = $now;
            if (is_int($receipt['at'] ?? null)) {
                $declared = (new \DateTimeImmutable())->setTimestamp(intdiv($receipt['at'], 1000));
                $acceptedAt = new \DateTimeImmutable($delivery['accepted_at']);
                $at = max($acceptedAt, min($declared, $now));
            }
            $sqlAt = $at->format('Y-m-d H:i:s');

            $applied += $connection->executeStatement(
                sprintf('UPDATE push_delivery SET %1$s = ? WHERE id = ? AND %1$s IS NULL', $column),
                [$sqlAt, $delivery['id']],
            );
            if ($column !== 'received_at') {
                $connection->executeStatement('UPDATE push_delivery SET received_at = ? WHERE id = ? AND received_at IS NULL', [$sqlAt, $delivery['id']]);
            }
            if ($delivery['push_subscription_id'] !== null) {
                $touched[(int) $delivery['push_subscription_id']] = true;
            }
        }

        foreach (array_keys($touched) as $subscriptionId) {
            try {
                $this->tracker->refreshReliability($subscriptionId, $now);
            } catch (\Throwable $e) {
                $this->logger->error('push.delivery_tracking_failed', ['stage' => 'receipt', 'error' => $e::class]);
            }
        }

        return $applied;
    }
}
