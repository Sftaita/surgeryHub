<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Lot 6 (D-100) — SELF_ACCESS (rôle SURGEON, sans sujet) gate la création/liste
 * self-scopée par le chirurgien ; l'appartenance à la Mission concernée est
 * revérifiée dans le service (même principe que SurgeonMissionRequestService pour
 * l'éligibilité de site, D-099). MANAGE (rôle MANAGER/ADMIN, sans sujet) gate la
 * résolution manager.
 */
final class EncodingAnomalyReportVoter extends Voter
{
    public const SELF_ACCESS = 'ENCODING_ANOMALY_REPORT_SELF_ACCESS';
    public const MANAGE = 'ENCODING_ANOMALY_REPORT_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::SELF_ACCESS, self::MANAGE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $roles = $user->getRoles();

        if ($attribute === self::SELF_ACCESS) {
            return in_array('ROLE_SURGEON', $roles, true);
        }

        return in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true);
    }
}
