<?php

namespace App\Service;

use App\Dto\Request\MaterialLineCreateRequest;
use App\Dto\Request\MaterialLineUpdateRequest;
use App\Dto\Request\MissionInterventionCreateRequest;
use App\Dto\Request\MissionInterventionFirmCreateRequest;
use App\Dto\Request\MissionInterventionFirmUpdateRequest;
use App\Dto\Request\MissionInterventionUpdateRequest;
use App\Entity\ImplantSubMission;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionFirm;
use App\Entity\User;
use App\Enum\MissionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InterventionService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function addIntervention(Mission $mission, MissionInterventionCreateRequest $dto): MissionIntervention
    {
        $intervention = new MissionIntervention();
        $intervention
            ->setMission($mission)
            ->setCode($dto->code)
            ->setLabel($dto->label)
            ->setOrderIndex($dto->orderIndex ?? 0);

        $this->em->persist($intervention);
        $this->em->flush();

        return $intervention;
    }

    public function updateIntervention(MissionIntervention $intervention, MissionInterventionUpdateRequest $dto): MissionIntervention
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

        return $intervention;
    }

    public function deleteIntervention(MissionIntervention $intervention): void
    {
        $this->em->remove($intervention);
        $this->em->flush();
    }

    public function addFirm(MissionIntervention $intervention, MissionInterventionFirmCreateRequest $dto): MissionInterventionFirm
    {
        $firm = new MissionInterventionFirm();
        $firm
            ->setMissionIntervention($intervention)
            ->setFirmName($dto->firmName);

        $this->em->persist($firm);
        $this->em->flush();

        return $firm;
    }

    public function updateFirm(MissionInterventionFirm $firm, MissionInterventionFirmUpdateRequest $dto): MissionInterventionFirm
    {
        if ($dto->firmName !== null) {
            $firm->setFirmName($dto->firmName);
        }

        $this->em->flush();

        return $firm;
    }

    public function deleteFirm(MissionInterventionFirm $firm): void
    {
        $this->em->remove($firm);
        $this->em->flush();
    }

    public function addMaterialLine(Mission $mission, MaterialLineCreateRequest $dto, User $createdBy): MaterialLine
    {
        if ($mission->getType() === MissionType::CONSULTATION) {
            throw new BadRequestHttpException('Material lines are forbidden for consultation missions');
        }

        $line = new MaterialLine();
        $line->setMission($mission);

        if ($dto->missionInterventionId) {
            $intervention = $this->em->find(MissionIntervention::class, $dto->missionInterventionId) ?? throw new NotFoundHttpException('Intervention not found');
            $line->setMissionIntervention($intervention);
        }

        if ($dto->missionInterventionFirmId) {
            $firm = $this->em->find(MissionInterventionFirm::class, $dto->missionInterventionFirmId) ?? throw new NotFoundHttpException('Firm not found');
            $line->setMissionInterventionFirm($firm);
        }

        $item = $this->em->find(MaterialItem::class, $dto->itemId) ?? throw new NotFoundHttpException('Material item not found');
        $line->setItem($item);
        $line->setQuantity((string) $dto->quantity);
        $line->setComment($dto->comment);
        $line->setCreatedBy($createdBy);

        if ($item->isImplant()) {
            $implantGroup = $this->resolveOrCreateImplantSubMission($mission, $line);
            $line->setImplantSubMission($implantGroup);
        }

        $this->em->persist($line);
        $this->em->flush();

        return $line;
    }

    public function updateMaterialLine(MaterialLine $line, MaterialLineUpdateRequest $dto): MaterialLine
    {
        $mission = $line->getMission();
        if ($mission->getType() === MissionType::CONSULTATION) {
            throw new BadRequestHttpException('Material lines are forbidden for consultation missions');
        }

        if ($dto->missionInterventionId) {
            $intervention = $this->em->find(MissionIntervention::class, $dto->missionInterventionId) ?? throw new NotFoundHttpException('Intervention not found');
            $line->setMissionIntervention($intervention);
        }

        if ($dto->missionInterventionFirmId) {
            $firm = $this->em->find(MissionInterventionFirm::class, $dto->missionInterventionFirmId) ?? throw new NotFoundHttpException('Firm not found');
            $line->setMissionInterventionFirm($firm);
        }

        if ($dto->itemId) {
            $item = $this->em->find(MaterialItem::class, $dto->itemId) ?? throw new NotFoundHttpException('Material item not found');
            $line->setItem($item);
        }

        if ($dto->quantity !== null) {
            $line->setQuantity((string) $dto->quantity);
        }

        if ($dto->comment !== null) {
            $line->setComment($dto->comment);
        }

        $this->em->flush();

        return $line;
    }

    public function deleteMaterialLine(MaterialLine $line): void
    {
        if ($line->getMission()->getType() === MissionType::CONSULTATION) {
            throw new BadRequestHttpException('Material lines are forbidden for consultation missions');
        }
        $this->em->remove($line);
        $this->em->flush();
    }

    private function resolveOrCreateImplantSubMission(Mission $mission, MaterialLine $line): ImplantSubMission
    {
        $firmName = $line->getMissionInterventionFirm()?->getFirmName() ?? $line->getItem()->getManufacturer() ?? 'UNKNOWN_FIRM';

        $existing = $this->em->getRepository(ImplantSubMission::class)->findOneBy([
            'mission' => $mission,
            'firmName' => $firmName,
        ]);

        if ($existing) {
            return $existing;
        }

        $sub = new ImplantSubMission();
        $sub->setMission($mission);
        $sub->setFirmName($firmName);

        $this->em->persist($sub);

        return $sub;
    }
}
