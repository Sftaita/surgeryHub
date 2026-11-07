<?php

namespace App\Controller\Api;

use App\Activity\CorrelationContext;
use App\Entity\NotificationDispatch;
use App\Entity\User;
use App\Enum\NotificationEmailPolicy;
use App\Enum\NotificationType;
use App\Enum\OutboundNotificationChannel;
use App\Enum\OutboundNotificationStatus;
use App\Notification\EmailContent;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Security\Voter\PlanningVoter;
use App\Service\AbsenceReminderService;
use App\Service\NotificationService;
use App\Service\UserAuditService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Manager/admin actions to chase up missing absence declarations and to confirm what's
 * already encoded.
 *
 * Both actions now send ONE INDIVIDUAL email PER selected person, to their OWN address —
 * never to a fixed mailbox. `boost.conge@gmail.com` only ever appears as plain text inside
 * the request-missing message (asking the recipient to reply there with their dates), never
 * as an actual email recipient. See D-051 (second amendment).
 *
 * - `request-missing`: selection = active instrumentists/surgeons with no Absence overlapping
 *   today → +3 months.
 * - `confirm-encoded`: selection = active instrumentists/surgeons with at least one Absence
 *   where dateEnd >= today (ALL future absences are included in the email, no 3-month cap).
 *
 * H5a — every email goes through NotificationDispatcher (ABSENCE_REQUEST_MISSING /
 * ABSENCE_ENCODED_RECAP): MANDATORY email (an explicit manager request, never filtered by the
 * person's preferences), no in-app, no push, one NotificationDispatch + OutboundNotification
 * per person with an honest status. Idempotent per reminder batch: the episode is
 * `absence_reminder:{kind}:{batchId}` — the client may send a `batchId` (a retried request
 * then never emails anyone twice); otherwise the request's correlation id is the batch.
 */
#[Route('/api/planning/absences')]
class AbsenceReminderController extends AbstractController
{
    /**
     * Deliberately no "Bonjour," here — the greeting is rendered separately by the template,
     * personalized per recipient (Dr {lastname} for surgeons, {firstname} for instrumentists),
     * never duplicated with whatever the manager types in this editable body.
     */
    private const DEFAULT_REQUEST_MESSAGE = <<<'TXT'
        Nous n'avons actuellement aucun congé ou indisponibilité encodé pour vous pour les trois prochains mois.

        Pourriez-vous nous transmettre vos éventuels congés ou indisponibilités prévus en répondant à boost.conge@gmail.com ?

        À terme, cette demande se fera directement via l'application SurgicalHub. Vous serez tenu(e) au courant dès que cette fonctionnalité sera disponible.

        Merci d'avance.
        TXT;

    private const DEFAULT_CONFIRM_MESSAGE = <<<'TXT'
        Voici le récapitulatif des jours de congé ou d'indisponibilité actuellement encodés pour vous.

        À terme, cette confirmation se fera directement via l'application SurgicalHub. Vous serez tenu(e) au courant dès que cette fonctionnalité sera disponible.

        Merci.
        TXT;

    public function __construct(
        private readonly AbsenceReminderService $reminderService,
        private readonly NotificationService $notificationService,
        private readonly UserAuditService $userAuditService,
        private readonly EntityManagerInterface $em,
        private readonly NotificationDispatcher $dispatcher,
        private readonly CorrelationContext $correlation,
    ) {}

    #[Route('/missing-preview', name: 'api_absences_missing_preview', methods: ['GET'])]
    public function missingPreview(): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        [$from, $to] = $this->reminderService->defaultPeriod();
        $people = $this->reminderService->findUsersWithoutAbsenceInPeriod($from, $to);

        return $this->json([
            'count'  => count($people),
            'people' => array_map($this->serializePerson(...), $people),
        ]);
    }

    #[Route('/encoded-preview', name: 'api_absences_encoded_preview', methods: ['GET'])]
    public function encodedPreview(): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        [$from] = $this->reminderService->defaultPeriod();
        $groups = $this->reminderService->findAllFutureEncodedAbsencesGrouped($from);

        return $this->json([
            'count'  => count($groups),
            'groups' => array_map($this->serializeGroup(...), $groups),
        ]);
    }

    /**
     * Sends ONE individual email per selected person, to their own address. Never to
     * boost.conge@gmail.com — that address only appears as plain text inside the message,
     * asking the recipient to reply there with their dates.
     */
    #[Route('/request-missing', name: 'api_absences_request_missing', methods: ['POST'])]
    public function requestMissing(Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $data = $this->decodeJsonBody($request);
        if ($data === false) {
            return $this->json(['error' => 'Corps de requête JSON invalide.'], 400);
        }
        $message = trim((string) ($data['message'] ?? '')) ?: self::DEFAULT_REQUEST_MESSAGE;
        $userIds = $this->extractUserIds($data);

        [$from, $to] = $this->reminderService->defaultPeriod();
        $people = $this->reminderService->findUsersWithoutAbsenceInPeriod($from, $to);
        if ($userIds !== null) {
            $people = array_values(array_filter($people, static fn (User $u) => in_array($u->getId(), $userIds, true)));
        }

        $batchId = $this->batchId($data);
        $result  = $this->send(NotificationType::ABSENCE_REQUEST_MISSING, $batchId, array_map(
            fn (User $person) => [$person, $this->notificationService->absenceRequestMissingEmail($person, $message)],
            $people,
        ));
        $this->userAuditService->absencesRequestSent($actor, $result['count']);
        $this->em->flush();

        return $this->json(['sent' => true, 'batchId' => $batchId] + $result);
    }

    /**
     * Sends ONE individual email per selected person, to their own address, containing ALL of
     * their future absences (no 3-month cap) — never a single email to a fixed recipient.
     */
    #[Route('/confirm-encoded', name: 'api_absences_confirm_encoded', methods: ['POST'])]
    public function confirmEncoded(Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $data = $this->decodeJsonBody($request);
        if ($data === false) {
            return $this->json(['error' => 'Corps de requête JSON invalide.'], 400);
        }
        $message = trim((string) ($data['message'] ?? '')) ?: self::DEFAULT_CONFIRM_MESSAGE;
        $userIds = $this->extractUserIds($data);

        [$from] = $this->reminderService->defaultPeriod();
        $groups = $this->reminderService->findAllFutureEncodedAbsencesGrouped($from);
        if ($userIds !== null) {
            $groups = array_values(array_filter($groups, static fn (array $g) => in_array($g['user']->getId(), $userIds, true)));
        }

        $batchId = $this->batchId($data);
        $result  = $this->send(NotificationType::ABSENCE_ENCODED_RECAP, $batchId, array_map(
            fn (array $group) => [$group['user'], $this->notificationService->absenceEncodedRecapEmail($group['user'], $group['absences'], $message)],
            $groups,
        ));
        $this->userAuditService->absencesConfirmationSent($actor, $result['count']);
        $this->em->flush();

        return $this->json(['sent' => true, 'batchId' => $batchId] + $result);
    }

    /**
     * One dispatch per person (never a grouped or collective email). `count` = emails really
     * handed to the queue; `notSent` = people whose email could not be (no address, failure).
     *
     * @param list<array{0: User, 1: EmailContent}> $emails
     * @return array{count: int, notSent: list<array{id: ?int, name: string, reason: string}>}
     */
    private function send(NotificationType $type, string $batchId, array $emails): array
    {
        $count = 0;
        $notSent = [];
        foreach ($emails as [$person, $email]) {
            $status = null;
            try {
                $status = self::emailOutcome($this->dispatcher->dispatch(new NotificationIntent(
                    recipient: $person,
                    type: $type,
                    inAppPayload: [],
                    email: $email,
                    episodeRef: sprintf('absence_reminder:%s:%s', $type->value, $batchId),
                    subjectType: 'User',
                    subjectId: $person->getId(),
                    emailPolicy: NotificationEmailPolicy::MANDATORY,
                    inApp: false,
                )));
            } catch (\Throwable) {
                $status = 'FAILED';
            }

            if (in_array($status, [OutboundNotificationStatus::QUEUED->value, OutboundNotificationStatus::SENT->value], true)) {
                $count++;
            } else {
                $name = trim(($person->getFirstname() ?? '') . ' ' . ($person->getLastname() ?? ''));
                $notSent[] = ['id' => $person->getId(), 'name' => $name ?: (string) $person->getEmail(), 'reason' => $status ?? 'FAILED'];
            }
        }

        return ['count' => $count, 'notSent' => $notSent];
    }

    /** "QUEUED"/"SENT"/"FAILED", or the skip reason ("NO_EMAIL_ADDRESS") of the email delivery. */
    private static function emailOutcome(NotificationDispatch $dispatch): ?string
    {
        foreach ($dispatch->getDeliveries() as $delivery) {
            if ($delivery->getChannel() === OutboundNotificationChannel::EMAIL) {
                return $delivery->getStatus() === OutboundNotificationStatus::SKIPPED
                    ? ($delivery->getSkipReason()?->value ?? 'SKIPPED')
                    : $delivery->getStatus()->value;
            }
        }

        return null;
    }

    /** Client-provided batch id (retry-safe), or this request's correlation id. */
    private function batchId(array $data): string
    {
        $candidate = $data['batchId'] ?? null;
        if (is_string($candidate) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $candidate) === 1) {
            return $candidate;
        }

        return $this->correlation->correlationId();
    }

    /**
     * Returns the decoded body, or `false` if the body is present but not valid JSON. An
     * empty body is treated as `[]` (defaults apply) — only genuinely malformed JSON must be
     * rejected, never silently treated as "no filter, send to everyone".
     *
     * @return array<string, mixed>|false
     */
    private function decodeJsonBody(Request $request): array|false
    {
        $raw = $request->getContent();
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return false;
        }

        return $data;
    }

    /** @return list<int>|null null means "no filter — everyone in scope". */
    private function extractUserIds(array $data): ?array
    {
        if (!array_key_exists('userIds', $data) || !is_array($data['userIds'])) {
            return null;
        }
        return array_map('intval', $data['userIds']);
    }

    private function serializePerson(User $user): array
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));

        return [
            'id'    => $user->getId(),
            'name'  => $name ?: $user->getEmail(),
            'email' => $user->getEmail(),
            'role'  => in_array('ROLE_INSTRUMENTIST', $user->getRoles(), true) ? 'INSTRUMENTIST' : 'SURGEON',
        ];
    }

    /** @param array{user: User, absences: \App\Entity\Absence[]} $group */
    private function serializeGroup(array $group): array
    {
        $user = $group['user'];
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));

        return [
            'user' => [
                'id'    => $user->getId(),
                'name'  => $name ?: $user->getEmail(),
                'email' => $user->getEmail(),
                'role'  => in_array('ROLE_INSTRUMENTIST', $user->getRoles(), true) ? 'INSTRUMENTIST' : 'SURGEON',
            ],
            'absences' => array_map(static fn ($a) => [
                'dateStart' => $a->getDateStart()->format('Y-m-d'),
                'dateEnd'   => $a->getDateEnd()->format('Y-m-d'),
                'reason'    => $a->getReason(),
            ], $group['absences']),
        ];
    }
}
