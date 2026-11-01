<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * D-179 — relevé personnel « Mes heures » de l'instrumentiste.
 *
 * SELF_ACCESS (sans sujet, rôle seul, comme SurgeonActivityVoter::SELF_ACCESS) : l'endpoint
 * n'accepte jamais d'identifiant d'instrumentiste, le backend résout toujours l'utilisateur
 * authentifié (MyHoursController).
 *
 * VIEW_OWN_ESTIMATE (sujet = User) : SEULE exception à BillingVoter::MANAGE pour une
 * instrumentiste — l'estimation indicative de SES propres prestations (son tarif personnel ×
 * ses heures), jamais celle d'une autre instrumentiste, jamais un tarif firme, jamais un
 * calcul financier ou un relevé manager. Un manager n'y est pas admis : il dispose déjà des
 * écrans financiers sous BillingVoter.
 */
final class MyHoursVoter extends Voter
{
    public const SELF_ACCESS = 'MY_HOURS_SELF_ACCESS';
    public const VIEW_OWN_ESTIMATE = 'MY_HOURS_VIEW_OWN_ESTIMATE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::SELF_ACCESS => true,
            self::VIEW_OWN_ESTIMATE => $subject instanceof User,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !in_array('ROLE_INSTRUMENTIST', $user->getRoles(), true)) {
            return false;
        }

        return match ($attribute) {
            self::SELF_ACCESS => true,
            self::VIEW_OWN_ESTIMATE => $subject->getId() !== null && $subject->getId() === $user->getId(),
            default => false,
        };
    }
}
