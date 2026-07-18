<?php

namespace App\Security\Voter;

use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\MissionExecutionDispute;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Lot 1 (Exécution & Valorisation) — renommage de ServiceVoter, permissions inchangées.
 *
 * VIEW/UPDATE sont évalués sur Mission (pas MissionExecution) : contrairement à
 * l'ancien ServiceVoter, la permission est vérifiée AVANT toute création paresseuse de
 * MissionExecution — l'ancien flux (ServiceController::findOrCreateService() appelé
 * avant denyAccessUnlessGranted()) persistait une ligne vide avant de refuser l'accès
 * en cas d'appel non autorisé ; corrigé ici en gardant Mission comme sujet du vote.
 */
class MissionExecutionVoter extends Voter
{
    public const VIEW            = 'MISSION_EXECUTION_VIEW';
    public const UPDATE          = 'MISSION_EXECUTION_UPDATE';
    public const DISPUTE_CREATE  = 'MISSION_EXECUTION_DISPUTE_CREATE';
    public const DISPUTE_MANAGE  = 'MISSION_EXECUTION_DISPUTE_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::VIEW, self::UPDATE => $subject instanceof Mission,
            self::DISPUTE_CREATE     => $subject instanceof MissionExecution,
            self::DISPUTE_MANAGE     => $subject instanceof MissionExecutionDispute,
            default                  => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $isManager = in_array('ROLE_MANAGER', $user->getRoles(), true) || in_array('ROLE_ADMIN', $user->getRoles(), true);

        return match ($attribute) {
            self::VIEW => $this->canView($subject, $user, $isManager),
            self::UPDATE => $isManager || $subject->getInstrumentist()?->getId() === $user->getId(),
            self::DISPUTE_CREATE => $subject->getMission()?->getSurgeon()?->getId() === $user->getId(),
            self::DISPUTE_MANAGE => $isManager,
            default => false,
        };
    }

    /** @param Mission $mission */
    private function canView(mixed $mission, User $user, bool $isManager): bool
    {
        if ($isManager) {
            return true;
        }
        if ($mission->getInstrumentist()?->getId() === $user->getId()) {
            return true;
        }
        return $mission->getSurgeon()?->getId() === $user->getId();
    }
}
