<?php

namespace App\Service;

use App\Dto\Request\Response\MissionEncodingDto;
use App\Dto\Request\Response\MissionEncodingFirmDto;
use App\Dto\Request\Response\MissionEncodingInterventionDto;
use App\Dto\Request\Response\MissionEncodingMaterialItemRequestDto;
use App\Dto\Request\Response\MissionEncodingMaterialLineDto;
use App\Dto\Request\Response\MaterialItemSlimDto;
use App\Entity\MaterialItemRequest;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionFirm;
use Doctrine\ORM\EntityManagerInterface;

final class MissionEncodingService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function buildEncodingDto(Mission $mission): MissionEncodingDto
    {
        // Important : on force le chargement en une fois pour éviter N+1.
        // On recharge la mission avec les associations nécessaires.
        $mission = $this->reloadForEncoding($mission->getId() ?? 0);

        $interventions = [];
        foreach ($mission->getInterventions() as $intervention) {
            $interventions[] = $this->mapIntervention($intervention);
        }

        // Tri stable: orderIndex ASC puis id ASC (utile UI)
        usort($interventions, static function (MissionEncodingInterventionDto $a, MissionEncodingInterventionDto $b): int {
            return [$a->orderIndex, $a->id] <=> [$b->orderIndex, $b->id];
        });

        return new MissionEncodingDto(
            missionId: (int) $mission->getId(),
            missionType: (string) $mission->getType()?->value,
            missionStatus: (string) $mission->getStatus()?->value,
            interventions: $interventions,
        );
    }

    private function reloadForEncoding(int $missionId): Mission
    {
        $qb = $this->em->getRepository(Mission::class)->createQueryBuilder('m')
            ->leftJoin('m.interventions', 'i')->addSelect('i')
            ->leftJoin('i.firms', 'f')->addSelect('f')
            ->leftJoin('m.materialLines', 'ml')->addSelect('ml')
            ->leftJoin('ml.item', 'item')->addSelect('item')
            ->leftJoin('m.materialItemRequests', 'mir')->addSelect('mir')
            ->leftJoin('mir.missionIntervention', 'miri')->addSelect('miri')
            ->leftJoin('mir.missionInterventionFirm', 'mirf')->addSelect('mirf')
            ->andWhere('m.id = :id')->setParameter('id', $missionId);

        $mission = $qb->getQuery()->getOneOrNullResult();

        if (!$mission instanceof Mission) {
            throw new \RuntimeException('Mission not found (encoding reload)');
        }

        return $mission;
    }

    private function mapIntervention(MissionIntervention $i): MissionEncodingInterventionDto
    {
        $firms = [];
        foreach ($i->getFirms() as $firm) {
            $firms[] = $this->mapFirm($firm);
        }

        // Tri stable: firmName ASC puis id ASC
        usort($firms, static function (MissionEncodingFirmDto $a, MissionEncodingFirmDto $b): int {
            return [$a->firmName, $a->id] <=> [$b->firmName, $b->id];
        });

        return new MissionEncodingInterventionDto(
            id: (int) $i->getId(),
            code: (string) $i->getCode(),
            label: (string) $i->getLabel(),
            orderIndex: (int) ($i->getOrderIndex() ?? 0),
            firms: $firms,
        );
    }

    private function mapFirm(MissionInterventionFirm $f): MissionEncodingFirmDto
    {
        $mission = $f->getMissionIntervention()?->getMission();

        $lines = [];
        if ($mission) {
            foreach ($mission->getMaterialLines() as $line) {
                // On garde uniquement les lignes rattachées à CETTE firm + CETTE intervention
                if ($line->getMissionInterventionFirm()?->getId() !== $f->getId()) {
                    continue;
                }
                if ($line->getMissionIntervention()?->getId() !== $f->getMissionIntervention()?->getId()) {
                    continue;
                }

                $lines[] = $this->mapMaterialLine($line);
            }
        }

        // Tri stable: id ASC (ordre de création)
        usort($lines, static fn (MissionEncodingMaterialLineDto $a, MissionEncodingMaterialLineDto $b): int => $a->id <=> $b->id);

        $requests = [];
        if ($mission) {
            foreach ($mission->getMaterialItemRequests() as $req) {
                // Demandes rattachées à cette firm + cette intervention
                if ($req->getMissionInterventionFirm()?->getId() !== $f->getId()) {
                    continue;
                }
                if ($req->getMissionIntervention()?->getId() !== $f->getMissionIntervention()?->getId()) {
                    continue;
                }

                $requests[] = $this->mapMaterialItemRequest($req);
            }
        }

        usort($requests, static fn (MissionEncodingMaterialItemRequestDto $a, MissionEncodingMaterialItemRequestDto $b): int => $a->id <=> $b->id);

        return new MissionEncodingFirmDto(
            id: (int) $f->getId(),
            firmName: (string) $f->getFirmName(),
            materialLines: $lines,
            materialItemRequests: $requests,
        );
    }

    private function mapMaterialLine(MaterialLine $l): MissionEncodingMaterialLineDto
    {
        $item = $l->getItem();

        $itemDto = new MaterialItemSlimDto(
            id: (int) $item->getId(),
            manufacturer: $item->getManufacturer(),
            referenceCode: (string) $item->getReferenceCode(),
            label: (string) $item->getLabel(),
            unit: (string) $item->getUnit(),
            isImplant: (bool) $item->isImplant(),
        );

        return new MissionEncodingMaterialLineDto(
            id: (int) $l->getId(),
            item: $itemDto,
            quantity: (string) ($l->getQuantity() ?? '1.00'),
            comment: $l->getComment(),
        );
    }

    private function mapMaterialItemRequest(MaterialItemRequest $r): MissionEncodingMaterialItemRequestDto
    {
        return new MissionEncodingMaterialItemRequestDto(
            id: (int) $r->getId(),
            label: (string) $r->getLabel(),
            referenceCode: $r->getReferenceCode(),
            comment: $r->getComment(),
        );
    }
}
