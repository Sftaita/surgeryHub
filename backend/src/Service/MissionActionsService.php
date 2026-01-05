<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;

final class MissionActionsService
{
    /**
     * @return string[]
     */
    public function allowedActions(Mission $mission, User $viewer): array
    {
        $roles = $viewer->getRoles();
        $isManager = in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true);
        $isInstr = in_array('ROLE_INSTRUMENTIST', $roles, true);
        $isSurgeon = $mission->getSurgeon()?->getId() === $viewer->getId();
        $isAssignedInstr = $mission->getInstrumentist()?->getId() === $viewer->getId();

        $actions = ['view'];

        if ($isManager) {
            return match ($mission->getStatus()) {
                MissionStatus::DRAFT => ['view', 'edit', 'publish'],
                MissionStatus::OPEN => ['view', 'view_publications', 'cancel'],
                MissionStatus::ASSIGNED => ['view', 'cancel', 'reassign', 'view_claim'],
                MissionStatus::SUBMITTED => ['view', 'validate', 'reopen'],
                default => ['view'],
            };
        }

        // Instrumentiste : claim si OPEN
        if ($isInstr && $mission->getStatus() === MissionStatus::OPEN) {
            $actions[] = 'claim';
        }

        // Instrumentiste assigné : submit / edit_encoding
        if ($isInstr && $isAssignedInstr && in_array($mission->getStatus(), [MissionStatus::ASSIGNED, MissionStatus::IN_PROGRESS], true)) {
            $actions[] = 'edit_encoding';
            $actions[] = 'submit';
        }

        // Chirurgien : futur (rating, dispute hours) -> placeholders
        if ($isSurgeon) {
            $actions[] = 'rate_instrumentist';
            $actions[] = 'dispute_hours';
        }

        return array_values(array_unique($actions));
    }
}
