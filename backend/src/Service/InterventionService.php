<?php

namespace App\Service;

use App\Dto\Request\MaterialLineCreateRequest;
use App\Dto\Request\MaterialLineUpdateRequest;
use App\Dto\Request\MissionInterventionCreateRequest;
use App\Dto\Request\MissionInterventionUpdateRequest;
use App\Entity\Firm;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionDraft;
use App\Entity\User;
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

        $this->assertRepresentativePresenceAnswered($firm, $type, $representativePresent);

        $intervention = null;
        $this->em->wrapInTransaction(function () use (&$intervention, $mission, $type, $firm, $representativePresent): void {
            $orderIndex = $this->orderAllocator->nextIndexForNewEntry($mission);

            $intervention = new MissionIntervention();
            $intervention
                ->setMission($mission)
                ->setInterventionType($type)
                ->setPrimaryFirm($firm)
                ->setCode($type->getCode())
                ->setLabel($type->getLabel())
                ->setOrderIndex($orderIndex)
                ->setRepresentativePresent($representativePresent);

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

        // Refonte Catalogue/Prestations (D-092) — la validation ne se déclenche que si la
        // requête touche réellement un champ métier (type/firme/réponse délégué), jamais
        // sur un simple réordonnancement (orderIndex seul) : une ancienne intervention
        // jamais répondue reste réordonnable tant qu'on ne la modifie pas au sens métier
        // (voir docblock de section "rétrocompatibilité" — l'ouverture/la consultation ne
        // doivent jamais être cassées par cette règle).
        if ($dto->interventionTypeId !== null || $dto->primaryFirmIdProvided || $dto->representativePresentProvided) {
            $this->assertRepresentativePresenceAnswered($firm, $type, $representativePresent);
        }

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

        $this->em->flush();
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
