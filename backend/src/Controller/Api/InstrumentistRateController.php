<?php

namespace App\Controller\Api;

use App\Entity\InstrumentistRate;
use App\Entity\User;
use App\Enum\InstrumentistRateType;
use App\Security\Voter\BillingVoter;
use App\Service\InstrumentistRateService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * EPIC Exécution & Valorisation, Lot 2 (D-072) — gestion manager/admin des tarifs
 * instrumentistes historisés. Réutilise BillingVoter::MANAGE (même périmètre que
 * /api/firms/{id}/pricing-rules — "les tarifs relèvent uniquement du manager/admin",
 * §11 du lot) plutôt qu'un nouveau Voter redondant.
 */
#[Route('/api/instrumentists/{userId}/rates')]
final class InstrumentistRateController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InstrumentistRateService $rateService,
    ) {}

    #[Route('', name: 'api_instrumentist_rates_list', methods: ['GET'])]
    public function list(int $userId, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $instrumentist = $this->getInstrumentistOr404($userId);

        $qb = $this->em->createQueryBuilder()
            ->select('r')
            ->from(InstrumentistRate::class, 'r')
            ->where('r.instrumentist = :instrumentist')
            ->setParameter('instrumentist', $instrumentist)
            ->orderBy('r.validFrom', 'DESC');

        if ($rateType = $request->query->get('rateType')) {
            $qb->andWhere('r.rateType = :rateType')->setParameter('rateType', InstrumentistRateType::from($rateType));
        }

        $rates = $qb->getQuery()->getResult();

        return $this->json(array_map($this->serializeRate(...), $rates));
    }

    #[Route('', name: 'api_instrumentist_rates_create', methods: ['POST'])]
    public function create(int $userId, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $instrumentist = $this->getInstrumentistOr404($userId);

        $data = json_decode($request->getContent(), true) ?? [];
        $rateType = $this->parseRateType($data['rateType'] ?? null);
        $amount = $data['amount'] ?? null;
        if ($amount === null) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'amount est requis.']], 422);
        }
        $currency = isset($data['currency']) ? (string) $data['currency'] : 'EUR';
        $validFrom = $this->parseDate($data['validFrom'] ?? null) ?? new \DateTimeImmutable('today');
        $validTo = $this->parseDate($data['validTo'] ?? null);

        $today = new \DateTimeImmutable('today');
        $rate = $validFrom > $today
            ? $this->rateService->scheduleRate($instrumentist, $rateType, (string) $amount, $currency, $validFrom, $validTo, $actor)
            : $this->rateService->createInitialRate($instrumentist, $rateType, (string) $amount, $currency, $validFrom, $validTo, $actor);

        return $this->json($this->serializeRate($rate), 201);
    }

    #[Route('/{id}', name: 'api_instrumentist_rates_update', methods: ['PATCH'])]
    public function update(int $userId, int $id, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $this->getInstrumentistOr404($userId);
        $rate = $this->getRateOr404($id);

        $data = json_decode($request->getContent(), true) ?? [];

        $rate = $this->rateService->updateFutureRate(
            $rate,
            array_key_exists('amount', $data) ? (string) $data['amount'] : null,
            array_key_exists('currency', $data) ? (string) $data['currency'] : null,
            $this->parseDate($data['validFrom'] ?? null),
            array_key_exists('validTo', $data) ? $this->parseDate($data['validTo']) : null,
            $actor,
        );

        return $this->json($this->serializeRate($rate));
    }

    #[Route('/{id}', name: 'api_instrumentist_rates_delete', methods: ['DELETE'])]
    public function delete(int $userId, int $id, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $this->getInstrumentistOr404($userId);
        $rate = $this->getRateOr404($id);

        $this->rateService->cancelFutureRate($rate, $actor);

        return $this->json(['id' => $id, 'deleted' => true]);
    }

    #[Route('/{id}/replace', name: 'api_instrumentist_rates_replace', methods: ['POST'])]
    public function replace(int $userId, int $id, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);
        $this->getInstrumentistOr404($userId);
        $rate = $this->getRateOr404($id);

        $data = json_decode($request->getContent(), true) ?? [];
        $amount = $data['amount'] ?? null;
        $effectiveFrom = $this->parseDate($data['effectiveFrom'] ?? null);
        if ($amount === null || $effectiveFrom === null) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'amount et effectiveFrom sont requis.']], 422);
        }
        $currency = isset($data['currency']) ? (string) $data['currency'] : $rate->getCurrency();

        $newRate = $this->rateService->replaceCurrentRateFrom($rate, (string) $amount, $currency, $effectiveFrom, $actor);

        return $this->json($this->serializeRate($newRate), 201);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function getInstrumentistOr404(int $userId): User
    {
        $user = $this->em->find(User::class, $userId);
        if (!$user instanceof User || !in_array('ROLE_INSTRUMENTIST', $user->getRoles(), true)) {
            throw $this->createNotFoundException('Instrumentiste introuvable.');
        }
        return $user;
    }

    private function getRateOr404(int $id): InstrumentistRate
    {
        $rate = $this->em->find(InstrumentistRate::class, $id);
        if (!$rate instanceof InstrumentistRate) {
            throw $this->createNotFoundException('Tarif introuvable.');
        }
        return $rate;
    }

    private function parseRateType(mixed $value): InstrumentistRateType
    {
        if (!is_string($value)) {
            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('rateType requis (HOURLY_RATE ou CONSULTATION_FEE).');
        }
        try {
            return InstrumentistRateType::from($value);
        } catch (\ValueError) {
            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('rateType invalide (HOURLY_RATE ou CONSULTATION_FEE).');
        }
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Exception) {
            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('Date invalide (format Y-m-d attendu).');
        }
    }

    private function serializeRate(InstrumentistRate $r): array
    {
        return [
            'id' => $r->getId(),
            'instrumentist' => ['id' => $r->getInstrumentist()?->getId()],
            'rateType' => $r->getRateType()?->value,
            'amount' => $r->getAmount(),
            'currency' => $r->getCurrency(),
            'validFrom' => $r->getValidFrom()?->format('Y-m-d'),
            'validTo' => $r->getValidTo()?->format('Y-m-d'),
        ];
    }
}
