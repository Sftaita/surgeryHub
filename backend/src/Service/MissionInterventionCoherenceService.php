<?php

namespace App\Service;

use App\Dto\Request\Response\MissionInterventionCoherenceDto;
use App\Entity\MaterialLine;
use App\Entity\MissionIntervention;

/**
 * Lot 6 — signaux de cohérence purement informationnels (jamais bloquants), préparés
 * pour de futurs contrôles qualité manager. Nécessite la liste des matériels suggérés
 * déjà résolue par l'appelant (offering firme principale × type) — ce service ne fait
 * aucune requête, seulement de la comparaison en mémoire.
 */
final class MissionInterventionCoherenceService
{
    /**
     * @param MaterialLine[] $lines lignes matériel de cette intervention, déjà chargées
     *        par l'appelant (jamais `$intervention->getMaterialLines()` ici : cette
     *        collection inverse n'est pas eager-jointe par MissionEncodingService et son
     *        parcours déclencherait une requête par intervention — voir reloadForEncoding()
     *        qui ne joint que Mission::materialLines)
     * @param int[] $suggestedMaterialItemIds matériels suggérés pour (interventionType,
     *        primaryFirm) de cette intervention — vide si primaryFirm n'est pas défini
     *        ou si aucune prestation ne couvre ce couple
     */
    public function analyze(MissionIntervention $intervention, array $lines, array $suggestedMaterialItemIds): MissionInterventionCoherenceDto
    {
        $primaryFirmId = $intervention->getPrimaryFirm()?->getId();

        $addedItemIds = [];
        $unexpected = [];
        $fromOtherFirm = [];

        foreach ($lines as $line) {
            $item = $line->getItem();
            $addedItemIds[] = $item->getId();

            if (!empty($suggestedMaterialItemIds) && !in_array($item->getId(), $suggestedMaterialItemIds, true)) {
                $unexpected[] = $item->getId();
            }

            if ($primaryFirmId !== null && $item->getFirm()?->getId() !== $primaryFirmId) {
                $fromOtherFirm[] = $line->getId();
            }
        }

        $unused = array_values(array_diff($suggestedMaterialItemIds, $addedItemIds));

        return new MissionInterventionCoherenceDto(
            hasNoMaterialLines: count($lines) === 0,
            unusedSuggestedMaterialItemIds: $unused,
            unexpectedMaterialItemIds: array_values(array_unique($unexpected)),
            materialLineIdsFromOtherFirm: $fromOtherFirm,
        );
    }
}
