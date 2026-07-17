<?php

namespace App\Controller\Api;

use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\SuggestedMaterial;
use App\Security\Voter\BillingVoter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Prestations" (nom technique : FirmServiceOffering) + leurs matériels suggérés.
 * Accélérateur de saisie uniquement — jamais lu par le moteur financier
 * (PricingRuleResolver). Voir docs/decisions.md.
 */
#[Route('/api/firms/{firmId}/service-offerings')]
final class FirmServiceOfferingController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('', name: 'api_firm_offerings_list', methods: ['GET'])]
    public function list(int $firmId): JsonResponse
    {
        $firm = $this->getFirmOr404($firmId);
        if ($firm instanceof JsonResponse) {
            return $firm;
        }

        $offerings = $this->em->getRepository(FirmServiceOffering::class)->createQueryBuilder('o')
            ->leftJoin('o.interventionType', 'it')->addSelect('it')
            ->andWhere('o.firm = :firm')->setParameter('firm', $firm)
            ->orderBy('it.label', 'ASC')
            ->getQuery()->getResult();

        return $this->json(array_map(fn (FirmServiceOffering $o) => $this->serialize($o), $offerings));
    }

    /**
     * Création explicite uniquement — jamais implicite en arrière-plan (voir prompt Lot 1).
     */
    #[Route('', name: 'api_firm_offerings_create', methods: ['POST'])]
    public function create(int $firmId, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $firm = $this->getFirmOr404($firmId);
        if ($firm instanceof JsonResponse) {
            return $firm;
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $interventionTypeId = $data['interventionTypeId'] ?? null;

        if (!$interventionTypeId) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'interventionTypeId est requis.']], 422);
        }

        $type = $this->em->find(InterventionType::class, (int) $interventionTypeId);
        if (!$type instanceof InterventionType) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Type d\'intervention introuvable.']], 404);
        }

        $offering = new FirmServiceOffering();
        $offering->setFirm($firm);
        $offering->setInterventionType($type);
        $offering->setLabel(isset($data['label']) ? trim((string) $data['label']) ?: null : null);
        $offering->setActive(true);

        $this->em->persist($offering);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->json([
                'error' => [
                    'status' => 409,
                    'code' => 'CONFLICT',
                    'message' => 'Une prestation existe déjà pour cette firme et ce type d\'intervention.',
                ],
            ], 409);
        }

        return $this->json($this->serialize($offering), Response::HTTP_CREATED);
    }

    #[Route('/{offeringId}', name: 'api_firm_offerings_update', methods: ['PATCH'], requirements: ['offeringId' => '\d+'])]
    public function update(int $firmId, int $offeringId, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $offering = $this->getOfferingOr404($firmId, $offeringId);
        if ($offering instanceof JsonResponse) {
            return $offering;
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('label', $data)) {
            $offering->setLabel($data['label'] !== null ? trim((string) $data['label']) ?: null : null);
        }
        if (array_key_exists('active', $data)) {
            $offering->setActive((bool) $data['active']);
        }

        $this->em->flush();

        return $this->json($this->serialize($offering));
    }

    // ── Matériels suggérés ───────────────────────────────────────────────

    #[Route('/{offeringId}/suggested-materials', name: 'api_firm_offering_suggestions_create', methods: ['POST'], requirements: ['offeringId' => '\d+'])]
    public function addSuggestedMaterial(int $firmId, int $offeringId, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $offering = $this->getOfferingOr404($firmId, $offeringId);
        if ($offering instanceof JsonResponse) {
            return $offering;
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $materialItemId = $data['materialItemId'] ?? null;
        if (!$materialItemId) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'materialItemId est requis.']], 422);
        }

        $item = $this->em->find(MaterialItem::class, (int) $materialItemId);
        if (!$item instanceof MaterialItem) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Matériel introuvable.']], 404);
        }

        // Garde applicatif — doublé par la contrainte FK composée en base (voir migration).
        if ($item->getFirm()?->getId() !== $offering->getFirm()->getId()) {
            return $this->json([
                'error' => [
                    'status' => 422,
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'Le matériel suggéré doit appartenir à la même firme que la prestation.',
                ],
            ], 422);
        }

        $maxOrder = $this->em->getRepository(SuggestedMaterial::class)->createQueryBuilder('sm')
            ->select('MAX(sm.displayOrder)')
            ->andWhere('sm.firmServiceOffering = :o')->setParameter('o', $offering)
            ->getQuery()->getSingleScalarResult();
        $nextOrder = $maxOrder === null ? 0 : ((int) $maxOrder + 1);

        $suggestion = new SuggestedMaterial();
        $suggestion->setFirmServiceOffering($offering);
        $suggestion->setFirm($offering->getFirm());
        $suggestion->setMaterialItem($item);
        $suggestion->setDisplayOrder($nextOrder);

        $this->em->persist($suggestion);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->json(['error' => ['status' => 409, 'code' => 'CONFLICT', 'message' => 'Ce matériel est déjà suggéré pour cette prestation.']], 409);
        }

        return $this->json($this->serializeSuggestion($suggestion), Response::HTTP_CREATED);
    }

    /**
     * Réordonnancement complet (glisser-déposer côté frontend) — évite les mises à jour
     * partielles d'un seul displayOrder qui laisseraient la liste dans un état incohérent.
     */
    #[Route('/{offeringId}/suggested-materials/reorder', name: 'api_firm_offering_suggestions_reorder', methods: ['PATCH'], requirements: ['offeringId' => '\d+'])]
    public function reorderSuggestedMaterials(int $firmId, int $offeringId, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $offering = $this->getOfferingOr404($firmId, $offeringId);
        if ($offering instanceof JsonResponse) {
            return $offering;
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $orderedIds = $data['orderedIds'] ?? null;
        if (!is_array($orderedIds)) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'orderedIds est requis (liste d\'identifiants).']], 422);
        }

        $suggestions = $offering->getSuggestedMaterials();
        $byId = [];
        foreach ($suggestions as $s) {
            $byId[$s->getId()] = $s;
        }

        if (count($orderedIds) !== count($byId) || array_diff(array_map('intval', $orderedIds), array_keys($byId)) !== []) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'orderedIds doit contenir exactement les suggestions de cette prestation.']], 422);
        }

        foreach (array_values($orderedIds) as $index => $id) {
            $byId[(int) $id]->setDisplayOrder($index);
        }

        $this->em->flush();

        return $this->json(array_map(fn (SuggestedMaterial $s) => $this->serializeSuggestion($s), iterator_to_array($offering->getSuggestedMaterials())));
    }

    #[Route('/{offeringId}/suggested-materials/{suggestionId}', name: 'api_firm_offering_suggestions_delete', methods: ['DELETE'], requirements: ['offeringId' => '\d+', 'suggestionId' => '\d+'])]
    public function deleteSuggestedMaterial(int $firmId, int $offeringId, int $suggestionId): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $offering = $this->getOfferingOr404($firmId, $offeringId);
        if ($offering instanceof JsonResponse) {
            return $offering;
        }

        $suggestion = $this->em->find(SuggestedMaterial::class, $suggestionId);
        if (!$suggestion instanceof SuggestedMaterial || $suggestion->getFirmServiceOffering()->getId() !== $offering->getId()) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Suggestion introuvable.']], 404);
        }

        // Suppression toujours physique : aucune incidence historique ou financière.
        $this->em->remove($suggestion);
        $this->em->flush();

        return $this->json(['id' => $suggestionId, 'deleted' => true]);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function getFirmOr404(int $id): Firm|JsonResponse
    {
        $firm = $this->em->find(Firm::class, $id);
        if (!$firm instanceof Firm) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Firme introuvable.']], 404);
        }
        return $firm;
    }

    private function getOfferingOr404(int $firmId, int $offeringId): FirmServiceOffering|JsonResponse
    {
        $offering = $this->em->find(FirmServiceOffering::class, $offeringId);
        if (!$offering instanceof FirmServiceOffering || $offering->getFirm()->getId() !== $firmId) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Prestation introuvable.']], 404);
        }
        return $offering;
    }

    private function serialize(FirmServiceOffering $o): array
    {
        return [
            'id' => $o->getId(),
            'firmId' => $o->getFirm()->getId(),
            'interventionType' => [
                'id' => $o->getInterventionType()->getId(),
                'code' => $o->getInterventionType()->getCode(),
                'label' => $o->getInterventionType()->getLabel(),
            ],
            'label' => $o->getLabel(),
            'active' => $o->isActive(),
            'suggestedMaterials' => array_map(
                fn (SuggestedMaterial $s) => $this->serializeSuggestion($s),
                iterator_to_array($o->getSuggestedMaterials()),
            ),
        ];
    }

    private function serializeSuggestion(SuggestedMaterial $s): array
    {
        $item = $s->getMaterialItem();
        return [
            'id' => $s->getId(),
            'displayOrder' => $s->getDisplayOrder(),
            'materialItem' => [
                'id' => $item->getId(),
                'label' => $item->getLabel(),
                'referenceCode' => $item->getReferenceCode(),
                'active' => $item->isActive(),
            ],
        ];
    }
}
