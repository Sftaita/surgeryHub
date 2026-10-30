<?php

namespace App\Push;

use App\Entity\PushEndpointInvalidation;
use App\Entity\PushSubscription;
use App\Entity\User;
use App\Service\WebPushService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * D-175 — réconciliation navigateur ↔ serveur, appelée par l'application (session JWT) à
 * l'ouverture, au changement de compte, au retour au premier plan et à l'activation.
 *
 * Le navigateur décrit l'abonnement qu'il détient ; le serveur répond par un état :
 *  - OK                l'appareil est connu et rattaché à ce compte (clés rafraîchies) ;
 *  - REGISTERED        appareil inconnu : enregistré ;
 *  - FOREIGN (409)     l'endpoint appartient encore à un AUTRE compte : jamais transféré (D-081
 *                      révisé, D-175). Rien n'est modifié chez l'autre compte, son identité n'est
 *                      pas révélée ; le navigateur légitime se réabonne (nouvel endpoint), ce qui
 *                      est la seule preuve de possession retenue ;
 *  - RENEWED           le navigateur vient de remplacer son abonnement (`replacesEndpoint`) :
 *                      même appareil, nouvel endpoint, l'ancien est mémorisé comme invalide ;
 *  - RENEW_REQUIRED    l'abonnement détenu ne peut pas servir : endpoint déclaré mort par le
 *                      fournisseur (reason INVALIDATED — jamais réenregistré), ou appareil
 *                      suspect si PUSH_AUTO_RENEW_SUSPECTED (reason SUSPECTED). `autoRenew`
 *                      dit si le navigateur doit se réabonner seul ; sinon la réparation reste
 *                      proposée dans Mon profil.
 *
 * Aucune suppression d'un appareil valide ; un renouvellement n'est jamais demandé pour la seule
 * ancienneté d'un abonnement ; au plus une demande par endpoint / appareil par fenêtre
 * anti-boucle (PushReliabilityPolicy).
 */
class PushSubscriptionSyncService
{
    // Longueurs des colonnes PushSubscription (validées ici : 400 clair plutôt qu'une erreur SQL).
    public const MAX_ENDPOINT_LENGTH = 500;
    public const MAX_KEY_LENGTH = 255;
    private const MAX_SW_VERSION_LENGTH = 32;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PushDeliveryTracker $tracker,
        private readonly PushReliabilityPolicy $policy,
        #[Autowire(service: 'monolog.logger.push')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function validateSubscription(mixed $endpoint, mixed $p256dh, mixed $auth): ?string
    {
        if (!is_string($endpoint) || $endpoint === '') {
            return 'Invalid subscription data: endpoint is required';
        }
        if (strlen($endpoint) > self::MAX_ENDPOINT_LENGTH) {
            return 'Invalid subscription data: endpoint is too long';
        }
        if (!is_string($p256dh) || $p256dh === '' || !is_string($auth) || $auth === '') {
            return 'Invalid subscription data: keys.p256dh and keys.auth are required';
        }
        if (strlen($p256dh) > self::MAX_KEY_LENGTH || strlen($auth) > self::MAX_KEY_LENGTH) {
            return 'Invalid subscription data: keys are too long';
        }

        return null;
    }

    public static function normalizeSwVersion(mixed $version): ?string
    {
        return is_string($version) && preg_match('/^[A-Za-z0-9._-]{1,' . self::MAX_SW_VERSION_LENGTH . '}$/', $version) === 1 ? $version : null;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function sync(User $user, array $body): array
    {
        $now = new \DateTimeImmutable();
        $endpoint = $body['endpoint'];
        $p256dh = $body['keys']['p256dh'];
        $auth = $body['keys']['auth'];
        $swVersion = self::normalizeSwVersion($body['swVersion'] ?? null);
        $receipts = ($body['receipts'] ?? false) === true && $swVersion !== null;
        $replaces = is_string($body['replacesEndpoint'] ?? null) && $body['replacesEndpoint'] !== $endpoint ? $body['replacesEndpoint'] : null;

        $invalidation = $this->tracker->findInvalidation($endpoint);
        if ($invalidation !== null) {
            return $this->renewInvalidated($user, $invalidation, $now);
        }

        $repo = $this->em->getRepository(PushSubscription::class);
        $row = $repo->findOneBy(['endpoint' => $endpoint]);
        $state = null;

        // D-081 révisé : connaître un endpoint (et même ses clés p256dh/auth, que la base connaît
        // aussi) ne prouve pas qu'on détient le navigateur. Un endpoint d'un autre compte n'est
        // jamais transféré : vérifié AVANT toute écriture, aucune ligne de l'autre compte n'est
        // modifiée. Seul un abonnement neuf, créé par le navigateur lui-même, est accepté.
        if ($row !== null && $row->getUser()?->getId() !== $user->getId()) {
            $this->logger->warning('push.subscription_transfer_refused', ['user_id' => $user->getId(), 'subscription_id' => $row->getId(), 'via' => 'sync']);

            return self::foreign();
        }

        if ($replaces !== null) {
            $previous = $repo->findOneBy(['endpoint' => $replaces, 'user' => $user]);
            // Seul un appareil de CE compte peut être déclaré remplacé : un endpoint inconnu ou
            // appartenant à un autre compte n'est jamais invalidé sur la parole du client.
            if ($previous !== null && $previous !== $row) {
                $this->tracker->recordInvalidated($replaces, PushEndpointInvalidation::REASON_RENEWED, $user->getId(), $previous->getId(), WebPushService::providerForEndpoint($replaces), null);
                if ($row === null) {
                    $previous->setEndpoint($endpoint)->resetReliability()->setRenewalReason(PushEndpointInvalidation::REASON_RENEWED);
                    $previous->setReceiptsCapableAt(null);
                    $row = $previous;
                    $state = 'RENEWED';
                } else {
                    $this->em->remove($previous);
                }
                $this->logger->info('push.subscription_renewed', ['user_id' => $user->getId(), 'subscription_id' => $previous->getId()]);
            }
        }

        $issueToken = ($body['hasDeviceToken'] ?? false) !== true;
        if ($row === null) {
            $row = (new PushSubscription())->setUser($user)->setEndpoint($endpoint)->setContentEncoding('aes128gcm');
            $this->em->persist($row);
            $state = 'REGISTERED';
            $issueToken = true;
            $this->logger->info('push.subscription_created', ['user_id' => $user->getId(), 'via' => 'sync']);
        }
        $state ??= 'OK';

        $row->setPublicKey($p256dh)->setAuthToken($auth)->setSwVersion($swVersion)->setLastSyncedAt($now);
        if ($receipts && $row->getReceiptsCapableAt() === null) {
            $row->setReceiptsCapableAt($now);
        }
        if (!$receipts) {
            // Ancien service worker (ou version inconnue) : ses envois n'attendent aucun accusé.
            $row->setReceiptsCapableAt(null);
        }

        $reason = null;
        $autoRenew = false;
        if ($state === 'OK' && $row->getSuspectedAt() !== null && $this->policy->autoRenewSuspected()
            && $this->policy->renewalAllowedSince($row->getRenewalRequestedAt(), $now)) {
            $row->setRenewalRequestedAt($now);
            [$state, $reason, $autoRenew] = ['RENEW_REQUIRED', 'SUSPECTED', true];
            $this->logger->info('push.renewal_requested', ['user_id' => $user->getId(), 'subscription_id' => $row->getId(), 'reason' => 'SUSPECTED']);
        }

        $deviceToken = null;
        if ($issueToken || $row->getDeviceTokenHash() === null) {
            $deviceToken = PushEndpoint::randomToken(32);
            $row->setDeviceTokenHash(PushEndpoint::tokenHash($deviceToken));
        }

        $this->em->flush();

        return [
            'state' => $state,
            'reason' => $reason,
            'autoRenew' => $autoRenew,
            'deviceToken' => $deviceToken,
            'device' => $this->describe($row, $user),
        ];
    }

    /**
     * Réponse 409 (PushSubscriptionController) : le navigateur doit se réabonner. `autoRenew` toujours
     * vrai : c'est le remplacement du rattachement automatique d'avant (changement de compte sur un
     * même appareil), pas une réparation soumise aux drapeaux D-175. Aucune donnée sur le propriétaire.
     *
     * @return array<string, mixed>
     */
    public static function foreign(): array
    {
        return [
            'code' => 'PUSH_SUBSCRIPTION_FOREIGN',
            'message' => 'This push subscription cannot be attached to this account: renew it',
            'state' => 'RENEW_REQUIRED',
            'reason' => 'FOREIGN',
            'autoRenew' => true,
            'deviceToken' => null,
            'device' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function renewInvalidated(User $user, PushEndpointInvalidation $invalidation, \DateTimeImmutable $now): array
    {
        $autoRenew = $this->policy->autoRenewInvalidated() && $this->policy->renewalAllowedSince($invalidation->getRenewalRequestedAt(), $now);
        if ($autoRenew) {
            $invalidation->markRenewalRequested($now);
            $this->em->flush();
        }
        $this->logger->info('push.sync_invalidated_endpoint', [
            'user_id' => $user->getId(), 'reason' => $invalidation->getReason(), 'auto_renew' => $autoRenew,
            'renewal_requests' => $invalidation->getRenewalRequestCount(),
        ]);

        return [
            'state' => 'RENEW_REQUIRED',
            'reason' => 'INVALIDATED',
            'autoRenew' => $autoRenew,
            'deviceToken' => null,
            'device' => null,
            'invalidatedAt' => $invalidation->getInvalidatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> état lisible de l'appareil courant (Mon profil) — jamais l'endpoint ni les clés */
    private function describe(PushSubscription $row, User $user): array
    {
        $fmt = static fn (?\DateTimeImmutable $d) => $d?->format(\DateTimeInterface::ATOM);
        $others = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM push_subscription WHERE user_id = ? AND id <> ?',
            [$user->getId(), $row->getId() ?? 0],
        );

        return [
            'id' => $row->getId(),
            'provider' => WebPushService::providerForEndpoint($row->getEndpoint()),
            'registeredAt' => $fmt($row->getCreatedAt()),
            'lastAcceptedAt' => $fmt($row->getLastAcceptedAt()),
            'lastReceiptAt' => $fmt($row->getLastReceiptAt()),
            'receiptsCapable' => $row->getReceiptsCapableAt() !== null,
            'unacknowledged' => $row->getAcceptedSinceReceipt(),
            'suspected' => $row->getSuspectedAt() !== null,
            'otherDevices' => $others,
        ];
    }
}
