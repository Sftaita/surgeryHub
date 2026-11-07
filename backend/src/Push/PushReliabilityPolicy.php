<?php

namespace App\Push;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * D-175 — réglages de la fiabilisation Web Push, tous pilotés par l'environnement pour un
 * déploiement progressif (observation d'abord, réparation ensuite) :
 *
 *  - PUSH_AUTO_RENEW_INVALIDATED (défaut 0) : un navigateur qui revient avec un endpoint que le
 *    fournisseur a déclaré mort (404/410) reçoit l'ordre de se réabonner automatiquement. À 0, le
 *    serveur ne le réenregistre pas non plus, mais laisse l'utilisateur déclencher la réparation
 *    (Mon profil) — phase d'observation.
 *  - PUSH_AUTO_RENEW_SUSPECTED (défaut 0, décision utilisateur du 29/10) : renouvellement d'un
 *    appareil seulement SUSPECT (envois acceptés sans aucun accusé). La détection et sa
 *    journalisation sont toujours actives ; le renouvellement reste coupé tant que les seuils ne
 *    sont pas validés sur mesures réelles.
 *  - PUSH_SUSPECT_MIN_UNACKNOWLEDGED / PUSH_SUSPECT_MIN_HOURS : seuils de suspicion.
 *  - PUSH_RENEWAL_COOLDOWN_HOURS : au plus une demande de renouvellement par endpoint (ou appareil)
 *    sur cette durée — protection contre les boucles.
 */
final class PushReliabilityPolicy
{
    public function __construct(
        #[Autowire('%env(bool:PUSH_AUTO_RENEW_INVALIDATED)%')]
        private readonly bool $autoRenewInvalidated,
        #[Autowire('%env(bool:PUSH_AUTO_RENEW_SUSPECTED)%')]
        private readonly bool $autoRenewSuspected,
        #[Autowire('%env(int:PUSH_SUSPECT_MIN_UNACKNOWLEDGED)%')]
        private readonly int $suspectMinUnacknowledged,
        #[Autowire('%env(int:PUSH_SUSPECT_MIN_HOURS)%')]
        private readonly int $suspectMinHours,
        #[Autowire('%env(int:PUSH_RENEWAL_COOLDOWN_HOURS)%')]
        private readonly int $renewalCooldownHours,
    ) {
    }

    public function autoRenewInvalidated(): bool { return $this->autoRenewInvalidated; }
    public function autoRenewSuspected(): bool { return $this->autoRenewSuspected; }
    /** Plancher à 2 : une seule absence d'accusé n'est jamais une preuve suffisante. */
    public function suspectMinUnacknowledged(): int { return max(2, $this->suspectMinUnacknowledged); }
    public function suspectMinHours(): int { return max(0, $this->suspectMinHours); }

    /** Vrai si une nouvelle demande de renouvellement peut être servie (aucune dans la fenêtre anti-boucle). */
    public function renewalAllowedSince(?\DateTimeImmutable $lastRequest, \DateTimeImmutable $now): bool
    {
        return $lastRequest === null
            || $lastRequest <= $now->modify(sprintf('-%d hours', max(1, $this->renewalCooldownHours)));
    }

    /** Une suspicion exige plusieurs envois sans accusé ET une durée minimale : jamais un seul envoi. */
    public function isSuspect(int $acceptedSinceReceipt, ?\DateTimeImmutable $firstUnacknowledgedAt, \DateTimeImmutable $now): bool
    {
        return $acceptedSinceReceipt >= $this->suspectMinUnacknowledged()
            && $firstUnacknowledgedAt !== null
            && $firstUnacknowledgedAt <= $now->modify(sprintf('-%d hours', $this->suspectMinHours()));
    }
}
