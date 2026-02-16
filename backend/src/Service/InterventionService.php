<?php

namespace App\Service;

use App\Dto\Request\MaterialLineCreateRequest;
use App\Dto\Request\MaterialLineUpdateRequest;
use App\Dto\Request\MissionInterventionCreateRequest;
use App\Dto\Request\MissionInterventionUpdateRequest;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InterventionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function create(Mission $mission, MissionInterventionCreateRequest $dto): MissionIntervention
    {
        $intervention = new MissionIntervention();
        $intervention
            ->setMission($mission)
            ->setCode($dto->code)
            ->setLabel($dto->label)
            ->setOrderIndex($dto->orderIndex);

        $this->em->persist($intervention);
        $this->em->flush();

        return $intervention;
    }

    public function update(MissionIntervention $intervention, MissionInterventionUpdateRequest $dto): void
    {
        if ($dto->code !== null) {
            $intervention->setCode($dto->code);
        }

        if ($dto->label !== null) {
            $intervention->setLabel($dto->label);
        }

        if ($dto->orderIndex !== null) {
            $intervention->setOrderIndex($dto->orderIndex);
        }

        $this->em->flush();
    }

    public function delete(MissionIntervention $intervention): void
    {
        $this->em->remove($intervention);
        $this->em->flush();
    }

    // ---------------------------------------------------------------------
    // Material Lines (encodage instrumentiste) — firm dérivée via item->firm
    // ---------------------------------------------------------------------

    public function createMaterialLine(Mission $mission, MaterialLineCreateRequest $dto, User $createdBy): MaterialLine
    {
        $item = $this->em->find(MaterialItem::class, $dto->itemId);
        if (!$item) {
            throw new NotFoundHttpException('Material item not found');
        }

        $intervention = null;
        if ($dto->missionInterventionId !== null) {
            $intervention = $this->em->find(MissionIntervention::class, $dto->missionInterventionId);
            if (!$intervention) {
                throw new NotFoundHttpException('Mission intervention not found');
            }
            if ($intervention->getMission()?->getId() !== $mission->getId()) {
                throw new BadRequestHttpException('Intervention does not belong to mission');
            }
        }

        $line = new MaterialLine();
        $line
            ->setMission($mission)
            ->setMissionIntervention($intervention)
            ->setItem($item)
            ->setCreatedBy($createdBy);

        if ($dto->quantity !== null) {
            $line->setQuantity($dto->quantity);
        }

        if ($dto->comment !== null) {
            $line->setComment($dto->comment);
        }

        $this->em->persist($line);
        $this->em->flush();

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
