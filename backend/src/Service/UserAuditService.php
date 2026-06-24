<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\UserAuditEvent;
use App\Enum\UserAuditEventType;
use Doctrine\ORM\EntityManagerInterface;

class UserAuditService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function userCreated(User $actor, User $target): void
    {
        $this->persist(
            actor: $actor,
            target: $target,
            type: UserAuditEventType::USER_CREATED,
            description: sprintf('Compte créé pour %s (%s)', $target->getEmail(), $this->roleLabel($target)),
            payload: ['email' => $target->getEmail(), 'roles' => $target->getRoles()],
        );
    }

    public function userInvitationSent(User $actor, User $target): void
    {
        $this->persist(
            actor: $actor,
            target: $target,
            type: UserAuditEventType::USER_INVITATION_SENT,
            description: sprintf('Invitation envoyée à %s', $target->getEmail()),
        );
    }

    public function userInvitationResent(User $actor, User $target): void
    {
        $this->persist(
            actor: $actor,
            target: $target,
            type: UserAuditEventType::USER_INVITATION_RESENT,
            description: sprintf('Invitation renvoyée à %s', $target->getEmail()),
        );
    }

    public function userInvitationCompleted(User $target): void
    {
        $this->persist(
            actor: $target,
            target: $target,
            type: UserAuditEventType::USER_INVITATION_COMPLETED,
            description: sprintf('%s a complété son compte', $target->getEmail()),
        );
    }

    public function userSuspended(User $actor, User $target): void
    {
        $this->persist(
            actor: $actor,
            target: $target,
            type: UserAuditEventType::USER_SUSPENDED,
            description: sprintf('Compte de %s suspendu', $target->getEmail()),
        );
    }

    public function userReactivated(User $actor, User $target): void
    {
        $this->persist(
            actor: $actor,
            target: $target,
            type: UserAuditEventType::USER_REACTIVATED,
            description: sprintf('Compte de %s réactivé', $target->getEmail()),
        );
    }

    public function userRoleChanged(User $actor, User $target, string $oldRole, string $newRole): void
    {
        $this->persist(
            actor: $actor,
            target: $target,
            type: UserAuditEventType::USER_ROLE_CHANGED,
            description: sprintf(
                'Rôle de %s changé de %s vers %s',
                $target->getEmail(),
                $oldRole,
                $newRole,
            ),
            payload: ['oldRole' => $oldRole, 'newRole' => $newRole],
        );
    }

    public function userSiteAdded(User $actor, User $target, string $siteName): void
    {
        $this->persist(
            actor: $actor,
            target: $target,
            type: UserAuditEventType::USER_SITE_ADDED,
            description: sprintf('Site "%s" ajouté pour %s', $siteName, $target->getEmail()),
            payload: ['siteName' => $siteName],
        );
    }

    public function userSiteRemoved(User $actor, User $target, string $siteName): void
    {
        $this->persist(
            actor: $actor,
            target: $target,
            type: UserAuditEventType::USER_SITE_REMOVED,
            description: sprintf('Site "%s" retiré pour %s', $siteName, $target->getEmail()),
            payload: ['siteName' => $siteName],
        );
    }

    /** No single target — concerns N people at once, listed in the payload. */
    public function absencesRequestSent(User $actor, int $recipientCount): void
    {
        $this->persist(
            actor: $actor,
            target: null,
            type: UserAuditEventType::ABSENCES_REQUEST_SENT,
            description: sprintf('Demande de congés envoyée pour %d personne(s) sans absence renseignée', $recipientCount),
            payload: ['count' => $recipientCount],
        );
    }

    /** No single target — concerns N people at once, listed in the payload. */
    public function absencesConfirmationSent(User $actor, int $peopleWithAbsencesCount): void
    {
        $this->persist(
            actor: $actor,
            target: null,
            type: UserAuditEventType::ABSENCES_CONFIRMATION_SENT,
            description: sprintf('Récapitulatif des congés envoyé pour %d personne(s)', $peopleWithAbsencesCount),
            payload: ['count' => $peopleWithAbsencesCount],
        );
    }

    private function persist(
        User $actor,
        ?User $target,
        UserAuditEventType $type,
        string $description,
        ?array $payload = null,
    ): void {
        $evt = new UserAuditEvent();
        $evt
            ->setActor($actor)
            ->setTargetUser($target)
            ->setEventType($type)
            ->setDescription($description)
            ->setPayload($payload);

        $this->em->persist($evt);
        // flush géré par l'appelant (UserAdministrationService ou InvitationController)
    }

    private function roleLabel(User $user): string
    {
        $roles = $user->getRoles();
        if (in_array('ROLE_ADMIN', $roles, true)) return 'Admin';
        if (in_array('ROLE_MANAGER', $roles, true)) return 'Manager';
        if (in_array('ROLE_SURGEON', $roles, true)) return 'Chirurgien';
        if (in_array('ROLE_INSTRUMENTIST', $roles, true)) return 'Instrumentiste';
        return 'Inconnu';
    }
}
