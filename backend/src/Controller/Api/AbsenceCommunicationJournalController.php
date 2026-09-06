<?php

namespace App\Controller\Api;

use App\Entity\SurgeonAbsenceCommunication;
use App\Entity\SurgeonAbsenceCommunicationDelivery;
use App\Enum\AbsenceCommunicationStatus;
use App\Enum\AbsenceCommunicationType;
use App\Entity\User;
use App\Repository\SurgeonAbsenceCommunicationRepository;
use App\Security\Voter\PlanningVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Communication des absences chirurgiens — Lot C (D-114), journal manager (§15-§23 de la
 * demande). Lecture seule, réservé MANAGER/ADMIN (`PlanningVoter::PLANNING_MANAGE`) — aucune
 * donnée patient/tarif/clinique n'est jamais présente dans ce journal (§21), uniquement de
 * la communication organisationnelle.
 */
#[Route('/api/planning/absence-communications')]
class AbsenceCommunicationJournalController extends AbstractController
{
    public function __construct(
        private readonly SurgeonAbsenceCommunicationRepository $communications,
    ) {
    }

    /**
     * Filtres : siteId, surgeonId, type (ROOM_RELEASE|BLOCK_MANAGEMENT_ABSENCE|
     * BLOCK_MANAGEMENT_MODIFICATION|BLOCK_MANAGEMENT_CANCELLATION), status (statut global
     * calculé — voir SurgeonAbsenceCommunicationRepository::computeGlobalStatus()),
     * periodFrom/periodTo (chevauchement avec la période de congé snapshotée), page, limit.
     */
    #[Route('', name: 'api_absence_communications_journal_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $siteId = $request->query->getInt('siteId', 0) ?: null;
        $surgeonId = $request->query->getInt('surgeonId', 0) ?: null;
        $typeParam = $request->query->getString('type', '');
        $type = $typeParam !== '' ? $this->parseType($typeParam) : null;
        $statusParam = $request->query->getString('status', '');
        $status = $statusParam !== '' ? $this->parseStatus($statusParam) : null;
        $periodFrom = $this->parseDate($request->query->get('periodFrom'));
        $periodTo = $this->parseDate($request->query->get('periodTo'));
        $page = max($request->query->getInt('page', 1), 1);
        $limit = min(max($request->query->getInt('limit', 25), 1), 100);

        $result = $this->communications->findForManager(
            siteId: $siteId,
            surgeonId: $surgeonId,
            type: $type,
            globalStatus: $status,
            periodFrom: $periodFrom,
            periodTo: $periodTo,
            page: $page,
            limit: $limit,
        );

        return $this->json([
            'items' => array_map([$this, 'toListPayload'], $result['items']),
            'page' => $page,
            'limit' => $limit,
            'total' => $result['total'],
        ]);
    }

    #[Route('/{id}', name: 'api_absence_communications_journal_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $communication = $this->communications->find($id);
        if (!$communication instanceof SurgeonAbsenceCommunication) {
            throw $this->createNotFoundException();
        }

        return $this->json($this->toDetailPayload($communication));
    }

    /** @return array<string, mixed> */
    private function toListPayload(SurgeonAbsenceCommunication $c): array
    {
        $deliveries = $c->getDeliveries();
        $counts = ['sent' => 0, 'failed' => 0, 'cancelled' => 0, 'scheduled' => 0];
        foreach ($deliveries as $d) {
            /** @var SurgeonAbsenceCommunicationDelivery $d */
            $counts[match ($d->getStatus()) {
                AbsenceCommunicationStatus::SENT => 'sent',
                AbsenceCommunicationStatus::FAILED => 'failed',
                AbsenceCommunicationStatus::CANCELLED => 'cancelled',
                AbsenceCommunicationStatus::SCHEDULED => 'scheduled',
            }]++;
        }

        // Absence.createdAt (Lot C, dispo pour référence terrain) — jamais l'origine du
        // filtre "période", qui reste basé sur les snapshots de cette ligne (§20 : ne jamais
        // dépendre de l'existence de l'Absence).
        return [
            'id' => $c->getId(),
            'type' => $c->getType()->value,
            'revisionNumber' => $c->getRevisionNumber(),
            'surgeon' => $c->getSurgeon() !== null ? [
                'id' => $c->getSurgeon()->getId(),
                'name' => self::displayName($c->getSurgeon()),
            ] : null,
            'site' => $c->getSite() !== null ? [
                'id' => $c->getSite()->getId(),
                'name' => $c->getSite()->getName(),
            ] : null,
            'absenceId' => $c->getAbsence()?->getId(),
            'absenceDateStart' => $c->getAbsenceDateStartSnapshot()->format('Y-m-d'),
            'absenceDateEnd' => $c->getAbsenceDateEndSnapshot()->format('Y-m-d'),
            'createdAt' => $c->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'globalStatus' => SurgeonAbsenceCommunicationRepository::computeGlobalStatus($c)->value,
            'deliveryCount' => $deliveries->count(),
            'sentCount' => $counts['sent'],
            'failedCount' => $counts['failed'],
            'cancelledCount' => $counts['cancelled'],
            'scheduledCount' => $counts['scheduled'],
        ];
    }

    /** @return array<string, mixed> */
    private function toDetailPayload(SurgeonAbsenceCommunication $c): array
    {
        $isBlockManagement = $c->getType() !== AbsenceCommunicationType::ROOM_RELEASE;

        return array_merge($this->toListPayload($c), [
            'subject' => $c->getSubjectSnapshot(),
            'body' => $c->getBodySnapshot(),
            'occurrences' => $c->getOccurrencesSnapshot(),
            'replyTo' => $isBlockManagement ? $c->getSurgeon()?->getEmail() : null,
            'deliveries' => array_map([$this, 'toDeliveryPayload'], $c->getDeliveries()->toArray()),
        ]);
    }

    /** @return array<string, mixed> */
    private function toDeliveryPayload(SurgeonAbsenceCommunicationDelivery $d): array
    {
        return [
            'id' => $d->getId(),
            'to' => $d->getRecipientEmailSnapshot(),
            'cc' => $d->getRecipientCcSnapshot(),
            'status' => $d->getStatus()->value,
            'attemptCount' => $d->getAttemptCount(),
            'scheduledAt' => $d->getScheduledAt()?->format(\DateTimeInterface::ATOM),
            'sentAt' => $d->getSentAt()?->format(\DateTimeInterface::ATOM),
            'cancelledAt' => $d->getCancelledAt()?->format(\DateTimeInterface::ATOM),
            'lastError' => $d->getLastError(),
        ];
    }

    private static function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($dt === false) {
            throw new BadRequestHttpException(sprintf('Format de date invalide : "%s". Utiliser Y-m-d.', $value));
        }

        return $dt;
    }

    private function parseType(string $value): AbsenceCommunicationType
    {
        $case = AbsenceCommunicationType::tryFrom($value);
        if ($case === null) {
            $valid = implode(', ', array_column(AbsenceCommunicationType::cases(), 'value'));
            throw new BadRequestHttpException(sprintf('Type inconnu "%s". Valeurs valides : %s', $value, $valid));
        }

        return $case;
    }

    private function parseStatus(string $value): AbsenceCommunicationStatus
    {
        $case = AbsenceCommunicationStatus::tryFrom($value);
        if ($case === null) {
            $valid = implode(', ', array_column(AbsenceCommunicationStatus::cases(), 'value'));
            throw new BadRequestHttpException(sprintf('Statut inconnu "%s". Valeurs valides : %s', $value, $valid));
        }

        return $case;
    }
}
