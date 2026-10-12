<?php

namespace App\Controller\Api;

use App\Enum\AuditEventType;
use App\Security\Voter\AuditJournalVoter;
use App\Service\AuditJournalService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * D-147 — journal d'activité manager : tous les AuditEvent (missions et événements
 * globaux), filtrables. Lecture seule.
 */
#[Route('/api/manager/audit-events')]
final class ManagerAuditJournalController extends AbstractController
{
    public function __construct(private readonly AuditJournalService $journal)
    {
    }

    /**
     * GET /api/manager/audit-events
     *   ?category=MISSION|SURGEON_REQUEST|PLANNING|ENCODING|FINANCE|CATALOGUE
     *   ?eventType=A,B        (AuditEventType values)
     *   ?actorId=42  ?missionId=7
     *   ?from=ISO8601 (inclus)  ?to=ISO8601 (exclu)
     *   ?q=texte               (auteur, chirurgien, instrumentiste, site, #id mission)
     *   ?page=1  ?limit=50     (max 100)
     */
    #[Route('', name: 'api_manager_audit_events_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(AuditJournalVoter::VIEW);

        $query = $request->query;

        $eventTypes = [];
        $rawTypes = trim($query->getString('eventType'));
        if ($rawTypes !== '') {
            foreach (explode(',', $rawTypes) as $raw) {
                $eventTypes[] = AuditEventType::tryFrom(trim($raw))
                    ?? throw new UnprocessableEntityHttpException(sprintf('Invalid eventType "%s"', trim($raw)));
            }
        }

        $category = trim($query->getString('category'));

        return $this->json($this->journal->search([
            'category' => $category !== '' ? $category : null,
            'eventTypes' => $eventTypes,
            'actorId' => $query->getInt('actorId') ?: null,
            'missionId' => $query->getInt('missionId') ?: null,
            'from' => self::parseDate($query->getString('from'), 'from'),
            'to' => self::parseDate($query->getString('to'), 'to'),
            'q' => $query->getString('q'),
            'page' => $query->getInt('page', 1),
            'limit' => $query->getInt('limit', 50),
        ]));
    }

    private static function parseDate(string $raw, string $field): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            throw new UnprocessableEntityHttpException(sprintf('Invalid %s datetime format (ISO 8601 expected)', $field));
        }
    }
}
