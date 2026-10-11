<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\NotificationDispatch;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionCommunicationSource;
use App\Enum\MissionStatus;
use App\Enum\NotificationEmailPolicy;
use App\Enum\NotificationType;
use App\Enum\OutboundNotificationChannel;
use App\Enum\OutboundNotificationStatus;
use App\Enum\PlanningVersionStatus;
use App\Notification\DocumentStore;
use App\Notification\PlanningDocumentIdentity;
use App\Notification\EmailContent;
use App\Notification\MissionCommunicationSpec;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Manual "Renvoyer le planning par e-mail" action (D-090, anomalie fonctionnelle 1) —
 * a manager re-sending one person's CURRENTLY PUBLISHED plan on demand, independent of
 * any deploy/redeploy and any diff. Never fires for anyone else, never depends on
 * whether anything changed recently.
 *
 * Deliberately reuses the exact same PDF templates (`pdf/planning_instrumentist.html.twig`
 * / `pdf/planning_surgeon.html.twig`), email templates (`emails/planning_instrumentist.html.twig`
 * / `emails/planning_surgeon.html.twig`) and subject format
 * (`Planning du {from} au {to}` — D-058) as the initial-deploy email
 * (PlanningDeployPdfsMessageHandler) — never a second, divergent format for "the same
 * information, resent". The one deliberate content difference: no "missions disponibles"
 * section (that's specific to the deploy-time claim-pool nudge, not a plain resend) and no
 * global manager PDF attachment for surgeons (manager-only content, irrelevant to a resend).
 *
 * H5a — through NotificationDispatcher (PLANNING_RESENT_MANUAL): the email is MANDATORY (an
 * explicit one-off manager action never depends on the person's preferences), the in-app
 * item follows her preferences, the PDF is stored once (GeneratedDocument) and attached by
 * reference. Formerly an untraced SendBillingEmailMessage plus a NotificationEvent marked
 * "SENT" the moment the email was merely queued. The missions it presents become
 * communicated (mission_communication, source RESEND) only once a channel really reached
 * her — never if every channel failed.
 */
class PlanningResendService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PdfService $pdfService,
        private readonly NotificationDispatcher $dispatcher,
        private readonly DocumentStore $documents,
    ) {}

    /**
     * @return array{missionCount: int, email: string, status: string}
     * @throws \RuntimeException      the email could not be handed to the queue (its FAILED delivery is recorded)
     * @throws NotFoundHttpException   version or user not found, or user has no role-appropriate missions in it
     * @throws ConflictHttpException   the version is not the currently published (ACTIVE) one
     */
    public function resend(PlanningVersion $version, User $target, User $actor): array
    {
        // "Exclusivement le planning publié, jamais le brouillon" — enforced structurally,
        // not just by convention: a DRAFT/ARCHIVED version simply cannot be resent.
        if ($version->getStatus() !== PlanningVersionStatus::ACTIVE) {
            throw new ConflictHttpException('Seul le planning actuellement publié (version ACTIVE) peut être renvoyé.');
        }

        $roles = $target->getRoles();
        $isInstrumentist = in_array('ROLE_INSTRUMENTIST', $roles, true);
        $isSurgeon       = in_array('ROLE_SURGEON', $roles, true);

        if (!$isInstrumentist && !$isSurgeon) {
            throw new NotFoundHttpException('Cet utilisateur n\'a pas de planning instrumentiste ou chirurgien à renvoyer.');
        }

        if ($target->getEmail() === null || $target->getEmail() === '') {
            throw new NotFoundHttpException('Cet utilisateur n\'a pas d\'adresse email.');
        }

        $missions = $isInstrumentist
            ? $this->loadMissions($version, 'm.instrumentist', $target, [MissionStatus::ASSIGNED])
            : $this->loadMissions($version, 'm.surgeon', $target, [MissionStatus::OPEN, MissionStatus::ASSIGNED]);

        if (empty($missions)) {
            throw new NotFoundHttpException('Aucune mission publiée pour cet utilisateur sur cette version.');
        }

        $fromDate = $version->getPeriodStart();
        $toDate   = $version->getPeriodEnd();
        $subject  = sprintf('Planning du %s au %s', $fromDate->format('d/m/Y'), $toDate->format('d/m/Y'));
        $name     = self::displayName($target);

        if ($isInstrumentist) {
            $render = fn () => $this->pdfService->generateFromTemplate('pdf/planning_instrumentist.html.twig', [
                'instrumentist' => $target,
                'missions'      => $missions,
                'periodFrom'    => $fromDate,
                'periodTo'      => $toDate,
            ]);
            $htmlTemplate = 'emails/planning_instrumentist.html.twig';
            $context = [
                'instrumentist'        => $target,
                'periodFrom'           => $fromDate,
                'periodTo'             => $toDate,
                'missionCount'         => count($missions),
                'availableMissions'    => [],
                'availableMissionsUrl' => null,
            ];
        } else {
            $render = fn () => $this->pdfService->generateFromTemplate('pdf/planning_surgeon.html.twig', [
                'surgeon'    => $target,
                'missions'   => $missions,
                'periodFrom' => $fromDate,
                'periodTo'   => $toDate,
            ]);
            $covered = count(array_filter($missions, fn (Mission $m) => $m->getStatus() === MissionStatus::ASSIGNED));
            $htmlTemplate = 'emails/planning_surgeon.html.twig';
            $context = [
                'surgeon'        => $target,
                'periodFrom'     => $fromDate,
                'periodTo'       => $toDate,
                'totalCount'     => count($missions),
                'coveredCount'   => $covered,
                'uncoveredCount' => count($missions) - $covered,
            ];
        }

        $filename = sprintf(
            'planning-%s%s-%s-%s.pdf',
            $isSurgeon ? 'chirurgien-' : '',
            strtolower(str_replace(' ', '-', $name)),
            $fromDate->format('Y-m-d'),
            $toDate->format('Y-m-d'),
        );

        // H5b (D-132) — a resend sends the document ALREADY communicated when what it shows is
        // unchanged (same bytes, same sha256 as the publication / last recap) — never a fresh
        // rendering presented as the original; a planning that changed is a new document.
        $role = $isInstrumentist ? PlanningDocumentIdentity::ROLE_INSTRUMENTIST : PlanningDocumentIdentity::ROLE_SURGEON;
        $identity = PlanningDocumentIdentity::personal($role, $target, $fromDate, $toDate, $missions);
        $document = $this->documents->storeOnce($isInstrumentist ? 'PLANNING_INSTRUMENTIST' : 'PLANNING_SURGEON',
            $identity['sourceKey'], $identity['fingerprint'], $filename, $render);

        $dispatch = $this->dispatcher->dispatch(new NotificationIntent(
            recipient: $target,
            type: NotificationType::PLANNING_RESENT_MANUAL,
            inAppPayload: [
                'planningVersionId' => $version->getId(),
                'periodFrom'        => $fromDate->format('Y-m-d'),
                'periodTo'          => $toDate->format('Y-m-d'),
                'missionCount'      => count($missions),
                'actorId'           => $actor->getId(),
                'actorName'         => self::displayName($actor),
            ],
            email: new EmailContent($subject, $htmlTemplate, $context, attachments: [$document]),
            subjectType: 'PlanningVersion',
            subjectId: $version->getId(),
            emailPolicy: NotificationEmailPolicy::MANDATORY,
            communicates: new MissionCommunicationSpec(
                MissionCommunicationSource::RESEND,
                $isInstrumentist ? MissionCommunicationSpec::ROLE_INSTRUMENTIST : MissionCommunicationSpec::ROLE_SURGEON,
                array_values(array_map(static fn (Mission $m) => (int) $m->getId(), $missions)),
            ),
        ));

        $status = self::emailStatus($dispatch);
        if (!in_array($status, [OutboundNotificationStatus::QUEUED->value, OutboundNotificationStatus::SENT->value], true)) {
            // Controlled wording only — never a transport/stack detail in the API response.
            throw new \RuntimeException('L\'email n\'a pas pu être mis en file d\'envoi. Consultez l\'historique des envois.');
        }

        // "QUEUED": handed to the queue — the history shows SENT once the relay accepted it.
        return ['missionCount' => count($missions), 'email' => $target->getEmail(), 'status' => $status];
    }

    private static function emailStatus(NotificationDispatch $dispatch): ?string
    {
        foreach ($dispatch->getDeliveries() as $delivery) {
            if ($delivery->getChannel() === OutboundNotificationChannel::EMAIL) {
                return $delivery->getStatus()->value;
            }
        }

        return null;
    }

    /** @param MissionStatus[] $statuses @return Mission[] */
    private function loadMissions(PlanningVersion $version, string $field, User $user, array $statuses): array
    {
        return $this->em->createQuery(
            "SELECT m FROM App\Entity\Mission m
             WHERE m.planningVersion = :version AND {$field} = :user AND m.status IN (:statuses)
             ORDER BY m.startAt ASC"
        )
            ->setParameter('version', $version)
            ->setParameter('user', $user)
            ->setParameter('statuses', $statuses)
            ->getResult();
    }

    private static function displayName(User $u): string
    {
        $name = trim(($u->getFirstname() ?? '') . ' ' . ($u->getLastname() ?? ''));
        return $name !== '' ? $name : ($u->getEmail() ?? '');
    }
}
