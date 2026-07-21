<?php

namespace App\Service;

use App\Dto\Request\MaterialItemRequestCreateRequest;
use App\Entity\MaterialItemRequest;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionDraft;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class MaterialItemRequestService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MaterialAttachmentResolver $attachmentResolver,
    ) {}

    /**
     * EPIC Revue instrumentiste, Lot 3, commit 4 — même principe que
     * InterventionService::createMaterialLine() : la cible (missionInterventionId OU
     * interventionDraftId) est résolue via MaterialAttachmentResolver, dans la même
     * transaction que la persistance (verrou pessimiste éventuel sur un draft).
     */
    public function create(Mission $mission, MaterialItemRequestCreateRequest $dto, User $createdBy): MaterialItemRequest
    {
        $req = null;

        $this->em->wrapInTransaction(function () use (&$req, $mission, $dto, $createdBy): void {
            $target = $this->attachmentResolver->resolve($mission, $dto->missionInterventionId, $dto->interventionDraftId);

            $req = new MaterialItemRequest();
            $req
                ->setMission($mission)
                ->setLabel($dto->label)
                ->setReferenceCode($dto->referenceCode)
                ->setComment($dto->comment)
                ->setCreatedBy($createdBy);

            if ($target instanceof MissionIntervention) {
                $req->setMissionIntervention($target);
            } elseif ($target instanceof MissionInterventionDraft) {
                $req->setInterventionDraft($target);
            }

            $this->em->persist($req);
            $this->em->flush();
        });

        return $req;
    }
}
