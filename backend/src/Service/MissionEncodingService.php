<?php

namespace App\Service;

use App\Dto\Request\Response\FirmSlimDto;
use App\Dto\Request\Response\InterventionTypeSlimDto;
use App\Dto\Request\Response\MissionEncodingCatalogDto;
use App\Dto\Request\Response\MissionEncodingCoherenceSummaryDto;
use App\Dto\Request\Response\MissionEncodingCommentDto;
use App\Dto\Request\Response\MissionEncodingDto;
use App\Dto\Request\Response\MissionEncodingEntryDto;
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
use App\Entity\MissionEncodingComment;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionDraft;
use App\Entity\User;
use App\Enum\MaterialLineBillingState;
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

        // EPIC Revue instrumentiste, Lot 3, commit 7 — un seul regroupement du matériel
        // de la mission, par cible réelle (attachmentTarget()), consommé à la fois par
        // mapIntervention() (déjà existant, refactoré pour ne plus lire les FK brutes) et
        // mapDraftToEntry() (nouveau) : une seule logique de rattachement, jamais deux
        // mécanismes divergents dans ce fichier.
        $grouped = $this->groupMaterialByAttachmentTarget($mission);

        $interventions = [];
        $entries = [];
        foreach ($mission->getInterventions() as $intervention) {
            $dto = $this->mapIntervention($mission, $intervention, $suggestedMaterialsByPair, $grouped);
            $interventions[] = $dto;
            $entries[] = $this->mapInterventionToEntry($intervention, $dto);
        }

        usort(
            $interventions,
            static fn (MissionEncodingInterventionDto $a, MissionEncodingInterventionDto $b): int
                => [$a->orderIndex, $a->id] <=> [$b->orderIndex, $b->id]
        );

        foreach ($mission->getMissionInterventionDrafts() as $draft) {
            $entry = $this->mapDraftToEntry($draft, $grouped);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        // Tri exclusivement par orderIndex. Tri secondaire déterministe (kind puis id) en
        // cas d'égalité anormale entre une intervention et un draft — MissionEntryOrderAllocator
        // ne doit jamais produire un tel chevauchement, mais si l'invariant est violé
        // (données corrompues, bug), les deux entrées restent visibles à un rang voisin
        // plutôt que l'une masquant silencieusement l'autre selon l'ordre de itération PHP.
        usort(
            $entries,
            static fn (MissionEncodingEntryDto $a, MissionEncodingEntryDto $b): int
                => [$a->orderIndex, $a->kind, $a->id] <=> [$b->orderIndex, $b->kind, $b->id]
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

        $coherenceSummary = $this->buildCoherenceSummary($interventions);

        $encodingComments = [];
        foreach ($mission->getEncodingComments() as $comment) {
            $encodingComments[] = $this->mapEncodingComment($comment);
        }

        return new MissionEncodingDto(
            mission: [
                'id' => (int) $mission->getId(),
                'type' => (string) $mission->getType()->value,
                'status' => (string) $mission->getStatus()->value,
                'allowedActions' => $allowedActions,
            ],
            interventions: $interventions,
            entries: $entries,
            interventionTypeRequests: $interventionTypeRequests,
            catalog: $catalog,
            coherenceSummary: $coherenceSummary,
            encodingComments: $encodingComments,
        );
    }

    /**
     * Lot 7 (D-070) — agrégation mission-level des signaux Lot 6 déjà calculés par
     * intervention : aucune requête supplémentaire, pure lecture en mémoire.
     *
     * @param MissionEncodingInterventionDto[] $interventions
     */
    private function buildCoherenceSummary(array $interventions): MissionEncodingCoherenceSummaryDto
    {
        $hasNoInterventions = count($interventions) === 0;
        $hasInterventionsWithNoMaterial = false;
        $hasUnusedSuggestions = false;
        $hasMaterialFromOtherFirm = false;
        $hasMissingPrimaryFirm = false;

        foreach ($interventions as $i) {
            if ($i->coherence->hasNoMaterialLines) {
                $hasInterventionsWithNoMaterial = true;
            }
            if (!empty($i->coherence->unusedSuggestedMaterialItemIds)) {
                $hasUnusedSuggestions = true;
            }
            if (!empty($i->coherence->materialLineIdsFromOtherFirm)) {
                $hasMaterialFromOtherFirm = true;
            }
            if ($i->primaryFirm === null) {
                $hasMissingPrimaryFirm = true;
            }
        }

        return new MissionEncodingCoherenceSummaryDto(
            hasNoInterventions: $hasNoInterventions,
            hasInterventionsWithNoMaterial: $hasInterventionsWithNoMaterial,
            hasUnusedSuggestions: $hasUnusedSuggestions,
            hasMaterialFromOtherFirm: $hasMaterialFromOtherFirm,
            hasMissingPrimaryFirm: $hasMissingPrimaryFirm,
        );
    }

    private function mapEncodingComment(MissionEncodingComment $c): MissionEncodingCommentDto
    {
        $author = $c->getAuthor();
        $authorName = $author !== null
            ? trim(($author->getFirstname() ?? '') . ' ' . ($author->getLastname() ?? ''))
            : '';

        return new MissionEncodingCommentDto(
            id: (int) $c->getId(),
            comment: (string) $c->getComment(),
            authorDisplayName: $authorName,
            createdAt: (string) $c->getCreatedAt()?->format(\DateTimeInterface::ATOM),
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
            ->leftJoin('m.missionInterventionDrafts', 'mid')->addSelect('mid')
            ->leftJoin('mid.requestedFirm', 'midf')->addSelect('midf')
            ->leftJoin('m.encodingComments', 'ec')->addSelect('ec')
            ->leftJoin('ec.author', 'eca')->addSelect('eca')
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
     * EPIC Revue instrumentiste, Lot 3, commit 7 — regroupe tout le matériel de la
     * mission (MaterialLine + MaterialItemRequest PENDING) par sa cible réelle, via
     * MaterialLine::attachmentTarget()/MaterialItemRequest::attachmentTarget() — jamais
     * en lisant directement getMissionIntervention()/getInterventionDraft() (voir
     * MaterialAttachmentTarget). Clé = spl_object_id() de la cible, pas son id métier :
     * une MissionIntervention et un MissionInterventionDraft sont deux tables avec deux
     * séquences indépendantes, un id numérique identique entre les deux ne signifierait
     * pas la même cible.
     *
     * @return array{lines: array<int, MaterialLine[]>, requests: array<int, MaterialItemRequest[]>}
     */
    private function groupMaterialByAttachmentTarget(Mission $mission): array
    {
        $lines = [];
        foreach ($mission->getMaterialLines() as $line) {
            $target = $line->attachmentTarget();
            if ($target === null) {
                continue;
            }
            $lines[spl_object_id($target)][] = $line;
        }

        $requests = [];
        foreach ($mission->getMaterialItemRequests() as $req) {
            if ($req->getStatus() !== MaterialItemRequest::STATUS_PENDING) {
                continue;
            }
            $target = $req->attachmentTarget();
            if ($target === null) {
                continue;
            }
            $requests[spl_object_id($target)][] = $req;
        }

        return ['lines' => $lines, 'requests' => $requests];
    }

    /**
     * @param array<string, list<\App\Dto\Request\Response\MaterialItemSlimDto>> $suggestedMaterialsByPair
     * @param array{lines: array<int, MaterialLine[]>, requests: array<int, MaterialItemRequest[]>} $grouped
     */
    private function mapIntervention(Mission $mission, MissionIntervention $i, array $suggestedMaterialsByPair, array $grouped): MissionEncodingInterventionDto
    {
        $rawLines = $grouped['lines'][spl_object_id($i)] ?? [];
        $lines = array_map($this->mapMaterialLine(...), $rawLines);

        usort(
            $lines,
            static fn (MissionEncodingMaterialLineDto $a, MissionEncodingMaterialLineDto $b): int => $a->id <=> $b->id
        );

        $rawRequests = $grouped['requests'][spl_object_id($i)] ?? [];
        $requests = array_map($this->mapMaterialItemRequest(...), $rawRequests);

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

    /**
     * EPIC Revue instrumentiste, Lot 3, commit 7 — une MissionIntervention réelle est
     * toujours "CATALOGUED" (MaterialLineBillingState::billingEligibility() de
     * MissionIntervention ne renvoie jamais autre chose) et toujours modifiable
     * (acceptsNewMaterial() toujours true) — dérivés de l'interface plutôt que codés en
     * dur, pour ne jamais diverger silencieusement si ce comportement changeait un jour.
     */
    private function mapInterventionToEntry(MissionIntervention $i, MissionEncodingInterventionDto $dto): MissionEncodingEntryDto
    {
        return new MissionEncodingEntryDto(
            kind: 'INTERVENTION',
            id: $dto->id,
            requestId: null,
            orderIndex: $dto->orderIndex,
            label: $dto->label,
            interventionType: $dto->interventionType,
            firm: $dto->primaryFirm,
            requestedFirmNameSnapshot: null,
            status: $i->billingEligibility()->value,
            readOnly: !$i->acceptsNewMaterial(),
            materialLines: $dto->materialLines,
            materialItemRequests: $dto->materialItemRequests,
        );
    }

    /**
     * EPIC Revue instrumentiste, Lot 3, commit 7 — un draft ne produit une entrée que
     * s'il reste utile à afficher :
     * - CONVERTED / MATERIAL_REASSIGNED : jamais d'entrée. Le matériel a déjà été
     *   repointé vers redirectTarget() (la vraie MissionIntervention, déjà présente dans
     *   $entries via mapInterventionToEntry()) — afficher le draft en plus dupliquerait
     *   la même position visuelle avec une ligne vide.
     * - KEPT_AS_HISTORY sans le moindre matériel : aucune entrée non plus (rien à montrer
     *   à l'instrumentiste/manager, voir consigne "visible uniquement si son matériel
     *   historique doit encore être montré").
     * - OPEN, ou KEPT_AS_HISTORY avec du matériel : une entrée, readOnly reflète
     *   acceptsNewMaterial() (false uniquement pour KEPT_AS_HISTORY).
     *
     * @param array{lines: array<int, MaterialLine[]>, requests: array<int, MaterialItemRequest[]>} $grouped
     */
    private function mapDraftToEntry(MissionInterventionDraft $draft, array $grouped): ?MissionEncodingEntryDto
    {
        if ($draft->getStatus() === MissionInterventionDraft::STATUS_CONVERTED
            || $draft->getStatus() === MissionInterventionDraft::STATUS_MATERIAL_REASSIGNED
        ) {
            return null;
        }

        $rawLines = $grouped['lines'][spl_object_id($draft)] ?? [];
        $lines = array_map($this->mapMaterialLine(...), $rawLines);
        usort($lines, static fn (MissionEncodingMaterialLineDto $a, MissionEncodingMaterialLineDto $b): int => $a->id <=> $b->id);

        $rawRequests = $grouped['requests'][spl_object_id($draft)] ?? [];
        $requests = array_map($this->mapMaterialItemRequest(...), $rawRequests);
        usort($requests, static fn (MissionEncodingMaterialItemRequestDto $a, MissionEncodingMaterialItemRequestDto $b): int => $a->id <=> $b->id);

        if ($draft->getStatus() === MissionInterventionDraft::STATUS_KEPT_AS_HISTORY
            && count($lines) === 0
            && count($requests) === 0
        ) {
            return null;
        }

        $requestedFirm = $draft->getRequestedFirm();
        $firmDto = $requestedFirm ? new FirmSlimDto(
            id: (int) $requestedFirm->getId(),
            name: (string) $requestedFirm->getName(),
        ) : null;

        return new MissionEncodingEntryDto(
            kind: 'DRAFT',
            id: $draft->getId(),
            requestId: $draft->getInterventionTypeRequest()->getId(),
            orderIndex: $draft->getOrderIndex(),
            label: (string) $draft->getLabel(),
            interventionType: null,
            firm: $firmDto,
            requestedFirmNameSnapshot: $draft->getRequestedFirmNameSnapshot(),
            status: $draft->getStatus(),
            readOnly: !$draft->acceptsNewMaterial(),
            materialLines: $lines,
            materialItemRequests: $requests,
        );
    }

    private function mapMaterialLine(MaterialLine $l): MissionEncodingMaterialLineDto
    {
        $itemDto = $this->itemMapper->toSlim($l->getItem());

        $missionInterventionId = $l->getMissionIntervention()?->getId();
        $missionInterventionId = $missionInterventionId !== null ? (int) $missionInterventionId : null;

        $interventionDraftId = $l->getInterventionDraft()?->getId();
        $interventionDraftId = $interventionDraftId !== null ? (int) $interventionDraftId : null;

        $rawQty = $l->getQuantity();
        $qty = $rawQty === null ? '1.00' : number_format((float) $rawQty, 2, '.', '');

        return new MissionEncodingMaterialLineDto(
            id: (int) $l->getId(),
            missionInterventionId: $missionInterventionId,
            item: $itemDto,
            quantity: $qty,
            comment: $l->getComment(),
            interventionDraftId: $interventionDraftId,
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
