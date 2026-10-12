<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * D-147 — VIEW (sans sujet) gate le journal d'activité transverse
 * (GET /api/manager/audit-events) : tous les AuditEvent, missions et événements globaux
 * (tarifs, demandes chirurgien, catalogue…). Réservé MANAGER/ADMIN — le journal par
 * mission reste MissionVoter::VIEW_AUDIT (chirurgien de la mission inclus).
 */
final class AuditJournalVoter extends Voter
{
    public const VIEW = 'AUDIT_JOURNAL_VIEW';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::VIEW;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $roles = $user->getRoles();

        return in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true);
    }
}
