<?php

namespace App\Controller\Api;

use App\Entity\FirmServiceOffering;
use App\Entity\InterventionType;
use App\Entity\PricingRule;
use App\Security\Voter\BillingVoter;
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
    public function __construct(private readonly EntityManagerInterface $em) {}

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

        return $this->json(array_map(fn (InterventionType $t) => $this->serialize($t), $types));
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

        return $this->json($this->serialize($type), Response::HTTP_CREATED);
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

        return $this->json($this->serialize($type));
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

    private function getTypeOr404(int $id): InterventionType|JsonResponse
    {
        $type = $this->em->find(InterventionType::class, $id);
        if (!$type instanceof InterventionType) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Type d\'intervention introuvable.']], 404);
        }
        return $type;
    }

    private function serialize(InterventionType $t): array
    {
        return [
            'id' => $t->getId(),
            'code' => $t->getCode(),
            'label' => $t->getLabel(),
            'specialty' => $t->getSpecialty(),
            'active' => $t->isActive(),
        ];
    }
}
