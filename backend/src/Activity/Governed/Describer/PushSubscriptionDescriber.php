<?php

namespace App\Activity\Governed\Describer;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Activity\Governed\AbstractGovernedDescriber;
use App\Activity\Governed\GovernedChange;
use App\Entity\PushSubscription;
use App\Enum\ActivityEventType;
use App\Service\WebPushService;

/**
 * H5b (D-132) — a push device registered / removed. NEVER the endpoint, the p256dh key or the
 * auth secret: the subscription id, its owner and the push provider (FCM / APPLE / MOZILLA…)
 * only. A key refresh of the same device is technical: no event. The expiry cleanup by the
 * provider (404/410) is a bulk DELETE, journaled by WebPushService with reason PROVIDER_EXPIRED.
 */
final class PushSubscriptionDescriber extends AbstractGovernedDescriber
{
    public function entityClass(): string { return PushSubscription::class; }

    public function trackedFields(): array
    {
        return ['user' => 'Utilisateur', 'endpoint' => 'Abonnement'];
    }

    /** D-175 — l'endpoint n'est suivi que pour détecter un renouvellement : jamais sa valeur. */
    public function redactedFields(): array
    {
        return ['endpoint'];
    }

    public function describe(GovernedChange $c): ?ActivityRecord
    {
        /** @var PushSubscription $s */
        $s        = $c->entity;
        $person   = $s->getUser();
        $provider = WebPushService::providerForEndpoint($s->getEndpoint());
        $name     = ActivityJournal::displayName($person) ?? '—';

        // D-175 — même appareil, nouvel endpoint (renouvellement automatique, pushsubscriptionchange
        // ou réparation demandée) : l'ancien endpoint est mémorisé comme invalide, l'appareil reste.
        if ($c->action === GovernedChange::UPDATED && $c->changed('endpoint') && !$c->changed('user')) {
            return $this->record(
                ActivityEventType::PUSH_SUBSCRIPTION_RENEWED,
                new GovernedChange($c->action, $c->entity, $c->entityId, [], [], $c->actor, $c->occurredAt),
                'User', $name,
                sprintf('Appareil push #%d de %s renouvelé (%s)', $c->entityId, $name, $provider),
                ['userId' => $person?->getId(), 'userName' => $name, 'provider' => $provider, 'reason' => $s->getRenewalReason()],
                userId: $person?->getId(),
            );
        }
        if ($c->action === GovernedChange::UPDATED && !$c->changed('user')) {
            return null;
        }
        $reassignedFrom = $c->action === GovernedChange::UPDATED ? $c->change('user')['fromId'] : null;

        return $this->record(
            $c->action === GovernedChange::DELETED ? ActivityEventType::PUSH_SUBSCRIPTION_REMOVED : ActivityEventType::PUSH_SUBSCRIPTION_CREATED,
            new GovernedChange($c->action, $c->entity, $c->entityId, [], [], $c->actor, $c->occurredAt),
            'User', $name,
            $c->action === GovernedChange::DELETED
                ? sprintf('Appareil push #%d de %s désactivé (%s)', $c->entityId, $name, $provider)
                : sprintf('Appareil push #%d enregistré pour %s (%s)', $c->entityId, $name, $provider),
            [
                'userId' => $person?->getId(), 'userName' => $name, 'provider' => $provider,
                'reason' => $c->action === GovernedChange::DELETED ? 'USER_UNSUBSCRIBED' : null,
                'reassignedFromUserId' => $reassignedFrom,
            ],
            userId: $person?->getId(),
        );
    }
}
