<?php

namespace App\Service\Prestation;

use App\Entity\Mission;

/**
 * Complément D-156 — mission de référence d'une prestation multi-salles : la mission qui
 * reçoit TOUT nouvel encodage de contenu (interventions, matériel, demandes au catalogue).
 * L'instrumentiste ne choisit jamais de salle ; les missions restent distinctes pour le
 * planning et la répartition des heures (WorkedPresence), jamais pour le contenu.
 *
 * Règle (fonction pure, décidée par le serveur uniquement) :
 * - parmi les missions qui portent déjà du contenu, celle dont l'identifiant est le plus
 *   petit (une seule mission encodée → c'est elle) ;
 * - sinon, la mission dont l'identifiant est le plus petit.
 *
 * Stabilité : une mission créée plus tard a toujours un identifiant plus grand, et une
 * mission qui porte du contenu ne quitte jamais sa prestation en silence (annulation,
 * réaffectation, déplacement : MissionEncodingDataGuard). Avant tout contenu, changer de
 * référence est sans effet.
 *
 * Le contenu historique d'autres missions de la prestation (encodé avant ce complément) reste
 * sur sa mission d'origine : jamais déplacé, jamais dupliqué — seulement regroupé à l'affichage.
 */
final class PrestationReference
{
    /** @param list<Mission> $group */
    public static function of(array $group): Mission
    {
        $byId = $group;
        usort($byId, static fn (Mission $a, Mission $b) => (int) $a->getId() <=> (int) $b->getId());
        foreach ($byId as $mission) {
            if (self::carriesContent($mission)) {
                return $mission;
            }
        }

        return $byId[0];
    }

    /** Interventions, lignes de matériel ou demandes au catalogue (quel que soit leur statut). */
    public static function carriesContent(Mission $mission): bool
    {
        return !$mission->getInterventions()->isEmpty()
            || !$mission->getMaterialLines()->isEmpty()
            || !$mission->getMissionInterventionDrafts()->isEmpty()
            || !$mission->getMaterialItemRequests()->isEmpty()
            || !$mission->getInterventionTypeRequests()->isEmpty();
    }

    /**
     * Missions de la prestation, autres que la référence, qui portent du contenu historique.
     *
     * @param list<Mission> $group
     * @return list<int> ids triés
     */
    public static function otherEncodedMissionIds(array $group): array
    {
        $reference = self::of($group);
        $ids = [];
        foreach ($group as $mission) {
            if ($mission->getId() !== $reference->getId() && self::carriesContent($mission)) {
                $ids[] = (int) $mission->getId();
            }
        }
        sort($ids);

        return $ids;
    }
}
