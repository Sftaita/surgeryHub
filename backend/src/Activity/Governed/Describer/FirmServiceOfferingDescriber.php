<?php

namespace App\Activity\Governed\Describer;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Activity\Governed\AbstractGovernedDescriber;
use App\Activity\Governed\FrenchLabels;
use App\Activity\Governed\GovernedChange;
use App\Enum\ActivityEventType;
use App\Entity\FirmServiceOffering;

/** H5b (D-132) — a firm's service offering for an intervention type (fees, representative rules). */
final class FirmServiceOfferingDescriber extends AbstractGovernedDescriber
{
    public function entityClass(): string { return FirmServiceOffering::class; }

    public function trackedFields(): array
    {
        return [
            'firm' => 'Firme', 'interventionType' => 'Type d\'intervention', 'label' => 'Libellé', 'active' => 'Active',
            'representativePresenceRelevant' => 'Présence du représentant pertinente',
            'representativeSuppressesInterventionFee' => 'Représentant supprime le forfait intervention',
            'representativeSuppressesOwnMaterialFees' => 'Représentant supprime ses frais matériel',
            'feeApplicable' => 'Forfait applicable',
        ];
    }

    public function describe(GovernedChange $c): ?ActivityRecord
    {
        /** @var FirmServiceOffering $o */
        $o     = $c->entity;
        $name  = sprintf('« %s » (%s)', self::offeringName($o), $o->getFirm()?->getName() ?? '—');
        $actor = self::actorName($c);
        [$type, $summary] = match ($c->action) {
            GovernedChange::CREATED => [ActivityEventType::FIRM_OFFERING_CREATED, sprintf('%s a créé l\'offre %s', $actor, $name)],
            GovernedChange::DELETED => [ActivityEventType::FIRM_OFFERING_DELETED, sprintf('%s a supprimé l\'offre %s', $actor, $name)],
            default => [ActivityEventType::FIRM_OFFERING_UPDATED, sprintf('%s a modifié l\'offre %s : %s', $actor, $name, self::diffText($c))],
        };

        return $this->record($type, $c, 'FirmServiceOffering', $name, $summary, [
            'offeringId' => $c->entityId, 'offeringLabel' => self::offeringName($o), 'firmId' => $o->getFirm()?->getId(), 'firmName' => $o->getFirm()?->getName(),
            'interventionTypeId' => $o->getInterventionType()?->getId(),
        ]);
    }
}
