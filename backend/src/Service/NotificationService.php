<?php

namespace App\Service;

use App\Entity\FirmInvoice;
use App\Entity\InstrumentistStatement;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\PublicationChannel;
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

    public function sendInstrumentistInvitation(User $user): void
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
    }

    public function sendSurgeonInvitation(User $user): void
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