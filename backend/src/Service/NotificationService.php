<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\PublicationChannel;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class NotificationService
{
    private const INSTRUMENTIST_INVITATION_PATH = '/complete-account';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly EmailService $emailService,
        #[Autowire('%env(string:FRONTEND_URL)%')]
        private readonly string $frontendUrl,
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