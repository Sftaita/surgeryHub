<?php

namespace App\Service;

use App\Dto\Request\Response\FirmSlimDto;
use App\Dto\Request\Response\MissionEncodingCatalogDto;
use App\Dto\Request\Response\MissionEncodingDto;
use App\Dto\Request\Response\MissionEncodingInterventionDto;
use App\Dto\Request\Response\MissionEncodingMaterialItemRequestDto;
use App\Dto\Request\Response\MissionEncodingMaterialLineDto;
use App\Entity\Firm;
use App\Entity\MaterialItem;
use App\Entity\MaterialItemRequest;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\User;
use App\Service\MaterialCatalogService;
use App\Service\MaterialItemMapper;
use App\Service\MissionActionsService;
use Doctrine\ORM\EntityManagerInterface;

final class MissionEncodingService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MaterialCatalogService $catalogService,
        private readonly MaterialItemMapper $itemMapper,
        private readonly MissionActionsService $actionsService,
    ) {}

    public function buildEncodingDto(Mission $mission, User $viewer): MissionEncodingDto
    {
        $mission = $this->reloadForEncoding((int) ($mission->getId() ?? 0));

        $interventions = [];
        foreach ($mission->getInterventions() as $intervention) {
            $interventions[] = $this->mapIntervention($mission, $intervention);
        }

        usort(
            $interventions,
            static fn (MissionEncodingInterventionDto $a, MissionEncodingInterventionDto $b): int
                => [$a->orderIndex, $a->id] <=> [$b->orderIndex, $b->id]
        );

        $catalog = $this->buildCatalogDto();

        $allowedActions = $this->actionsService->allowedActions($mission, $viewer);

        return new MissionEncodingDto(
            mission: [
                'id' => (int) $mission->getId(),
                'type' => (string) $mission->getType()->value,
                'status' => (string) $mission->getStatus()->value,
                'allowedActions' => $allowedActions,
            ],
            interventions: $interventions,
            catalog: $catalog,
        );
    }

    private function buildCatalogDto(): MissionEncodingCatalogDto
    {
        $raw = $this->catalogService->getEncodingCatalog();

        /** @var list<Firm> $firms */
        $firms = $raw['firms'];
        /** @var list<MaterialItem> $items */
        $items = $raw['items'];

        $firmDtos = [];
        foreach ($firms as $f) {
            $firmDtos[] = new FirmSlimDto(
                id: (int) $f->getId(),
                name: (string) $f->getName(),
            );
        }

        $itemDtos = [];
        foreach ($items as $it) {
            $itemDtos[] = $this->itemMapper->toSlim($it);
        }

        return new MissionEncodingCatalogDto(
            items: $itemDtos,
            firms: $firmDtos,
        );
    }

    private function reloadForEncoding(int $missionId): Mission
    {
        $qb = $this->em->getRepository(Mission::class)->createQueryBuilder('m')
            ->leftJoin('m.interventions', 'i')->addSelect('i')
            ->leftJoin('m.materialLines', 'ml')->addSelect('ml')
            ->leftJoin('ml.item', 'item')->addSelect('item')
            ->leftJoin('item.firm', 'firm')->addSelect('firm')
            // IMPORTANT: on ne fetch-join pas ml.missionIntervention / mir.missionIntervention
            // (sinon MissionIntervention est hydratée via proxies -> warning dans ObjectHydrator)
            ->leftJoin('m.materialItemRequests', 'mir')->addSelect('mir')
            ->andWhere('m.id = :id')->setParameter('id', $missionId);

        $mission = $qb->getQuery()->getOneOrNullResult();

        if (!$mission instanceof Mission) {
            throw new \RuntimeException('Mission not found (encoding reload)');
        }

        return $mission;
    }

    private function mapIntervention(Mission $mission, MissionIntervention $i): MissionEncodingInterventionDto
    {
        $lines = [];
        foreach ($mission->getMaterialLines() as $line) {
            if ($line->getMissionIntervention()?->getId() !== $i->getId()) {
                continue;
            }
            $lines[] = $this->mapMaterialLine($line);
        }

        usort(
            $lines,
            static fn (MissionEncodingMaterialLineDto $a, MissionEncodingMaterialLineDto $b): int => $a->id <=> $b->id
        );

        $requests = [];
        foreach ($mission->getMaterialItemRequests() as $req) {
            if ($req->getMissionIntervention()?->getId() !== $i->getId()) {
                continue;
            }
            $requests[] = $this->mapMaterialItemRequest($req);
        }

        usort(
            $requests,
            static fn (MissionEncodingMaterialItemRequestDto $a, MissionEncodingMaterialItemRequestDto $b): int => $a->id <=> $b->id
        );

        return new MissionEncodingInterventionDto(
            id: (int) $i->getId(),
            code: (string) $i->getCode(),
            label: (string) $i->getLabel(),
            orderIndex: (int) ($i->getOrderIndex() ?? 0),
            materialLines: $lines,
            materialItemRequests: $requests,
        );
    }

    private function mapMaterialLine(MaterialLine $l): MissionEncodingMaterialLineDto
    {
        $itemDto = $this->itemMapper->toSlim($l->getItem());

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
