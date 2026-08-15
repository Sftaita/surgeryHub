<?php

namespace App\Service;

use App\Entity\ChoiceOption;
use App\Entity\MaterialItem;
use App\Entity\MissionIntervention;
use App\Exception\IncompatibleChoiceMaterialException;

/**
 * Tarification firme conditionnée à un choix obligatoire — exclusivité automatique entre
 * les options d'un même RequiredChoiceGroup (§4/§7 du prompt) : l'appartenance au même
 * groupe suffit, jamais une configuration manuelle "A exclut B". Ne connaît ni PricingRule
 * ni aucun montant — uniquement des relations ChoiceOption → MaterialItem.
 */
final class ChoiceMaterialExclusivityService
{
    /** @throws IncompatibleChoiceMaterialException */
    public function assertMaterialCompatible(MissionIntervention $intervention, MaterialItem $item): void
    {
        $selected = $intervention->getSelectedChoiceOption();
        if ($selected === null) {
            return;
        }

        foreach ($selected->getGroup()->getOptions() as $option) {
            if ($option->getId() === $selected->getId()) {
                continue;
            }
            $otherItem = $option->getMaterialItem();
            if ($otherItem !== null && $otherItem->getId() === $item->getId()) {
                throw new IncompatibleChoiceMaterialException(sprintf(
                    'Le matériel « %s » n\'est pas compatible avec « %s », actuellement sélectionné pour cette intervention.',
                    $item->getLabel(), $selected->getLabel(),
                ));
            }
        }
    }

    /**
     * Lignes de matériel déjà encodées sur $intervention qui deviendraient incompatibles
     * si $newSelection était sélectionnée à la place du choix actuel (§8 du prompt).
     * Vide si $newSelection est null (retrait du choix — jamais bloquant en soi) ou si
     * aucune ligne existante n'est concernée.
     *
     * @return \App\Entity\MaterialLine[]
     */
    public function findLinesIncompatibleWith(MissionIntervention $intervention, ?ChoiceOption $newSelection): array
    {
        if ($newSelection === null) {
            return [];
        }

        $incompatibleItemIds = [];
        foreach ($newSelection->getGroup()->getOptions() as $option) {
            if ($option->getId() === $newSelection->getId()) {
                continue;
            }
            $item = $option->getMaterialItem();
            if ($item !== null) {
                $incompatibleItemIds[] = $item->getId();
            }
        }

        if ($incompatibleItemIds === []) {
            return [];
        }

        $result = [];
        foreach ($intervention->getMaterialLines() as $line) {
            if (in_array($line->getItem()?->getId(), $incompatibleItemIds, true)) {
                $result[] = $line;
            }
        }

        return $result;
    }
}
