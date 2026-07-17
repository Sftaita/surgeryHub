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
use App\Entity\User;
use App\Exception\InterventionTypeInactiveException;
use App\Exception\InterventionTypeNotFoundException;
use App\Exception\PrimaryFirmInactiveException;
use App\Exception\PrimaryFirmNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InterventionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Lot 5 (D-068) : `interventionTypeId` obligatoire (référentiel fermé) — `code`/`label`
     * ne sont plus fournis par le client, ils sont dérivés (instantané figé) depuis le
     * type résolu. `primaryFirmId` reste facultatif.
     */
    public function create(Mission $mission, MissionInterventionCreateRequest $dto): MissionIntervention
    {
        $type = $this->resolveActiveInterventionType((int) $dto->interventionTypeId);
        $firm = $dto->primaryFirmId !== null ? $this->resolveActiveFirm($dto->primaryFirmId) : null;

        $intervention = new MissionIntervention();
        $intervention
            ->setMission($mission)
            ->setInterventionType($type)
            ->setPrimaryFirm($firm)
            ->setCode($type->getCode())
            ->setLabel($type->getLabel())
            ->setOrderIndex($dto->orderIndex);

        $this->em->persist($intervention);
        $this->em->flush();

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
        if ($dto->interventionTypeId !== null) {
            $type = $this->resolveActiveInterventionType($dto->interventionTypeId);
            $intervention->setInterventionType($type);
            $intervention->setCode($type->getCode());
            $intervention->setLabel($type->getLabel());
        }

        if ($dto->primaryFirmIdProvided) {
            $firm = $dto->primaryFirmId !== null ? $this->resolveActiveFirm($dto->primaryFirmId) : null;
            $intervention->setPrimaryFirm($firm);
        }

        if ($dto->orderIndex !== null) {
            $intervention->setOrderIndex($dto->orderIndex);
        }

        $this->em->flush();
    }

    private function resolveActiveInterventionType(int $interventionTypeId): InterventionType
    {
        $type = $this->em->find(InterventionType::class, $interventionTypeId);
        if (!$type instanceof InterventionType) {
            throw new InterventionTypeNotFoundException('Type d\'intervention introuvable.');
        }
        if (!$type->isActive()) {
            throw new InterventionTypeInactiveException('Ce type d\'intervention est désactivé.');
        }
        return $type;
    }

    private function resolveActiveFirm(int $firmId): Firm
    {
        $firm = $this->em->find(Firm::class, $firmId);
        if (!$firm instanceof Firm) {
            throw new PrimaryFirmNotFoundException('Firme introuvable.');
        }
        if (!$firm->isActive()) {
            throw new PrimaryFirmInactiveException('Cette firme est désactivée.');
        }
        return $firm;
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
