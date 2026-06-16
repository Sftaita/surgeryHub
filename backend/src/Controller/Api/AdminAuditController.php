<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\UserAuditEvent;
use App\Enum\UserAuditEventType;
use App\Repository\UserAuditEventRepository;
use App\Security\Voter\UserAdministrationVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/audit')]
final class AdminAuditController extends AbstractController
{
    use AdminResponseTrait;

    public function __construct(
        private readonly UserAuditEventRepository $auditEvents,
    ) {}

    /**
     * GET /api/admin/audit
     *
     * Filtres supportés :
     *   ?from=ISO8601        (ex: 2026-06-01T00:00:00Z)
     *   ?to=ISO8601
     *   ?targetUserId=42
     *   ?eventType=USER_CREATED   (voir UserAuditEventType cases)
     *   ?limit=200            (max 500)
     *   ?offset=0
     */
    #[Route('', name: 'api_admin_audit_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(UserAdministrationVoter::LIST);

        $from           = $this->parseDate($request->query->get('from'));
        $to             = $this->parseDate($request->query->get('to'));
        $targetUserId   = $request->query->getInt('targetUserId', 0) ?: null;
        $eventTypeParam = $request->query->getString('eventType', '');
        $eventType      = $eventTypeParam !== '' ? $this->parseEventType($eventTypeParam) : null;
        $limit          = min((int) $request->query->get('limit', 200), 500);
        $offset         = max((int) $request->query->get('offset', 0), 0);

        $events = $this->auditEvents->findForAdminAuditPage(
            from:         $from,
            to:           $to,
            targetUserId: $targetUserId,
            eventType:    $eventType,
            limit:        $limit,
            offset:       $offset,
        );

        return $this->json([
            'items'  => array_map([$this, 'toPayload'], $events),
            'total'  => count($events),
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    private function toPayload(UserAuditEvent $e): array
    {
        $actor      = $e->getActor();
        $targetUser = $e->getTargetUser();

        return [
            'id'        => $e->getId(),
            'eventType' => $e->getEventType()?->value ?? '',
            'description' => $e->getDescription(),
            'payload'   => $e->getPayload(),
            'createdAt' => $e->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'actor'     => $actor !== null ? [
                'id'          => $actor->getId(),
                'email'       => $actor->getEmail(),
                'displayName' => $this->buildDisplayName($actor),
            ] : null,
            'targetUser' => $targetUser !== null ? [
                'id'          => $targetUser->getId(),
                'email'       => $targetUser->getEmail(),
                'displayName' => $this->buildDisplayName($targetUser),
            ] : null,
        ];
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $value)
           ?: \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        if ($dt === false) {
            throw new BadRequestHttpException(sprintf('Invalid date format: "%s". Use ISO 8601 (e.g. 2026-06-01T00:00:00+00:00).', $value));
        }
        return $dt;
    }

    private function parseEventType(string $value): UserAuditEventType
    {
        $case = UserAuditEventType::tryFrom($value);
        if ($case === null) {
            $valid = implode(', ', array_column(UserAuditEventType::cases(), 'value'));
            throw new BadRequestHttpException(sprintf('Unknown eventType "%s". Valid values: %s', $value, $valid));
        }
        return $case;
    }

}
