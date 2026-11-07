<?php

namespace App\Activity\Governed\Describer;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Activity\Governed\AbstractGovernedDescriber;
use App\Activity\Governed\FrenchLabels;
use App\Activity\Governed\GovernedChange;
use App\Enum\ActivityEventType;
use App\Entity\ChoiceOption;

/** H5b (D-132) — an option of an offering's mandatory choice (re-ordering is cosmetic). */
final class ChoiceOptionDescriber extends AbstractGovernedDescriber
{
    public function entityClass(): string { return ChoiceOption::class; }

    public function trackedFields(): array
    {
        return ['label' => 'Libellé', 'materialItem' => 'Article', 'active' => 'Active'];
    }

    public function describe(GovernedChange $c): ?ActivityRecord
    {
        /** @var ChoiceOption $o */
        $o        = $c->entity;
        $offering = $o->getGroup()?->getOffering();
        $name     = sprintf('« %s »', self::offeringName($offering));
        $actor    = self::actorName($c);
        [$type, $summary] = match (true) {
            $c->action === GovernedChange::CREATED => [ActivityEventType::FIRM_OFFERING_CHOICE_OPTION_ADDED,
                sprintf('%s a ajouté l\'option « %s » au choix obligatoire de l\'offre %s', $actor, $o->getLabel() ?? '—', $name)],
            $c->action === GovernedChange::DELETED || ($c->changed('active') && !$o->isActive()) => [ActivityEventType::FIRM_OFFERING_CHOICE_OPTION_REMOVED,
                sprintf('%s a retiré l\'option « %s » du choix obligatoire de l\'offre %s', $actor, $o->getLabel() ?? '—', $name)],
            default => [ActivityEventType::FIRM_OFFERING_CHOICE_OPTION_UPDATED,
                sprintf('%s a modifié une option du choix obligatoire de l\'offre %s : %s', $actor, $name, self::diffText($c))],
        };

        return $this->record($type, $c, 'FirmServiceOffering', $name, $summary, [
            'offeringId' => $offering?->getId(), 'offeringLabel' => self::offeringName($offering), 'choiceGroupId' => $o->getGroup()?->getId(),
            'materialItemId' => $o->getMaterialItem()?->getId(), 'materialItemLabel' => $o->getMaterialItem()?->getLabel(),
        ]);
    }
}
