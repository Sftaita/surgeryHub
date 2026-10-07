<?php

namespace App\Controller\Api;

use App\Entity\Firm;
use App\Entity\User;
use App\Enum\FirmBillingStatus;
use App\Security\Voter\BillingVoter;
use App\Service\FirmBilling\FirmBillingRecalculationService;
use App\Service\FirmBilling\FirmBillingWorklistExporter;
use App\Service\FirmBilling\FirmBillingWorklistService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * D-133 — worklist « Facturation firmes » : lecture (projection), export d'une sélection,
 * et calcul groupé. Toute l'autorisation passe par BillingVoter::MANAGE.
 */
#[Route('/api/firm-billing')]
class FirmBillingWorklistController extends AbstractController
{
    private const MAX_EXPORT_KEYS = 5000;

    public function __construct(
        private readonly FirmBillingWorklistService $worklist,
        private readonly FirmBillingWorklistExporter $exporter,
        private readonly FirmBillingRecalculationService $recalculation,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * GET /api/firm-billing/worklist?from=AAAA-MM-JJ&to=AAAA-MM-JJ[&firmIds[]=1&firmIds[]=2]
     *   [&type=INTERVENTION|MATERIAL][&status=BILLABLE|NOT_BILLABLE|TO_REVIEW|INVOICED]
     */
    #[Route('/worklist', name: 'api_firm_billing_worklist', methods: ['GET'])]
    public function worklist(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        [$from, $to, $error] = $this->period($request->query->get('from'), $request->query->get('to'));
        if ($error !== null) {
            return $error;
        }
        [$firmIds, $error] = $this->firmIds($this->firmIdsInput($request->query->all()['firmIds'] ?? null));
        if ($error !== null) {
            return $error;
        }

        $type = $request->query->get('type');
        if ($type !== null && $type !== '' && !in_array($type, [FirmBillingWorklistService::TYPE_INTERVENTION, FirmBillingWorklistService::TYPE_MATERIAL], true)) {
            return $this->validationError('type doit valoir INTERVENTION ou MATERIAL.');
        }
        $statusParam = $request->query->get('status');
        $status = $statusParam !== null && $statusParam !== '' ? FirmBillingStatus::tryFrom($statusParam) : null;
        if ($statusParam !== null && $statusParam !== '' && $status === null) {
            return $this->validationError('status invalide (BILLABLE, NOT_BILLABLE, TO_REVIEW, INVOICED).');
        }

        return $this->json($this->worklist->build($from, $to, $firmIds, $type ?: null, $status));
    }

    /**
     * POST /api/firm-billing/worklist/export
     * { from, to, firmIds: int[], keys: string[], format: "pdf"|"xlsx" }
     * Exporte EXACTEMENT les lignes désignées par `keys` (aucune autre, sans doublon).
     */
    #[Route('/worklist/export', name: 'api_firm_billing_worklist_export', methods: ['POST'])]
    public function export(Request $request): Response
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            return $this->validationError('Corps JSON invalide.');
        }
        [$from, $to, $error] = $this->period($body['from'] ?? null, $body['to'] ?? null);
        if ($error !== null) {
            return $error;
        }
        [$firmIds, $error] = $this->firmIds($this->firmIdsInput($body['firmIds'] ?? []));
        if ($error !== null) {
            return $error;
        }
        $format = $body['format'] ?? null;
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            return $this->validationError('format doit valoir pdf ou xlsx.');
        }
        $keys = $body['keys'] ?? null;
        if (!is_array($keys) || $keys === [] || count($keys) > self::MAX_EXPORT_KEYS || array_filter($keys, static fn ($k) => !is_string($k) || $k === '') !== []) {
            return $this->validationError('keys doit être une liste non vide de lignes sélectionnées.');
        }

        $selection = $this->worklist->selectRows($from, $to, $firmIds, $keys);
        if ($selection['unknownKeys'] !== []) {
            return $this->json(['error' => [
                'status' => 422,
                'code' => 'EXPORT_SELECTION_INVALID',
                'message' => sprintf('%d ligne(s) sélectionnée(s) ne font plus partie de cette période ou de ces firmes : rechargez la page puis refaites la sélection.', count($selection['unknownKeys'])),
            ]], 422);
        }

        $firmNames = $this->firmNames($firmIds);
        $stamp = $from->format('Y-m');
        if ($format === 'xlsx') {
            return new Response($this->exporter->xlsx($selection['rows'], $from, $to, $firmNames), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => sprintf('attachment; filename="facturation-firmes-%s.xlsx"', $stamp),
            ]);
        }

        return new Response($this->exporter->pdf($selection['rows'], $from, $to, $firmNames), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="facturation-firmes-%s.pdf"', $stamp),
        ]);
    }

    /**
     * POST /api/firm-billing/calculations { missionIds: int[] } — calcule (ou recalcule)
     * chaque mission via le moteur financier existant ; résultat mission par mission.
     */
    #[Route('/calculations', name: 'api_firm_billing_calculations', methods: ['POST'])]
    public function calculate(Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $body = json_decode((string) $request->getContent(), true);
        $ids = is_array($body) ? ($body['missionIds'] ?? null) : null;
        if (!is_array($ids) || $ids === [] || count($ids) > FirmBillingRecalculationService::MAX_MISSIONS || array_filter($ids, static fn ($id) => !is_int($id) || $id <= 0) !== []) {
            return $this->validationError(sprintf('missionIds doit contenir de 1 à %d identifiants de mission.', FirmBillingRecalculationService::MAX_MISSIONS));
        }

        $results = $this->recalculation->run($ids, $actor);
        $counts = array_count_values(array_column($results, 'outcome'));

        return $this->json([
            'results' => $results,
            'calculated' => $counts['CALCULATED'] ?? 0,
            'failed' => $counts['FAILED'] ?? 0,
            'skipped' => ($counts['SKIPPED'] ?? 0) + ($counts['NOT_PROCESSED'] ?? 0),
        ]);
    }

    // ── Paramètres ──────────────────────────────────────────────────────────

    /** @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable, 2: ?JsonResponse} */
    private function period(mixed $from, mixed $to): array
    {
        $fromDay = $this->businessDay($from);
        $toDay = $this->businessDay($to);
        if ($fromDay === null || $toDay === null || $fromDay > $toDay) {
            return [null, null, $this->validationError('from et to (AAAA-MM-JJ, from ≤ to) sont requis.')];
        }
        if ($fromDay->diff($toDay)->days > 366) {
            return [null, null, $this->validationError('La période ne peut pas dépasser un an.')];
        }

        return [$fromDay, $toDay, null];
    }

    private function businessDay(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $day !== false && $day->format('Y-m-d') === $value ? $day : null;
    }

    /** Accepte firmIds[]=1&firmIds[]=2, un tableau JSON, ou "1,2". */
    private function firmIdsInput(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_string($raw)) {
            return explode(',', $raw);
        }

        return is_array($raw) ? array_values($raw) : [$raw];
    }

    /** @return array{0: int[], 1: ?JsonResponse} */
    private function firmIds(array $raw): array
    {
        $ids = [];
        foreach ($raw as $value) {
            if (!is_numeric($value) || (int) $value <= 0) {
                return [[], $this->validationError('firmIds doit contenir des identifiants de firme.')];
            }
            $ids[(int) $value] = (int) $value;
        }
        if ($ids === []) {
            return [[], null];
        }
        $found = $this->em->createQueryBuilder()
            ->select('f.id')->from(Firm::class, 'f')->where('f.id IN (:ids)')->setParameter('ids', array_values($ids))
            ->getQuery()->getSingleColumnResult();
        if (count($found) !== count($ids)) {
            return [[], $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Firme introuvable.']], 404)];
        }

        return [array_values($ids), null];
    }

    /** @param int[] $firmIds */
    private function firmNames(array $firmIds): array
    {
        if ($firmIds === []) {
            return [];
        }
        $names = $this->em->createQueryBuilder()
            ->select('f.name')->from(Firm::class, 'f')->where('f.id IN (:ids)')->setParameter('ids', $firmIds)->orderBy('f.name', 'ASC')
            ->getQuery()->getSingleColumnResult();

        return array_map('strval', $names);
    }

    private function validationError(string $message): JsonResponse
    {
        return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => $message]], 422);
    }
}
