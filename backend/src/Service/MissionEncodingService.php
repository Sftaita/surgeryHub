<?php

namespace App\Service;

use App\Dto\Request\Response\FirmSlimDto;
use App\Dto\Request\Response\InterventionTypeSlimDto;
use App\Dto\Request\Response\MissionEncodingCatalogDto;
use App\Dto\Request\Response\MissionEncodingDto;
use App\Dto\Request\Response\MissionEncodingInterventionDto;
use App\Dto\Request\Response\MissionEncodingInterventionTypeRequestDto;
use App\Dto\Request\Response\MissionEncodingMaterialItemRequestDto;
use App\Dto\Request\Response\MissionEncodingMaterialLineDto;
use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\InterventionType;
use App\Entity\InterventionTypeRequest;
use App\Entity\MaterialItem;
use App\Entity\MaterialItemRequest;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class MissionEncodingService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MaterialCatalogService $catalogService,
        private readonly MaterialItemMapper $itemMapper,
        private readonly MissionActionsService $actionsService,
        private readonly MissionInterventionCoherenceService $coherenceService,
    ) {}

    public function buildEncodingDto(Mission $mission, User $viewer): MissionEncodingDto
    {
        $mission = $this->reloadForEncoding((int) ($mission->getId() ?? 0));

        // Lot 6 — un seul aller-retour DB pour les matériels suggérés de TOUTES les
        // interventions de cette mission (pas une requête par intervention) : on
        // rassemble d'abord les couples (interventionType, primaryFirm) réellement
        // présents, puis on charge en une fois les FirmServiceOffering correspondantes.
        $suggestedMaterialsByPair = $this->loadSuggestedMaterialsByTypeAndFirm($mission->getInterventions());

        $interventions = [];
        foreach ($mission->getInterventions() as $intervention) {
            $interventions[] = $this->mapIntervention($mission, $intervention, $suggestedMaterialsByPair);
        }

        usort(
            $interventions,
            static fn (MissionEncodingInterventionDto $a, MissionEncodingInterventionDto $b): int
                => [$a->orderIndex, $a->id] <=> [$b->orderIndex, $b->id]
        );

        $interventionTypeRequests = [];
        foreach ($mission->getInterventionTypeRequests() as $req) {
            if ($req->getStatus() !== InterventionTypeRequest::STATUS_PENDING) {
                continue;
            }
            $interventionTypeRequests[] = new MissionEncodingInterventionTypeRequestDto(
                id: (int) $req->getId(),
                label: (string) $req->getLabel(),
                suggestedCode: $req->getSuggestedCode(),
                comment: $req->getComment(),
            );
        }

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
            interventionTypeRequests: $interventionTypeRequests,
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

        $types = $this->em->getRepository(InterventionType::class)->createQueryBuilder('it')
            ->andWhere('it.active = :a')->setParameter('a', true)
            ->orderBy('it.label', 'ASC')
            ->getQuery()->getResult();

        $typeDtos = [];
        foreach ($types as $t) {
            $typeDtos[] = new InterventionTypeSlimDto(
                id: (int) $t->getId(),
                code: (string) $t->getCode(),
                label: (string) $t->getLabel(),
            );
        }

        return new MissionEncodingCatalogDto(
            items: $itemDtos,
            firms: $firmDtos,
            interventionTypes: $typeDtos,
        );
    }

    private function reloadForEncoding(int $missionId): Mission
    {
        $qb = $this->em->getRepository(Mission::class)->createQueryBuilder('m')
            ->leftJoin('m.interventions', 'i')->addSelect('i')
            ->leftJoin('i.interventionType', 'it')->addSelect('it')
            ->leftJoin('i.primaryFirm', 'pf')->addSelect('pf')
            ->leftJoin('m.materialLines', 'ml')->addSelect('ml')
            ->leftJoin('ml.item', 'item')->addSelect('item')
            ->leftJoin('item.firm', 'firm')->addSelect('firm')
            ->leftJoin('m.materialItemRequests', 'mir')->addSelect('mir')
            ->leftJoin('m.interventionTypeRequests', 'itr')->addSelect('itr')
            ->andWhere('m.id = :id')->setParameter('id', $missionId);

        $mission = $qb->getQuery()->getOneOrNullResult();

        if (!$mission instanceof Mission) {
            throw new \RuntimeException('Mission not found (encoding reload)');
        }

        return $mission;
    }

    /**
     * @param iterable<MissionIntervention> $interventions
     * @return array<string, list<\App\Dto\Request\Response\MaterialItemSlimDto>> matériels
     *         suggérés, clé "typeId:firmId"
     */
    private function loadSuggestedMaterialsByTypeAndFirm(iterable $interventions): array
    {
        $typeIds = [];
        $firmIds = [];
        foreach ($interventions as $i) {
            $type = $i->getInterventionType();
            $firm = $i->getPrimaryFirm();
            if ($type !== null && $firm !== null) {
                $typeIds[] = $type->getId();
                $firmIds[] = $firm->getId();
            }
        }

        if (empty($typeIds)) {
            return [];
        }

        // Alias "of" évité volontairement : c'est un token réservé par le parseur DQL
        // (erreur de syntaxe "Expected ... got 'of'") — d'où "off" ci-dessous.
        $offerings = $this->em->createQueryBuilder()
            ->select('o')
            ->from(FirmServiceOffering::class, 'o')
            ->leftJoin('o.interventionType', 'ot')->addSelect('ot')
            ->leftJoin('o.firm', 'off')->addSelect('off')
            ->leftJoin('o.suggestedMaterials', 'sm')->addSelect('sm')
            ->leftJoin('sm.materialItem', 'mi')->addSelect('mi')
            ->leftJoin('mi.firm', 'mif')->addSelect('mif')
            ->andWhere('ot.id IN (:types)')->setParameter('types', array_unique($typeIds))
            ->andWhere('off.id IN (:firms)')->setParameter('firms', array_unique($firmIds))
            ->andWhere('o.active = true')
            ->getQuery()
            ->getResult();

        $map = [];
        /** @var FirmServiceOffering $o */
        foreach ($offerings as $o) {
            $key = $o->getInterventionType()->getId() . ':' . $o->getFirm()->getId();
            $items = [];
            foreach ($o->getSuggestedMaterials() as $sm) {
                $item = $sm->getMaterialItem();
                if ($item !== null && $item->isActive()) {
                    $items[] = $this->itemMapper->toSlim($item);
                }
            }
            $map[$key] = $items;
        }

        return $map;
    }

    /**
     * @param array<string, list<\App\Dto\Request\Response\MaterialItemSlimDto>> $suggestedMaterialsByPair
     */
    private function mapIntervention(Mission $mission, MissionIntervention $i, array $suggestedMaterialsByPair = []): MissionEncodingInterventionDto
    {
        $lines = [];
        $rawLines = [];
        foreach ($mission->getMaterialLines() as $line) {
            if ($line->getMissionIntervention()?->getId() !== $i->getId()) {
                continue;
            }
            $lines[] = $this->mapMaterialLine($line);
            $rawLines[] = $line;
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
            if ($req->getStatus() !== MaterialItemRequest::STATUS_PENDING) {
                continue;
            }
            $requests[] = $this->mapMaterialItemRequest($req);
        }

        usort(
            $requests,
            static fn (MissionEncodingMaterialItemRequestDto $a, MissionEncodingMaterialItemRequestDto $b): int => $a->id <=> $b->id
        );

        $type = $i->getInterventionType();
        $typeDto = $type ? new InterventionTypeSlimDto(
            id: (int) $type->getId(),
            code: (string) $type->getCode(),
            label: (string) $type->getLabel(),
        ) : null;

        $primaryFirm = $i->getPrimaryFirm();
        $primaryFirmDto = $primaryFirm ? new FirmSlimDto(
            id: (int) $primaryFirm->getId(),
            name: (string) $primaryFirm->getName(),
        ) : null;

        $suggestedMaterials = [];
        if ($type !== null && $primaryFirm !== null) {
            $key = $type->getId() . ':' . $primaryFirm->getId();
            $suggestedMaterials = $suggestedMaterialsByPair[$key] ?? [];
        }
        $suggestedMaterialItemIds = array_map(static fn ($m) => $m->id, $suggestedMaterials);

        $coherence = $this->coherenceService->analyze($i, $rawLines, $suggestedMaterialItemIds);

        return new MissionEncodingInterventionDto(
            id: (int) $i->getId(),
            code: (string) $i->getCode(),
            label: (string) $i->getLabel(),
            orderIndex: (int) ($i->getOrderIndex() ?? 0),
            interventionType: $typeDto,
            primaryFirm: $primaryFirmDto,
            materialLines: $lines,
            materialItemRequests: $requests,
            suggestedMaterials: $suggestedMaterials,
            coherence: $coherence,
        );
    }

    private function mapMaterialLine(MaterialLine $l): MissionEncodingMaterialLineDto
    {
        $itemDto = $this->itemMapper->toSlim($l->getItem());

        $missionInterventionId = $l->getMissionIntervention()?->getId();
        $missionInterventionId = $missionInterventionId !== null ? (int) $missionInterventionId : null;

        $rawQty = $l->getQuantity();
        $qty = $rawQty === null ? '1.00' : number_format((float) $rawQty, 2, '.', '');

        return new MissionEncodingMaterialLineDto(
            id: (int) $l->getId(),
            missionInterventionId: $missionInterventionId,
            item: $itemDto,
            quantity: $qty,
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
