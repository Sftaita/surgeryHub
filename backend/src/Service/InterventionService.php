<?php

namespace App\Service;

use App\Dto\Request\MaterialLineCreateRequest;
use App\Dto\Request\MaterialLineUpdateRequest;
use App\Dto\Request\MissionInterventionCreateRequest;
use App\Dto\Request\MissionInterventionUpdateRequest;
use App\Entity\ChoiceOption;
use App\Entity\Firm;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionDraft;
use App\Entity\User;
use App\Exception\ChoiceOptionChangeRequiresConfirmationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class InterventionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MissionEntryOrderAllocator $orderAllocator,
        private readonly ActiveFirmResolver $firmResolver,
        private readonly ActiveInterventionTypeResolver $interventionTypeResolver,
        private readonly MaterialAttachmentResolver $attachmentResolver,
        private readonly RepresentativePolicyResolver $representativePolicyResolver,
        private readonly RequiredChoiceGroupResolver $choiceGroupResolver,
        private readonly ChoiceMaterialExclusivityService $exclusivityService,
    ) {}

    /**
     * Lot 5 (D-068) : `interventionTypeId` obligatoire (référentiel fermé) — `code`/`label`
     * ne sont plus fournis par le client, ils sont dérivés (instantané figé) depuis le
     * type résolu. `primaryFirmId` reste facultatif.
     *
     * EPIC Revue instrumentiste, Lot 3 — `$dto->orderIndex` n'est PLUS utilisé pour une
     * création : voir le docblock `@deprecated` sur MissionInterventionCreateRequest::
     * $orderIndex. Le serveur alloue désormais seul la position via
     * MissionEntryOrderAllocator (verrou pessimiste sur la mission, MAX+1 sur l'union
     * interventions réelles + drafts) — remplace l'absence totale d'allocation serveur
     * qui existait jusqu'ici (le client choisissait et envoyait sa propre position).
     */
    public function create(Mission $mission, MissionInterventionCreateRequest $dto): MissionIntervention
    {
        $type = $this->interventionTypeResolver->resolveActive((int) $dto->interventionTypeId);
        $firm = $dto->primaryFirmId !== null ? $this->firmResolver->resolveActive($dto->primaryFirmId) : null;
        $representativePresent = $dto->representativePresent;
        $selectedChoiceOption = $dto->selectedChoiceOptionId !== null
            ? $this->resolveChoiceOption($dto->selectedChoiceOptionId, $firm, $type)
            : null;

        $this->assertRepresentativePresenceAnswered($firm, $type, $representativePresent);
        $this->assertChoiceAnswered($firm, $type, $selectedChoiceOption);

        $intervention = null;
        $this->em->wrapInTransaction(function () use (&$intervention, $mission, $type, $firm, $representativePresent, $selectedChoiceOption): void {
            $orderIndex = $this->orderAllocator->nextIndexForNewEntry($mission);

            $intervention = new MissionIntervention();
            $intervention
                ->setMission($mission)
                ->setInterventionType($type)
                ->setPrimaryFirm($firm)
                ->setCode($type->getCode())
                ->setLabel($type->getLabel())
                ->setOrderIndex($orderIndex)
                ->setRepresentativePresent($representativePresent)
                ->setSelectedChoiceOption($selectedChoiceOption);

            $this->em->persist($intervention);
            $this->em->flush();
        });

        return $intervention;
    }

    /**
     * `interventionTypeId`, si fourni, re-dérive aussi le snapshot `code`/`label` (l'action
     * de changer explicitement le type sur CETTE intervention n'est pas la même chose que le
     * référentiel qui change ailleurs — voir MissionIntervention). `primaryFirmId` supporte
     * le retrait explicite (`primaryFirmIdProvided=true` + `primaryFirmId=null`).
     */
    public function update(MissionIntervention $intervention, MissionInterventionUpdateRequest $dto): void
    {
        $type = $dto->interventionTypeId !== null
            ? $this->interventionTypeResolver->resolveActive($dto->interventionTypeId)
            : $intervention->getInterventionType();

        $firm = $dto->primaryFirmIdProvided
            ? ($dto->primaryFirmId !== null ? $this->firmResolver->resolveActive($dto->primaryFirmId) : null)
            : $intervention->getPrimaryFirm();

        $representativePresent = $dto->representativePresentProvided
            ? $dto->representativePresent
            : $intervention->getRepresentativePresent();

        $selectedChoiceOption = $dto->selectedChoiceOptionIdProvided
            ? ($dto->selectedChoiceOptionId !== null ? $this->resolveChoiceOption($dto->selectedChoiceOptionId, $firm, $type) : null)
            : $intervention->getSelectedChoiceOption();

        // Refonte Catalogue/Prestations (D-092) — la validation ne se déclenche que si la
        // requête touche réellement un champ métier (type/firme/réponse délégué/choix
        // tarifaire), jamais sur un simple réordonnancement (orderIndex seul) : une
        // ancienne intervention jamais répondue reste réordonnable tant qu'on ne la
        // modifie pas au sens métier (voir docblock de section "rétrocompatibilité" —
        // l'ouverture/la consultation ne doivent jamais être cassées par cette règle).
        if ($dto->interventionTypeId !== null || $dto->primaryFirmIdProvided || $dto->representativePresentProvided || $dto->selectedChoiceOptionIdProvided) {
            $this->assertRepresentativePresenceAnswered($firm, $type, $representativePresent);
            $this->assertChoiceAnswered($firm, $type, $selectedChoiceOption);
        }

        // §8 du prompt — un changement de choix ne supprime jamais silencieusement du
        // matériel déjà encodé devenu incompatible : bloquant tant que le client n'a pas
        // explicitement confirmé (confirmRemoveIncompatibleMaterial).
        $choiceChanged = $dto->selectedChoiceOptionIdProvided
            && $intervention->getSelectedChoiceOption()?->getId() !== $selectedChoiceOption?->getId();
        $linesToRemove = [];
        if ($choiceChanged) {
            $linesToRemove = $this->exclusivityService->findLinesIncompatibleWith($intervention, $selectedChoiceOption);
            if ($linesToRemove !== [] && !$dto->confirmRemoveIncompatibleMaterial) {
                throw new ChoiceOptionChangeRequiresConfirmationException($linesToRemove, sprintf(
                    'Le matériel « %s » est actuellement encodé pour cette intervention. En sélectionnant « %s », il devra être retiré.',
                    $linesToRemove[0]->getItem()?->getLabel() ?? '—',
                    $selectedChoiceOption?->getLabel() ?? '—',
                ));
            }
        }

        $this->em->wrapInTransaction(function () use ($intervention, $dto, $type, $firm, $selectedChoiceOption, $choiceChanged, $linesToRemove): void {
            if ($dto->interventionTypeId !== null) {
                $intervention->setInterventionType($type);
                $intervention->setCode($type->getCode());
                $intervention->setLabel($type->getLabel());
            }

            if ($dto->primaryFirmIdProvided) {
                $intervention->setPrimaryFirm($firm);
            }

            if ($dto->orderIndex !== null) {
                $intervention->setOrderIndex($dto->orderIndex);
            }

            if ($dto->representativePresentProvided) {
                $intervention->setRepresentativePresent($dto->representativePresent);
            }

            if ($choiceChanged) {
                foreach ($linesToRemove as $line) {
                    $this->em->remove($line);
                }
                $intervention->setSelectedChoiceOption($selectedChoiceOption);
            }

            $this->em->flush();
        });
    }

    /**
     * Refonte Catalogue/Prestations (D-092) — défense serveur (jamais uniquement le
     * frontend) : si la prestation (firme × type) effective exige la réponse délégué,
     * elle doit être fournie (true/false), jamais null. Sans firme ou sans type résolu,
     * la question ne peut pas être pertinente (voir RepresentativePolicyResolver::resolve,
     * jamais appelé sans les deux).
     */
    private function assertRepresentativePresenceAnswered(?Firm $firm, ?InterventionType $type, ?bool $representativePresent): void
    {
        if ($firm === null || $type === null) {
            return;
        }

        $policy = $this->representativePolicyResolver->resolve($firm, $type);
        if ($policy->representativePresenceRelevant && $representativePresent === null) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Indiquez si un délégué de %s était présent.',
                $firm->getName(),
            ));
        }
    }

    /**
     * Résout et valide une ChoiceOption fournie par le client : doit exister, être
     * active, et appartenir au groupe de la prestation (firm, type) effectivement
     * concernée — jamais une option d'une autre prestation/firme (défense contre un id
     * arbitraire envoyé par un client compromis ou obsolète).
     */
    private function resolveChoiceOption(int $id, ?Firm $firm, ?InterventionType $type): ChoiceOption
    {
        $option = $this->em->find(ChoiceOption::class, $id);
        if (!$option instanceof ChoiceOption || !$option->isActive()) {
            throw new NotFoundHttpException('Option de choix introuvable.');
        }

        $offering = $option->getGroup()->getOffering();
        if ($firm === null || $type === null || $offering->getFirm()->getId() !== $firm->getId() || $offering->getInterventionType()->getId() !== $type->getId()) {
            throw new UnprocessableEntityHttpException('Cette option ne correspond pas à la prestation de cette intervention.');
        }

        return $option;
    }

    /**
     * Tarification firme conditionnée à un choix obligatoire — même défense serveur que
     * assertRepresentativePresenceAnswered() : si la prestation (firm × type) effective
     * exige une réponse, elle doit être fournie, jamais null.
     */
    private function assertChoiceAnswered(?Firm $firm, ?InterventionType $type, ?ChoiceOption $selected): void
    {
        if ($firm === null || $type === null) {
            return;
        }

        $policy = $this->choiceGroupResolver->resolve($firm, $type);
        if ($policy->required && $selected === null) {
            throw new UnprocessableEntityHttpException($policy->question ?? 'Une réponse est requise pour cette prestation.');
        }
    }

    public function delete(MissionIntervention $intervention): void
    {
        $this->em->remove($intervention);
        $this->em->flush();
    }

    // ---------------------------------------------------------------------
    // Material Lines (encodage instrumentiste) — firm dérivée via item->firm
    // ---------------------------------------------------------------------

    /**
     * EPIC Revue instrumentiste, Lot 3, commit 4 — la résolution de la cible
     * (missionInterventionId OU interventionDraftId, jamais les deux) passe désormais
     * par MaterialAttachmentResolver, seul endroit qui interprète le statut d'un draft
     * (redirection silencieuse CONVERTED/MATERIAL_REASSIGNED, 409 KEPT_AS_HISTORY).
     * Résolution + persistance dans une seule transaction : le verrou pessimiste posé
     * sur un draft éventuel l'exige.
     */
    public function createMaterialLine(Mission $mission, MaterialLineCreateRequest $dto, User $createdBy): MaterialLine
    {
        $item = $this->em->find(MaterialItem::class, $dto->itemId);
        if (!$item) {
            throw new NotFoundHttpException('Material item not found');
        }

        $line = null;
        $this->em->wrapInTransaction(function () use (&$line, $mission, $dto, $item, $createdBy): void {
            $target = $this->attachmentResolver->resolve($mission, $dto->missionInterventionId, $dto->interventionDraftId);

            $line = new MaterialLine();
            $line
                ->setMission($mission)
                ->setItem($item)
                ->setQuantity($dto->getQuantityAsString() ?? '1.00')
                ->setComment($dto->comment)
                ->setCreatedBy($createdBy);

            if ($target instanceof MissionIntervention) {
                $this->exclusivityService->assertMaterialCompatible($target, $item);
                $line->setMissionIntervention($target);
            } elseif ($target instanceof MissionInterventionDraft) {
                $line->setInterventionDraft($target);
            }

            $this->em->persist($line);
            $this->em->flush();
        });

        return $line;
    }

    public function updateMaterialLine(MaterialLine $line, MaterialLineUpdateRequest $dto): void
    {
        // MissionIntervention : uniquement si un id est fourni (sinon on ne touche pas)
        if ($dto->missionInterventionId !== null) {
            $intervention = $this->em->find(MissionIntervention::class, $dto->missionInterventionId);
            if (!$intervention) {
                throw new NotFoundHttpException('Mission intervention not found');
            }

            $missionId = $line->getMission()?->getId();
            if ($intervention->getMission()?->getId() !== $missionId) {
                throw new BadRequestHttpException('Intervention does not belong to mission');
            }

            $this->exclusivityService->assertMaterialCompatible($intervention, $line->getItem());
            $line->setMissionIntervention($intervention);
        }

        if ($dto->quantity !== null) {
            $line->setQuantity($dto->quantity);
        }

        if ($dto->comment !== null) {
            $line->setComment($dto->comment);
        }

        $this->em->flush();
    }

    public function deleteMaterialLine(MaterialLine $line): void
    {
        $this->em->remove($line);
        $this->em->flush();
    }
}
