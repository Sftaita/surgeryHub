<?php

namespace App\Push;

use App\Entity\PushEndpointInvalidation;
use App\Entity\PushSubscription;
use App\Service\WebPushService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * D-175 — remplacement d'abonnement par le navigateur (`pushsubscriptionchange`), reçu par le
 * service worker alors qu'aucune page — donc aucun JWT — n'est disponible.
 *
 * Authentification dédiée : le jeton d'appareil (256 bits aléatoires) remis par
 * POST /api/push/sync à la page authentifiée, stocké par l'origine pour son service worker, et
 * dont le serveur ne garde que l'empreinte. Les secrets Web Push (`auth`, `p256dh`) ne sont PAS
 * réutilisés : ce sont des clés de chiffrement du contenu, connues aussi du fournisseur de clés
 * côté navigateur, et non une preuve d'identité.
 *
 * Portée minimale du jeton : remplacer l'endpoint de CET appareil, rien d'autre — ni changer de
 * compte, ni lire une donnée. Il tourne à chaque remplacement. Un endpoint déclaré mort n'est
 * jamais accepté comme remplaçant.
 */
class PushDeviceRenewalService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PushDeliveryTracker $tracker,
        #[Autowire(service: 'monolog.logger.push')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $body {deviceToken, subscription: {endpoint, keys: {p256dh, auth}}, swVersion?}
     * @return array{status: int, body: array<string, mixed>}
     */
    public function renew(array $body): array
    {
        $token = $body['deviceToken'] ?? null;
        if (!PushEndpoint::isWellFormedToken($token, 43, 43)) {
            return ['status' => 401, 'body' => ['code' => 'PUSH_DEVICE_UNKNOWN']];
        }
        $sub = is_array($body['subscription'] ?? null) ? $body['subscription'] : [];
        $endpoint = $sub['endpoint'] ?? null;
        $keys = is_array($sub['keys'] ?? null) ? $sub['keys'] : [];
        $error = PushSubscriptionSyncService::validateSubscription($endpoint, $keys['p256dh'] ?? null, $keys['auth'] ?? null);
        if ($error !== null) {
            return ['status' => 400, 'body' => ['message' => $error]];
        }
        if ($this->tracker->findInvalidation($endpoint) !== null) {
            return ['status' => 409, 'body' => ['code' => 'PUSH_SUBSCRIPTION_INVALIDATED']];
        }

        // Ligne verrouillée puis jeton revérifié : deux `pushsubscriptionchange` simultanés (ou un
        // rejeu) avec le même jeton ne peuvent pas tous deux remplacer l'endpoint — le second voit le
        // jeton déjà tourné et reçoit 401, au lieu de laisser au service worker un jeton périmé.
        return $this->em->wrapInTransaction(fn () => $this->renewLocked($token, $endpoint, $keys, $body));
    }

    /**
     * @param array<string, mixed> $keys
     * @param array<string, mixed> $body
     * @return array{status: int, body: array<string, mixed>}
     */
    private function renewLocked(string $token, string $endpoint, array $keys, array $body): array
    {
        $hash = PushEndpoint::tokenHash($token);
        $row = $this->em->getRepository(PushSubscription::class)->findOneBy(['deviceTokenHash' => $hash]);
        if ($row !== null) {
            $this->em->refresh($row, LockMode::PESSIMISTIC_WRITE);
        }
        if ($row === null || $row->getDeviceTokenHash() !== $hash) {
            return ['status' => 401, 'body' => ['code' => 'PUSH_DEVICE_UNKNOWN']];
        }

        $swVersion = PushSubscriptionSyncService::normalizeSwVersion($body['swVersion'] ?? null);
        if ($endpoint === $row->getEndpoint()) {
            $row->setPublicKey($keys['p256dh'])->setAuthToken($keys['auth'])->setSwVersion($swVersion ?? $row->getSwVersion());
            $this->em->flush();

            return ['status' => 200, 'body' => ['state' => 'OK', 'deviceToken' => null]];
        }

        $other = $this->em->getRepository(PushSubscription::class)->findOneBy(['endpoint' => $endpoint]);
        if ($other !== null) {
            // Le nouvel endpoint est déjà rattaché ailleurs : la page tranchera à la prochaine synchronisation.
            return ['status' => 409, 'body' => ['code' => 'PUSH_SUBSCRIPTION_TAKEN']];
        }

        $previousEndpoint = (string) $row->getEndpoint();
        $this->tracker->recordInvalidated($previousEndpoint, PushEndpointInvalidation::REASON_REPLACED, $row->getUser()?->getId(), $row->getId(), WebPushService::providerForEndpoint($previousEndpoint), null);

        $newToken = PushEndpoint::randomToken(32);
        $row->setEndpoint($endpoint)->setPublicKey($keys['p256dh'])->setAuthToken($keys['auth'])
            ->resetReliability()->setReceiptsCapableAt(null)->setSwVersion($swVersion ?? $row->getSwVersion())
            ->setDeviceTokenHash(PushEndpoint::tokenHash($newToken))->setRenewalReason(PushEndpointInvalidation::REASON_REPLACED);
        $this->em->flush();
        $this->logger->info('push.subscription_replaced', ['user_id' => $row->getUser()?->getId(), 'subscription_id' => $row->getId()]);

        return ['status' => 200, 'body' => ['state' => 'RENEWED', 'deviceToken' => $newToken]];
    }
}
