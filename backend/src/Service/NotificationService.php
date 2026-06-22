<?php

namespace App\Service;

use App\Entity\FirmInvoice;
use App\Entity\InstrumentistStatement;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\PublicationChannel;
use App\Message\PlanningAlertRaisedMessage;
use App\Message\SendBillingEmailMessage;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

class NotificationService
{
    private const INSTRUMENTIST_INVITATION_PATH = '/complete-account';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly EmailService $emailService,
        private readonly MessageBusInterface $bus,
        #[Autowire('%env(string:FRONTEND_URL)%')]
        private readonly string $frontendUrl,
        #[Autowire('%env(string:MAILER_FROM_ADDRESS)%')]
        private readonly string $fromAddress,
        #[Autowire('%env(string:MAILER_FROM_NAME)%')]
        private readonly string $fromName,
    ) {
    }

    /**
     * Déclaration -> managers/admins globaux.
     */
    public function missionDeclaredNotifyManagersAdmins(Mission $mission): void
    {
        $recipients = $this->userRepository->findManagersAndAdmins(true);

        foreach ($recipients as $user) {
            $this->createInApp($user, $mission, 'MISSION_DECLARED');
        }
    }

    /**
     * Approbation -> instrumentiste.
     */
    public function missionDeclaredApprovedNotifyInstrumentist(Mission $mission): void
    {
        $instrumentist = $mission->getInstrumentist();
        if (!$instrumentist instanceof User) {
            return;
        }

        $this->createInApp($instrumentist, $mission, 'MISSION_DECLARED_APPROVED');
    }

    /**
     * Rejet -> instrumentiste.
     */
    public function missionDeclaredRejectedNotifyInstrumentist(Mission $mission): void
    {
        $instrumentist = $mission->getInstrumentist();
        if (!$instrumentist instanceof User) {
            return;
        }

        $this->createInApp($instrumentist, $mission, 'MISSION_DECLARED_REJECTED');
    }

    /**
     * Méthode générique d'envoi d'invitation, quel que soit le rôle.
     * Met à jour invitationLastSentAt sur l'entité (flush géré par l'appelant).
     */
    public function sendUserInvitation(User $user): void
    {
        $token = $user->getInvitationToken();
        if ($token === null || $token === '') {
            throw new \LogicException('Invitation token is missing.');
        }

        $invitationUrl = $this->buildFrontendUrl(self::INSTRUMENTIST_INVITATION_PATH, [
            'token' => $token,
        ]);

        $this->emailService->sendTemplatedEmail(
            to: (string) $user->getEmail(),
            subject: 'Complete your SurgicalHub account',
            htmlTemplate: 'emails/instrumentist_invitation.html.twig',
            context: [
                'displayName' => $this->resolveDisplayName($user),
                'invitationUrl' => $invitationUrl,
                'expiresAt' => $user->getInvitationExpiresAt(),
            ],
            textTemplate: 'emails/instrumentist_invitation.txt.twig',
        );

        $user->setInvitationLastSentAt(new \DateTimeImmutable());
    }

    public function sendInstrumentistInvitation(User $user): void
    {
        $this->sendUserInvitation($user);
        $this->em->flush();
    }

    public function sendSurgeonInvitation(User $user): void
    {
        $this->sendUserInvitation($user);
        $this->em->flush();
    }

    public function sendFirmInvoiceEmail(FirmInvoice $invoice, string $emailTo, array $emailCc, string $pdfBinary): void
    {
        $filename = sprintf('facture-%s-%s.pdf',
            strtolower(str_replace(' ', '-', $invoice->getFirm()->getName())),
            $invoice->getNumber() ?? $invoice->getId()
        );

        $this->bus->dispatch(new SendBillingEmailMessage(
            to: $emailTo,
            cc: $emailCc,
            subject: sprintf('Facture %s — %s', $invoice->getNumber() ?? $invoice->getId(), $invoice->getFirm()->getName()),
            fromAddress: $this->fromAddress,
            fromName: $this->fromName,
            htmlTemplate: 'emails/firm_invoice_sent.html.twig',
            context: ['invoice' => $invoice],
            attachmentBase64: base64_encode($pdfBinary),
            attachmentFilename: $filename,
        ));
    }

    public function sendStatementEmail(InstrumentistStatement $statement, string $emailTo, string $pdfBinary): void
    {
        $filename = sprintf('decompte-%02d-%d.pdf', $statement->getPeriodMonth(), $statement->getPeriodYear());

        $this->bus->dispatch(new SendBillingEmailMessage(
            to: $emailTo,
            cc: [],
            subject: sprintf('Décompte %02d/%d — %s', $statement->getPeriodMonth(), $statement->getPeriodYear(), $statement->getInstrumentistNameSnapshot() ?? ''),
            fromAddress: $this->fromAddress,
            fromName: $this->fromName,
            htmlTemplate: 'emails/instrumentist_statement_sent.html.twig',
            context: ['statement' => $statement],
            attachmentBase64: base64_encode($pdfBinary),
            attachmentFilename: $filename,
        ));
    }

    // ── Planning notifications ────────────────────────────────────────────────

    /**
     * Notify an instrumentist that a mission has been individually assigned to them at deploy time.
     */
    public function planningMissionAssignedNotifyInstrumentist(Mission $mission): void
    {
        $instrumentist = $mission->getInstrumentist();
        if (!$instrumentist instanceof User) {
            return;
        }

        $this->createInAppPlanning(
            $instrumentist,
            'PLANNING_MISSION_ASSIGNED',
            [
                'missionId' => $mission->getId(),
                'siteId'    => $mission->getSite()?->getId(),
                'siteName'  => $mission->getSite()?->getName(),
                'startAt'   => $mission->getStartAt()?->format(\DateTimeInterface::ATOM),
            ]
        );
    }

    /**
     * Notify all active instrumentists of a site that new OPEN missions are available (pool).
     * Sends one notification per instrumentist (not one per mission).
     * Message template: "X nouvelles missions publiées à [site] entre [from] et [to]".
     *
     * @param User[] $siteInstrumentists Active instrumentists of the site
     */
    public function planningNewOpenMissionsNotifySite(
        array $siteInstrumentists,
        int $missionCount,
        string $siteName,
        string $periodFrom,
        string $periodTo,
    ): void {
        foreach ($siteInstrumentists as $instrumentist) {
            $this->createInAppPlanning(
                $instrumentist,
                'PLANNING_OPEN_MISSIONS_AVAILABLE',
                [
                    'siteName'     => $siteName,
                    'missionCount' => $missionCount,
                    'periodFrom'   => $periodFrom,
                    'periodTo'     => $periodTo,
                ]
            );
        }
    }

    /**
     * Notify the manager that a planning version was deployed successfully.
     */
    public function planningDeployedNotifyManager(User $manager, int $missionCount, string $from, string $to): void
    {
        $this->createInAppPlanning(
            $manager,
            'PLANNING_DEPLOYED',
            [
                'missionCount' => $missionCount,
                'from'         => $from,
                'to'           => $to,
            ]
        );
    }

    /**
     * In-app row for a PlanningAlert (Batch 7). Channel gating (whether to also email/push)
     * is the caller's responsibility (PlanningAlertRaisedMessageHandler) via
     * NotificationPreferenceResolver — this method only ever writes the IN_APP row.
     * Payload is deliberately generic: mission/site/date/type only, no patient data.
     */
    public function planningAlertRaisedNotifyInApp(User $recipient, PlanningAlertRaisedMessage $message): void
    {
        $this->createInAppPlanning(
            $recipient,
            'PLANNING_ALERT_RAISED',
            [
                'alertId'         => $message->alertId,
                'alertType'       => $message->alertType,
                'missionId'       => $message->missionId,
                'siteId'          => $message->siteId,
                'siteName'        => $message->siteName,
                'missionDate'     => $message->missionDate,
            ],
        );
    }

    /**
     * Notify the old and new instrumentist after a manager reassigns a mission from a
     * PlanningAlert resolution action. Either side may be null/absent: a mission that had
     * no instrumentist yet has no "old" one; this is still called with whichever exists.
     */
    public function planningAlertReassignedNotify(Mission $mission, ?User $oldInstrumentist, User $newInstrumentist): void
    {
        if ($oldInstrumentist !== null && $oldInstrumentist->getId() !== $newInstrumentist->getId()) {
            $this->createInAppPlanning(
                $oldInstrumentist,
                'PLANNING_ALERT_REASSIGNED_AWAY',
                [
                    'missionId' => $mission->getId(),
                    'siteId'    => $mission->getSite()?->getId(),
                    'siteName'  => $mission->getSite()?->getName(),
                    'missionDate' => $mission->getStartAt()->format('Y-m-d'),
                ],
            );
        }

        $this->createInAppPlanning(
            $newInstrumentist,
            'PLANNING_ALERT_REASSIGNED_TO',
            [
                'missionId' => $mission->getId(),
                'siteId'    => $mission->getSite()?->getId(),
                'siteName'  => $mission->getSite()?->getName(),
                'missionDate' => $mission->getStartAt()->format('Y-m-d'),
            ],
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function createInApp(User $user, Mission $mission, string $eventType): void
    {
        $evt = new NotificationEvent();
        $evt
            ->setUser($user)
            ->setMission($mission)
            ->setEventType($eventType)
            ->setChannel(PublicationChannel::IN_APP)
            ->setPayload([
                'missionId' => $mission->getId(),
                'siteId' => $mission->getSite()?->getId(),
                'status' => $mission->getStatus()->value,
            ])
            ->setSentAt(new \DateTimeImmutable());

        $this->em->persist($evt);
        // flush géré par MissionService
    }

    private function createInAppPlanning(User $user, string $eventType, array $payload): void
    {
        $evt = new NotificationEvent();
        $evt
            ->setUser($user)
            ->setEventType($eventType)
            ->setChannel(PublicationChannel::IN_APP)
            ->setPayload($payload)
            ->setSentAt(new \DateTimeImmutable());

        $this->em->persist($evt);
    }

    private function buildFrontendUrl(string $path, array $query = []): string
    {
        $base = rtrim($this->frontendUrl, '/');
        $normalizedPath = '/' . ltrim($path, '/');
        $queryString = http_build_query($query);

        if ($queryString === '') {
            return $base . $normalizedPath;
        }

        return $base . $normalizedPath . '?' . $queryString;
    }

    private function resolveDisplayName(User $user): string
    {
        $firstname = $user->getFirstname();
        $lastname = $user->getLastname();

        $name = trim((string) ($firstname ?? '') . ' ' . (string) ($lastname ?? ''));

        return $name !== '' ? $name : (string) $user->getEmail();
    }
}