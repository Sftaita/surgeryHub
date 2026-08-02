<?php

namespace App\Service;

use App\Dto\Request\Response\FirmServiceOfferingEncodingDto;
use App\Dto\Request\Response\FirmSlimDto;
use App\Dto\Request\Response\InterventionTypeEncodingContextDto;
use App\Dto\Request\Response\InterventionTypeSlimDto;
use App\Entity\FirmServiceOffering;
use App\Entity\InterventionType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lot 6 — construit, pour un InterventionType donné, tout ce dont l'encodage a besoin en
 * un seul appel : prestations actives (toutes firmes), matériels suggérés par prestation,
 * et une firme principale suggérée quand elle est non ambiguë (une seule prestation
 * active pour ce type). Le frontend ne recalcule rien de ceci.
 */
final class InterventionEncodingContextService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MaterialItemMapper $itemMapper,
    ) {}

    public function buildContext(InterventionType $type): InterventionTypeEncodingContextDto
    {
        /** @var FirmServiceOffering[] $offerings */
        $offerings = $this->em->createQueryBuilder()
            ->select('o')
            ->from(FirmServiceOffering::class, 'o')
            ->leftJoin('o.firm', 'f')->addSelect('f')
            ->leftJoin('o.suggestedMaterials', 'sm')->addSelect('sm')
            ->leftJoin('sm.materialItem', 'mi')->addSelect('mi')
            ->leftJoin('mi.firm', 'mif')->addSelect('mif')
            ->andWhere('o.interventionType = :type')->setParameter('type', $type)
            ->andWhere('o.active = true')
            ->orderBy('f.name', 'ASC')
            ->getQuery()
            ->getResult();

        $offeringDtos = [];
        foreach ($offerings as $o) {
            $suggested = [];
            foreach ($o->getSuggestedMaterials() as $sm) {
                $item = $sm->getMaterialItem();
                if ($item !== null && $item->isActive()) {
                    $suggested[] = $this->itemMapper->toSlim($item);
                }
            }

            $offeringDtos[] = new FirmServiceOfferingEncodingDto(
                offeringId: (int) $o->getId(),
                firm: new FirmSlimDto(id: (int) $o->getFirm()->getId(), name: (string) $o->getFirm()->getName()),
                label: $o->getLabel(),
                suggestedMaterials: $suggested,
                representativePresenceRelevant: $o->isRepresentativePresenceRelevant(),
            );
        }

        // Non ambigu seulement s'il existe exactement une prestation active pour ce type :
        // l'instrumentiste n'a alors rien à choisir, la firme principale se déduit d'elle-même.
        $suggestedPrimaryFirm = count($offeringDtos) === 1 ? $offeringDtos[0]->firm : null;

        return new InterventionTypeEncodingContextDto(
            interventionType: new InterventionTypeSlimDto(
                id: (int) $type->getId(),
                code: (string) $type->getCode(),
                label: (string) $type->getLabel(),
            ),
            suggestedPrimaryFirm: $suggestedPrimaryFirm,
            offerings: $offeringDtos,
        );
    }
}
