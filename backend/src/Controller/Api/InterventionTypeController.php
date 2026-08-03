<?php

namespace App\Controller\Api;

use App\Entity\FirmServiceOffering;
use App\Entity\InterventionType;
use App\Entity\PricingRule;
use App\Exception\InterventionTypeMergeConflictException;
use App\Security\Voter\BillingVoter;
use App\Service\InterventionEncodingContextService;
use App\Service\InterventionTypeAuditService;
use App\Service\InterventionTypeMergeService;
use App\Service\InterventionTypeSimilarityService;
use App\Service\PricingRuleResolver;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Référentiel médical fermé (Lot 1). Aucune notion financière ici — voir
 * docs/decisions.md. MANAGER/ADMIN uniquement pour la mutation ; la lecture reste
 * ouverte à tout utilisateur authentifié (utile pour l'encodage futur — Lot 5).
 */
#[Route('/api/intervention-types')]
final class InterventionTypeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InterventionEncodingContextService $encodingContextService,
        private readonly InterventionTypeSimilarityService $similarityService,
        private readonly InterventionTypeAuditService $auditService,
        private readonly InterventionTypeMergeService $mergeService,
        private readonly PricingRuleResolver $pricingRuleResolver,
    ) {}

    #[Route('', name: 'api_intervention_types_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $qb = $this->em->getRepository(InterventionType::class)->createQueryBuilder('it')
            ->orderBy('it.label', 'ASC');

        $activeParam = $request->query->get('active');
        if ($activeParam !== null) {
            $qb->andWhere('it.active = :active')->setParameter('active', filter_var($activeParam, FILTER_VALIDATE_BOOLEAN));
        }

        $types = $qb->getQuery()->getResult();

        // Task 11, section 4 — "nombre de firmes utilisant ce type" dans l'écran global
        // de gestion. Une seule requête groupée (pas de N+1) : les types sans
        // FirmServiceOffering sont simplement absents de la map, on retombe sur 0.
        $counts = $this->em->getRepository(FirmServiceOffering::class)->createQueryBuilder('o')
            ->select('IDENTITY(o.interventionType) AS typeId, COUNT(DISTINCT o.firm) AS cnt')
            ->groupBy('o.interventionType')
            ->getQuery()->getResult();
        $firmsCountByTypeId = array_column($counts, 'cnt', 'typeId');

        return $this->json(array_map(
            fn (InterventionType $t) => $this->serialize($t, (int) ($firmsCountByTypeId[$t->getId()] ?? 0)),
            $types,
        ));
    }

    /**
     * Task 11, section 6 — appelé par le frontend avant toute création (référentiel
     * global ET "Ajouter une prestation") pour suggérer un rapprochement manuel. Ne
     * bloque jamais : liste de candidats à confiance HIGH/MEDIUM/LOW, jamais une
     * décision automatique (voir InterventionTypeSimilarityService).
     */
    #[Route('/similar', name: 'api_intervention_types_similar', methods: ['GET'])]
    public function similar(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $label = trim((string) $request->query->get('label', ''));
        if ($label === '') {
            return $this->json([]);
        }
        $excludeId = $request->query->get('excludeId');

        $candidates = $this->similarityService->findCandidates($label, $this->em, $excludeId !== null ? (int) $excludeId : null);

        return $this->json(array_map(fn (array $c) => [
            'type' => $this->serialize($c['type'], null),
            'confidence' => $c['confidence'],
        ], $candidates));
    }

    /**
     * Task 11, section 3 — rapport d'audit complet (table demandée avant toute
     * modification de données). Lecture seule, ne fusionne jamais rien.
     */
    #[Route('/duplicate-audit', name: 'api_intervention_types_duplicate_audit', methods: ['GET'])]
    public function duplicateAudit(): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        return $this->json($this->auditService->buildAuditTable());
    }

    #[Route('', name: 'api_intervention_types_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $data = json_decode($request->getContent(), true) ?? [];
        $code = trim((string) ($data['code'] ?? ''));
        $label = trim((string) ($data['label'] ?? ''));

        if ($code === '' || $label === '') {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'code et label sont requis.']], 422);
        }

        $type = new InterventionType();
        $type->setCode($code);
        $type->setLabel($label);
        $type->setSpecialty(isset($data['specialty']) ? trim((string) $data['specialty']) ?: null : null);
        $type->setActive((bool) ($data['active'] ?? true));

        $this->em->persist($type);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->json(['error' => ['status' => 409, 'code' => 'CONFLICT', 'message' => 'Un type d\'intervention avec ce code existe déjà.']], 409);
        }

        return $this->json($this->serialize($type, null), Response::HTTP_CREATED);
    }

    /**
     * Lot 6 — réponse riche pour l'encodage : évite d'enchaîner GET prestations +
     * matériels suggérés + résolution de firme en plusieurs appels quand l'instrumentiste
     * sélectionne un type. Ouvert à tout utilisateur authentifié (comme list()) — c'est
     * l'instrumentiste, pas seulement le manager, qui en a besoin pendant l'encodage.
     */
    #[Route('/{id}/encoding-context', name: 'api_intervention_types_encoding_context', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function encodingContext(int $id): JsonResponse
    {
        $type = $this->getTypeOr404($id);
        if ($type instanceof JsonResponse) {
            return $type;
        }

        return $this->json($this->encodingContextService->buildContext($type));
    }

    /**
     * Catalogue > Prestations, refonte UX — détail d'une intervention globale : quelles
     * firmes l'utilisent et à quel forfait (écran "Référentiel", docs/design/screens/
     * catalogue-prestations/README.md). Réutilise PricingRuleResolver::
     * resolveInterventionFee() (même résolution que le moteur financier réel) au lieu de
     * réimplémenter une logique de tarif côté frontend — jamais de nouvelle règle
     * métier, une simple projection en lecture de FirmServiceOffering + PricingRule.
     */
    #[Route('/{id}/offerings', name: 'api_intervention_types_offerings', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function offerings(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $type = $this->getTypeOr404($id);
        if ($type instanceof JsonResponse) {
            return $type;
        }

        $today = new \DateTimeImmutable('today');
        $offerings = $this->em->getRepository(FirmServiceOffering::class)->createQueryBuilder('o')
            ->leftJoin('o.firm', 'f')->addSelect('f')
            ->andWhere('o.interventionType = :t')->setParameter('t', $type)
            ->orderBy('f.name', 'ASC')
            ->getQuery()->getResult();

        return $this->json(array_map(function (FirmServiceOffering $o) use ($today) {
            $firm = $o->getFirm();
            $forfait = $o->isFeeApplicable()
                ? $this->pricingRuleResolver->resolveInterventionFee($firm, $o->getInterventionType(), $today)
                : null;

            return [
                'offeringId' => $o->getId(),
                'firm' => [
                    'id' => $firm->getId(),
                    'name' => $firm->getName(),
                    'logoPath' => $firm->getLogoPath(),
                ],
                'active' => $o->isActive(),
                'feeApplicable' => $o->isFeeApplicable(),
                'forfait' => $forfait !== null ? [
                    'amount' => $forfait->getUnitPrice(),
                    'currency' => $forfait->getCurrency(),
                ] : null,
            ];
        }, $offerings));
    }

    /**
     * Ne permet jamais de modifier `code` (immuable après création — voir InterventionType::setCode).
     */
    #[Route('/{id}', name: 'api_intervention_types_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $type = $this->getTypeOr404($id);
        if ($type instanceof JsonResponse) {
            return $type;
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('label', $data)) {
            $label = trim((string) $data['label']);
            if ($label === '') {
                return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'label ne peut pas être vide.']], 422);
            }
            $type->setLabel($label);
        }

        if (array_key_exists('specialty', $data)) {
            $type->setSpecialty($data['specialty'] !== null ? trim((string) $data['specialty']) ?: null : null);
        }

        if (array_key_exists('active', $data)) {
            $type->setActive((bool) $data['active']);
        }

        $this->em->flush();

        return $this->json($this->serialize($type, null));
    }

    #[Route('/{id}', name: 'api_intervention_types_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $type = $this->getTypeOr404($id);
        if ($type instanceof JsonResponse) {
            return $type;
        }

        $usedByOffering = (int) $this->em->getRepository(FirmServiceOffering::class)->createQueryBuilder('o')
            ->select('COUNT(o.id)')->andWhere('o.interventionType = :t')->setParameter('t', $type)
            ->getQuery()->getSingleScalarResult();
        $usedByRule = (int) $this->em->getRepository(PricingRule::class)->createQueryBuilder('r')
            ->select('COUNT(r.id)')->andWhere('r.interventionType = :t')->setParameter('t', $type)
            ->getQuery()->getSingleScalarResult();

        if ($usedByOffering > 0 || $usedByRule > 0) {
            return $this->json([
                'error' => [
                    'status' => 409,
                    'code' => 'CONFLICT',
                    'message' => 'Ce type d\'intervention est utilisé par au moins une prestation ou une règle tarifaire — désactivez-le plutôt que de le supprimer.',
                ],
            ], 409);
        }

        $this->em->remove($type);
        $this->em->flush();

        return $this->json(['id' => $id, 'deleted' => true]);
    }

    /**
     * Task 11, section 7 — fusion explicite d'un doublon dans son type canonique.
     * Jamais automatique (voir InterventionTypeMergeService pour ce qui est réassigné
     * vs préservé tel quel).
     */
    #[Route('/{id}/merge', name: 'api_intervention_types_merge', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function merge(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $source = $this->getTypeOr404($id);
        if ($source instanceof JsonResponse) {
            return $source;
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $targetId = $data['targetId'] ?? null;
        if (!$targetId) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'targetId est requis.']], 422);
        }

        $target = $this->getTypeOr404((int) $targetId);
        if ($target instanceof JsonResponse) {
            return $target;
        }

        /** @var \App\Entity\User $actor */
        $actor = $this->getUser();

        try {
            $result = $this->mergeService->merge($source, $target, $actor);
        } catch (InterventionTypeMergeConflictException $e) {
            return $this->json([
                'error' => [
                    'status' => 409,
                    'code' => 'CONFLICT',
                    'message' => $e->getMessage(),
                    'conflictingFirms' => $e->getConflictingFirmNames(),
                ],
            ], 409);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => $e->getMessage()]], 422);
        }

        return $this->json([
            'source' => $this->serialize($source, null),
            'target' => $this->serialize($target, null),
            ...$result,
        ]);
    }

    private function getTypeOr404(int $id): InterventionType|JsonResponse
    {
        $type = $this->em->find(InterventionType::class, $id);
        if (!$type instanceof InterventionType) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Type d\'intervention introuvable.']], 404);
        }
        return $type;
    }

    private function serialize(InterventionType $t, ?int $firmsCount): array
    {
        $data = [
            'id' => $t->getId(),
            'code' => $t->getCode(),
            'label' => $t->getLabel(),
            'specialty' => $t->getSpecialty(),
            'active' => $t->isActive(),
            'mergedIntoId' => $t->getMergedInto()?->getId(),
        ];

        if ($firmsCount !== null) {
            $data['firmsCount'] = $firmsCount;
        }

        return $data;
    }
}
