<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\CatalogueRequestKind;
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
 * Le chirurgien dispose depuis le Lot 2 du socle mobile partagé (D-095) d'un vrai
 * écran de détail mission (`/app/s/missions/{id}`, MissionDetailContent généralisé)
 * et d'un vrai planning (`/app/s/planning`) — les notifications chirurgien y renvoient
 * désormais directement, plus de repli générique vers `/app/s`.
 */
final class NotificationTargetResolver
{
    public function resolve(
        NotificationType $type,
        ?Mission $mission,
        User $recipient,
        ?int $requestId = null,
        ?CatalogueRequestKind $kind = null,
    ): ?string {
        $roles = $recipient->getRoles();
        $isManager = in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true);
        $isInstrumentist = in_array('ROLE_INSTRUMENTIST', $roles, true);
        $isSurgeon = in_array('ROLE_SURGEON', $roles, true);

        // Follow-up to D-093 — override ahead of the generic mission-based branch below:
        // a new catalogue proposal is actionable from CatalogueRequestsPage
        // (/app/m/catalogue/requests), not from the mission detail screen. The mission
        // FK still exists on the NotificationEvent for context/audit, it's just not
        // where a manager treats the request.
        //
        // Correctif workflow Demandes Catalogue (2026-09-04) — le deep-link porte
        // désormais le couple (kind, requestId) : MaterialItemRequest et
        // InterventionTypeRequest ont des espaces d'ID indépendants et peuvent partager
        // le même id, `requestId` seul serait ambigu côté frontend.
        if ($type === NotificationType::CATALOGUE_REQUEST_CREATED && $isManager) {
            if ($requestId !== null && $kind !== null) {
                return sprintf('/app/m/catalogue/requests?kind=%s&requestId=%d', $kind->value, $requestId);
            }
            return '/app/m/catalogue/requests';
        }

        // Lot 5 (D-099) — même raisonnement : une nouvelle SurgeonMissionRequest se
        // traite sur l'onglet dédié de MissionsListPage, jamais un détail de mission
        // (aucune Mission n'existe encore à ce stade). Un rejet n'a pas de Mission non
        // plus (état terminal, jamais créée) — renvoie sur "Mes demandes" (§11).
        if ($type === NotificationType::SURGEON_MISSION_REQUEST_CREATED && $isManager) {
            return '/app/m/missions/requests';
        }
        if ($type === NotificationType::SURGEON_MISSION_REQUEST_REJECTED && $isSurgeon) {
            return '/app/s/requests';
        }

        if ($mission !== null) {
            if ($isManager) {
                return '/app/m/missions/' . $mission->getId();
            }
            if ($isInstrumentist) {
                return '/app/i/missions/' . $mission->getId();
            }
            if ($isSurgeon) {
                return '/app/s/missions/' . $mission->getId();
            }
            return null;
        }

        return match ($type) {
            NotificationType::PLANNING_DEPLOYED_MANAGER => '/app/m/missions',
            NotificationType::PLANNING_DEPLOYED_INSTRUMENTIST => '/app/i/planning',
            NotificationType::PLANNING_DEPLOYED_SURGEON => '/app/s/planning',
            NotificationType::OPEN_MISSION_AVAILABLE => '/app/i/offers',
            NotificationType::PLANNING_ALERT => $isManager ? '/app/m/planning/v2' : null,
            NotificationType::PLANNING_RESENT_MANUAL => $isManager ? '/app/m/missions' : ($isInstrumentist ? '/app/i/planning' : ($isSurgeon ? '/app/s/planning' : null)),
            // Purement informatif ou sans cible connue aujourd'hui — jamais une route
            // devinée. Le frontend traite `null` comme non cliquable.
            default => null,
        };
    }
}
