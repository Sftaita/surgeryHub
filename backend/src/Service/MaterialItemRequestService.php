<?php

namespace App\Service;

use App\Dto\Request\MaterialItemRequestCreateRequest;
use App\Entity\MaterialItemRequest;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MaterialItemRequestService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function create(Mission $mission, MaterialItemRequestCreateRequest $dto): MaterialItemRequest
    {
        $req = new MaterialItemRequest();
        $req
            ->setMission($mission)
            ->setLabel($dto->label)
            ->setReferenceCode($dto->referenceCode)
            ->setComment($dto->comment);

        if ($dto->missionInterventionId !== null) {
            $intervention = $this->em->find(MissionIntervention::class, $dto->missionInterventionId);
            if (!$intervention || $intervention->getMission()?->getId() !== $mission->getId()) {
                throw new NotFoundHttpException('Mission intervention not found');
            }
            $req->setMissionIntervention($intervention);
        }

        $this->em->persist($req);
        $this->em->flush();

        return $req;
    }
}
