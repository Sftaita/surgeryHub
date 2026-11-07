<?php

namespace App\Activity\Governed\Describer;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Activity\Governed\AbstractGovernedDescriber;
use App\Activity\Governed\FrenchLabels;
use App\Activity\Governed\GovernedChange;
use App\Enum\ActivityEventType;
use App\Entity\RequiredChoiceGroup;

/** H5b (D-132) — the mandatory choice an offering imposes at encoding time (D-099 family). */
final class RequiredChoiceGroupDescriber extends AbstractGovernedDescriber
{
    public function entityClass(): string { return RequiredChoiceGroup::class; }

    public function trackedFields(): array
    {
        return ['question' => 'Question', 'active' => 'Actif'];
    }

    public function describe(GovernedChange $c): ?ActivityRecord
    {
        /** @var RequiredChoiceGroup $g */
        $g        = $c->entity;
        $offering = $g->getOffering();
        $name     = sprintf('« %s »', self::offeringName($offering));
        $actor    = self::actorName($c);
        [$type, $summary] = match (true) {
            $c->action === GovernedChange::CREATED => [ActivityEventType::FIRM_OFFERING_CHOICE_GROUP_CREATED,
                sprintf('%s a ajouté un choix obligatoire à l\'offre %s : « %s »', $actor, $name, $g->getQuestion() ?? '—')],
            $c->action === GovernedChange::DELETED || ($c->changed('active') && !$g->isActive()) => [ActivityEventType::FIRM_OFFERING_CHOICE_GROUP_DELETED,
                sprintf('%s a retiré le choix obligatoire de l\'offre %s', $actor, $name)],
            default => [ActivityEventType::FIRM_OFFERING_CHOICE_GROUP_UPDATED,
                sprintf('%s a modifié le choix obligatoire de l\'offre %s : %s', $actor, $name, self::diffText($c))],
        };

        return $this->record($type, $c, 'FirmServiceOffering', $name, $summary, [
            'offeringId' => $offering?->getId(), 'offeringLabel' => self::offeringName($offering), 'firmId' => $offering?->getFirm()?->getId(), 'choiceGroupId' => $c->entityId,
        ]);
    }
}
