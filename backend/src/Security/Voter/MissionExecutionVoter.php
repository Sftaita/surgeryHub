<?php

namespace App\Security\Voter;

use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\MissionExecutionDispute;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Service\MissionEncodingCorrectionPolicy;
use App\Service\MissionEncodingGuard;
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
 *
 * D-143 — UPDATE n'était borné par AUCUN statut : l'instrumentiste assignée pouvait
 * réécrire ses heures sur une mission SUBMITTED/VALIDATED, voire facturée, à l'insu du
 * manager qui avait validé. Désormais :
 *  - instrumentiste assignée : uniquement tant que l'encodage est modifiable par elle
 *    (mêmes statuts que 'edit_hours' de MissionActionsService, même garde
 *    MissionEncodingGuard : jamais avant le début, jamais verrouillé/facturé) ;
 *  - manager/admin (D-153) : MissionEncodingCorrectionPolicy::managerCanEditHours() —
 *    statuts corrigeables, jamais verrouillé/facturé/calcul LOCKED ; une correction après
 *    validation passe par reopen(), jamais par une écriture silencieuse.
 */
class MissionExecutionVoter extends Voter
{
    /** Mêmes statuts que 'edit_hours' (MissionActionsService) et MissionHoursReminderPolicy. */
    private const INSTRUMENTIST_EDITABLE_STATUSES = [
        MissionStatus::ASSIGNED,
        MissionStatus::IN_PROGRESS,
        MissionStatus::ENCODING_IN_PROGRESS,
        MissionStatus::DECLARED,
    ];

    public function __construct(
        private readonly MissionEncodingGuard $encodingGuard,
    ) {}

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
            self::UPDATE => $this->canUpdate($subject, $user, $isManager),
            self::DISPUTE_CREATE => $subject->getMission()?->getSurgeon()?->getId() === $user->getId(),
            self::DISPUTE_MANAGE => $isManager,
            default => false,
        };
    }

    private function canUpdate(Mission $mission, User $user, bool $isManager): bool
    {
        // D-171 — interventions et matériel validés par une reprise manager alors que les heures
        // manquaient : tant qu'elles manquent, elles restent déclarables (manager, instrumentiste
        // affectée), malgré le verrou de l'encodage. Même règle que 'edit_hours'.
        if (MissionEncodingCorrectionPolicy::hoursPendingAfterValidation($mission)) {
            return $isManager || $mission->getInstrumentist()?->getId() === $user->getId();
        }
        if ($mission->getEncodingLockedAt() !== null || $mission->getInvoiceGeneratedAt() !== null) {
            return false;
        }
        if ($isManager) {
            // D-153 — same rule as 'edit_hours' (MissionEncodingCorrectionPolicy).
            return MissionEncodingCorrectionPolicy::managerCanEditHours($mission);
        }
        if ($mission->getInstrumentist()?->getId() !== $user->getId()) {
            return false;
        }
        if (!in_array($mission->getStatus(), self::INSTRUMENTIST_EDITABLE_STATUSES, true)) {
            return false;
        }

        try {
            $this->encodingGuard->assertEncodingAllowed($mission, $user);
            return true;
        } catch (\Throwable) {
            return false;
        }
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
