<?php
// src/Service/MaterialItemRequestService.php

namespace App\Service;

use App\Dto\Request\MaterialItemRequestCreateRequest;
use App\Entity\MaterialItemRequest;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionFirm;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class MaterialItemRequestService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MissionEncodingGuard $encodingGuard,
    ) {}

    public function create(Mission $mission, MaterialItemRequestCreateRequest $dto, User $createdBy): MaterialItemRequest
    {
        $this->encodingGuard->assertEncodingAllowed($mission, $createdBy);

        $req = new MaterialItemRequest();
        $req->setMission($mission);
        $req->setCreatedBy($createdBy);
        $req->setLabel((string) $dto->label);
        $req->setReferenceCode($dto->referenceCode ? trim($dto->referenceCode) : null);
        $req->setComment($dto->comment);

        $intervention = null;
        if ($dto->missionInterventionId) {
            $intervention = $this->em->find(MissionIntervention::class, $dto->missionInterventionId)
                ?? throw new NotFoundHttpException('Intervention not found');

            if ($intervention->getMission()?->getId() !== $mission->getId()) {
                throw new UnprocessableEntityHttpException('Intervention does not belong to mission');
            }

            $req->setMissionIntervention($intervention);
        }

        if ($dto->missionInterventionFirmId) {
            $firm = $this->em->find(MissionInterventionFirm::class, $dto->missionInterventionFirmId)
                ?? throw new NotFoundHttpException('Firm not found');

            $firmIntervention = $firm->getMissionIntervention()
                ?? throw new UnprocessableEntityHttpException('Firm is not linked to an intervention');

            if ($firmIntervention->getMission()?->getId() !== $mission->getId()) {
                throw new UnprocessableEntityHttpException('Firm does not belong to mission');
            }

            if ($intervention !== null && $firmIntervention->getId() !== $intervention->getId()) {
                throw new UnprocessableEntityHttpException('Firm does not belong to provided intervention');
            }

            if ($intervention === null) {
                $req->setMissionIntervention($firmIntervention);
            }

            $req->setMissionInterventionFirm($firm);
        }

        $this->em->persist($req);
        $this->em->flush();

        return $req;
    }
}
