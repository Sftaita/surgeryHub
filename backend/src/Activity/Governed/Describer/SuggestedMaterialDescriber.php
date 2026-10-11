<?php

namespace App\Activity\Governed\Describer;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Activity\Governed\AbstractGovernedDescriber;
use App\Activity\Governed\FrenchLabels;
use App\Activity\Governed\GovernedChange;
use App\Enum\ActivityEventType;
use App\Entity\SuggestedMaterial;

/** H5b (D-132) — material suggested by an offering, added / removed (re-ordering is cosmetic). */
final class SuggestedMaterialDescriber extends AbstractGovernedDescriber
{
    public function entityClass(): string { return SuggestedMaterial::class; }

    public function trackedFields(): array
    {
        return ['materialItem' => 'Article'];
    }

    public function describe(GovernedChange $c): ?ActivityRecord
    {
        if ($c->action === GovernedChange::UPDATED) {
            return null;
        }
        /** @var SuggestedMaterial $s */
        $s        = $c->entity;
        $offering = $s->getFirmServiceOffering();
        $added    = $c->action === GovernedChange::CREATED;

        return $this->record($added ? ActivityEventType::FIRM_OFFERING_MATERIAL_ADDED : ActivityEventType::FIRM_OFFERING_MATERIAL_REMOVED, $c,
            'FirmServiceOffering', sprintf('« %s »', self::offeringName($offering)),
            sprintf('%s a %s l\'article « %s » %s l\'offre « %s » (%s)', self::actorName($c), $added ? 'ajouté' : 'retiré',
                $s->getMaterialItem()?->getLabel() ?? '—', $added ? 'à' : 'de', self::offeringName($offering), $offering?->getFirm()?->getName() ?? '—'),
            [
                'offeringId' => $offering?->getId(), 'offeringLabel' => self::offeringName($offering), 'firmId' => $offering?->getFirm()?->getId(),
                'materialItemId' => $s->getMaterialItem()?->getId(), 'materialItemLabel' => $s->getMaterialItem()?->getLabel(),
            ]);
    }
}
