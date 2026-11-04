<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * D-180 — suivi manager des heures instrumentistes (liste, Top 5, détail individuel).
 *
 * VIEW (sans sujet) : réservé à MANAGER et ADMIN. Les heures et estimations de toutes les
 * instrumentistes ne sont jamais visibles d'une instrumentiste ni d'un chirurgien ; une
 * instrumentiste ne consulte que les siennes via MyHoursVoter (D-179), inchangé.
 */
final class InstrumentistHoursVoter extends Voter
{
    public const VIEW = 'INSTRUMENTIST_HOURS_VIEW';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::VIEW;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$user->isActive()) {
            return false;
        }
        $roles = $user->getRoles();

        return in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true);
    }
}
