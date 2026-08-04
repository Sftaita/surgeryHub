<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\NotificationType;

/**
 * Point 4 (audit UX) — cible de navigation structurée pour une notification, calculée
 * côté serveur à partir de champs contrôlés (NotificationType, Mission FK, rôle du
 * destinataire) — jamais reconstruite côté frontend à partir d'un texte libre.
 *
 * Deux familles :
 * - notification rattachée à une Mission précise (`NotificationEvent.mission`, posé par
 *   MissionLifecycleChangedMessageHandler et consorts) → détail mission, route dérivée
 *   du rôle du destinataire ;
 * - notification agrégée (aucune Mission unique — déploiement, alerte planning, pool
 *   OPEN) → écran/contexte le plus pertinent pour ce type, jamais une mission arbitraire.
 *
 * Le chirurgien n'a aujourd'hui aucun écran de détail mission dédié (seule route:
 * `/app/s`, voir AppRouter.tsx) — les notifications chirurgien renvoient donc vers cet
 * unique écran existant plutôt que vers une route inventée. Limite documentée, pas un
 * bug de ce lot.
 */
final class NotificationTargetResolver
{
    public function resolve(NotificationType $type, ?Mission $mission, User $recipient): ?string
    {
        $roles = $recipient->getRoles();
        $isManager = in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true);
        $isInstrumentist = in_array('ROLE_INSTRUMENTIST', $roles, true);
        $isSurgeon = in_array('ROLE_SURGEON', $roles, true);

        // Follow-up to D-093 — override ahead of the generic mission-based branch below:
        // a new catalogue proposal is actionable from CatalogueRequestsPage
        // (/app/m/catalogue/requests), not from the mission detail screen. The mission
        // FK still exists on the NotificationEvent for context/audit, it's just not
        // where a manager treats the request.
        if ($type === NotificationType::CATALOGUE_REQUEST_CREATED && $isManager) {
            return '/app/m/catalogue/requests';
        }

        if ($mission !== null) {
            if ($isManager) {
                return '/app/m/missions/' . $mission->getId();
            }
            if ($isInstrumentist) {
                return '/app/i/missions/' . $mission->getId();
            }
            if ($isSurgeon) {
                return '/app/s';
            }
            return null;
        }

        return match ($type) {
            NotificationType::PLANNING_DEPLOYED_MANAGER => '/app/m/missions',
            NotificationType::PLANNING_DEPLOYED_INSTRUMENTIST => '/app/i/planning',
            NotificationType::PLANNING_DEPLOYED_SURGEON => '/app/s',
            NotificationType::OPEN_MISSION_AVAILABLE => '/app/i/offers',
            NotificationType::PLANNING_ALERT => $isManager ? '/app/m/planning/v2' : null,
            NotificationType::PLANNING_RESENT_MANUAL => $isManager ? '/app/m/missions' : ($isInstrumentist ? '/app/i/planning' : ($isSurgeon ? '/app/s' : null)),
            // Purement informatif ou sans cible connue aujourd'hui — jamais une route
            // devinée. Le frontend traite `null` comme non cliquable.
            default => null,
        };
    }
}
