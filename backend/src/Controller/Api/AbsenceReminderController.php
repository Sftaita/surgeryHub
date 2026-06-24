<?php

namespace App\Controller\Api;

use App\Entity\User;
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

        $data    = json_decode($request->getContent(), true) ?? [];
        $message = trim((string) ($data['message'] ?? '')) ?: self::DEFAULT_REQUEST_MESSAGE;
        $userIds = $this->extractUserIds($data);

        [$from, $to] = $this->reminderService->defaultPeriod();
        $people = $this->reminderService->findUsersWithoutAbsenceInPeriod($from, $to);
        if ($userIds !== null) {
            $people = array_values(array_filter($people, static fn (User $u) => in_array($u->getId(), $userIds, true)));
        }

        $sentCount = 0;
        foreach ($people as $person) {
            $this->notificationService->sendAbsenceRequestMissingEmailToUser($person, $message);
            $sentCount++;
        }
        $this->userAuditService->absencesRequestSent($actor, $sentCount);
        $this->em->flush();

        return $this->json([
            'sent'  => true,
            'count' => $sentCount,
        ]);
    }

    /**
     * Sends ONE individual email per selected person, to their own address, containing ALL of
     * their future absences (no 3-month cap) — never a single email to a fixed recipient.
     */
    #[Route('/confirm-encoded', name: 'api_absences_confirm_encoded', methods: ['POST'])]
    public function confirmEncoded(Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(PlanningVoter::PLANNING_MANAGE);

        $data    = json_decode($request->getContent(), true) ?? [];
        $message = trim((string) ($data['message'] ?? '')) ?: self::DEFAULT_CONFIRM_MESSAGE;
        $userIds = $this->extractUserIds($data);

        [$from] = $this->reminderService->defaultPeriod();
        $groups = $this->reminderService->findAllFutureEncodedAbsencesGrouped($from);
        if ($userIds !== null) {
            $groups = array_values(array_filter($groups, static fn (array $g) => in_array($g['user']->getId(), $userIds, true)));
        }

        $sentCount = 0;
        foreach ($groups as $group) {
            $this->notificationService->sendAbsenceConfirmEncodedEmailToUser($group['user'], $group['absences'], $message);
            $sentCount++;
        }
        $this->userAuditService->absencesConfirmationSent($actor, $sentCount);
        $this->em->flush();

        return $this->json([
            'sent'  => true,
            'count' => $sentCount,
        ]);
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
