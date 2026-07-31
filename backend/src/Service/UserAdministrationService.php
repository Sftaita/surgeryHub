<?php

namespace App\Service;

use App\Dto\Request\AdminCreateUserRequest;
use App\Entity\Hospital;
use App\Entity\SiteMembership;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UserAdministrationService
{
    private const INVITATION_TTL_HOURS = 48;

    /**
     * Anti-spam guard for resendInvitation() (Lot 7, audit PWA/mobile/admin
     * 2026-07-29) — previously an admin could resend in a tight loop (double-click,
     * repeated clicks), each call invalidating the previous token before the
     * instrumentist had any chance to use it.
     */
    private const RESEND_INVITATION_COOLDOWN_SECONDS = 60;

    private const ROLE_TO_SITE_ROLE = [
        'ROLE_INSTRUMENTIST' => 'INSTRUMENTIST',
        'ROLE_SURGEON'       => 'SURGEON',
        'ROLE_MANAGER'       => 'MANAGER',
    ];

    /**
     * Roles that must always keep at least one SiteMembership — both at creation and
     * for every subsequent removal. MANAGER and ADMIN are intentionally absent: they
     * may have zero, one, or several sites, never required.
     */
    private const ROLES_REQUIRING_SITE = ['ROLE_INSTRUMENTIST', 'ROLE_SURGEON'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly NotificationService $notifications,
        private readonly UserAuditService $audit,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Crée un utilisateur (instrumentiste, chirurgien ou manager) et envoie l'invitation.
     */
    public function createUser(AdminCreateUserRequest $dto, User $admin): User
    {
        $email = mb_strtolower(trim((string) $dto->email));
        if ($this->users->findOneByEmailInsensitive($email) !== null) {
            throw new ConflictHttpException('Email already used');
        }

        $role = (string) $dto->role;
        if (!isset(self::ROLE_TO_SITE_ROLE[$role])) {
            throw new BadRequestHttpException('Invalid role');
        }
        $siteRole = self::ROLE_TO_SITE_ROLE[$role];

        if (in_array($role, self::ROLES_REQUIRING_SITE, true) && count($dto->siteIds) === 0) {
            throw new BadRequestHttpException(sprintf(
                'At least one site is required for role %s.',
                $role,
            ));
        }

        $sites = $this->resolveSites($dto->siteIds);

        $user = new User();
        $user
            ->setEmail($email)
            ->setFirstname($dto->firstname !== '' ? $dto->firstname : null)
            ->setLastname($dto->lastname !== '' ? $dto->lastname : null)
            ->setPhone($dto->phone !== '' ? $dto->phone : null)
            ->setActive(true)
            ->setRoles([$role])
            ->setInvitationToken(bin2hex(random_bytes(32)))
            ->setInvitationExpiresAt(
                new \DateTimeImmutable(sprintf('+%d hours', self::INVITATION_TTL_HOURS))
            );

        foreach ($sites as $site) {
            $membership = new SiteMembership();
            $membership->setSite($site)->setUser($user)->setSiteRole($siteRole);
            $this->em->persist($membership);
        }

        $this->em->persist($user);

        $this->audit->userCreated($admin, $user);
        $this->em->flush();

        try {
            $this->notifications->sendUserInvitation($user);
            $this->audit->userInvitationSent($admin, $user);
            $this->em->flush();
        } catch (\Throwable $e) {
            // email failure never rolls back creation — invitationLastSentAt stays null → status "email_not_sent"
            $this->logger->warning('Could not queue invitation email for user {email}: {error}', [
                'email' => $user->getEmail(),
                'error' => $e->getMessage(),
            ]);
        }

        return $user;
    }

    /**
     * Suspend un utilisateur. Idempotent : si déjà suspendu, ne fait rien.
     */
    public function suspendUser(User $target, User $admin): User
    {
        if (!$target->isActive()) {
            return $target;
        }

        $target->setActive(false);
        $this->audit->userSuspended($admin, $target);
        $this->em->flush();

        return $target;
    }

    /**
     * Réactive un utilisateur. Idempotent : si déjà actif, ne fait rien.
     */
    public function activateUser(User $target, User $admin): User
    {
        if ($target->isActive()) {
            return $target;
        }

        $target->setActive(true);
        $this->audit->userReactivated($admin, $target);
        $this->em->flush();

        return $target;
    }

    /**
     * Change le rôle d'un utilisateur.
     * Met également à jour le siteRole de toutes ses SiteMembership existantes.
     * L'ADMIN ne peut pas changer son propre rôle.
     */
    public function changeRole(User $target, string $newRole, User $admin): User
    {
        // ROLE_ADMIN is accepted as a target despite being absent from ROLE_TO_SITE_ROLE
        // (Lot 7, audit PWA/mobile/admin 2026-07-29 — this endpoint previously rejected
        // it outright, leaving no controlled path to promote an existing account to
        // admin other than a manual SQL edit). It has no canonical site-role label, same
        // as today's "sites optional, never required" status for MANAGER/ADMIN in
        // ROLES_REQUIRING_SITE below — see the site-membership handling further down.
        if ($newRole !== 'ROLE_ADMIN' && !isset(self::ROLE_TO_SITE_ROLE[$newRole])) {
            throw new BadRequestHttpException('Invalid role: '.$newRole);
        }

        if ($target->getId() === $admin->getId()) {
            throw new BadRequestHttpException('An admin cannot change their own role.');
        }

        if (in_array($newRole, self::ROLES_REQUIRING_SITE, true) && count($target->getSiteMemberships()) === 0) {
            throw new BadRequestHttpException(sprintf(
                'Cannot change role to %s — the user has no site, and at least one is required for this role.',
                $newRole,
            ));
        }

        $oldRoles = $target->getRoles();
        $oldRole  = $this->extractBusinessRole($oldRoles);

        // setRoles() on the existing, managed $target entity — an UPDATE on flush, never
        // an INSERT, so this can never create a duplicate user for the same account.
        $target->setRoles([$newRole]);

        // Existing SiteMembership rows are preserved either way (never deleted here) —
        // only their descriptive siteRole label is refreshed, and only for roles that
        // have one. Promoting to ROLE_ADMIN keeps whatever labels the memberships
        // already had rather than inventing a fictitious "ADMIN" site role.
        if (isset(self::ROLE_TO_SITE_ROLE[$newRole])) {
            $newSiteRole = self::ROLE_TO_SITE_ROLE[$newRole];
            foreach ($target->getSiteMemberships() as $membership) {
                $membership->setSiteRole($newSiteRole);
            }
        }

        $this->audit->userRoleChanged($admin, $target, $oldRole, $newRole);
        $this->em->flush();

        return $target;
    }

    /**
     * Régénère le token d'invitation et renvoie l'email.
     * L'ancien token est invalide dès le flush.
     *
     * Concurrence (revue post-rapport, 2026-07-29) : le garde-fou "déjà activé" +
     * l'anti-spam (cooldown) sont vérifiés ET appliqués sous verrou pessimiste
     * (`SELECT ... FOR UPDATE`, transaction courte) — deux requêtes simultanées pour le
     * même utilisateur se sérialisent ici, la seconde relit l'état déjà mis à jour par
     * la première une fois son verrou obtenu, au lieu que les deux passent le contrôle
     * de cooldown avant qu'aucune n'ait écrit (race condition classique
     * check-then-act). Le verrou est relâché (commit) avant l'envoi de l'email : l'I/O
     * lente ne doit jamais retenir un verrou de ligne.
     */
    public function resendInvitation(User $target, User $admin): User
    {
        $shouldSend = false;

        $this->em->wrapInTransaction(function () use ($target, &$shouldSend): void {
            $this->em->lock($target, LockMode::PESSIMISTIC_WRITE);
            // Le verrou peut avoir été obtenu après qu'une requête concurrente a déjà
            // modifié cette ligne — on relit les champs pertinents depuis l'entité
            // managée (Doctrine rafraîchit l'état via le SELECT ... FOR UPDATE ci-dessus)
            // avant de décider, jamais depuis des valeurs capturées avant le verrou.

            if ($target->getPassword() !== null) {
                throw new ConflictHttpException('Account already activated — invitation cannot be resent.');
            }

            $lastSent = $target->getInvitationLastSentAt();
            if ($lastSent !== null) {
                $secondsSinceLastSend = (new \DateTimeImmutable())->getTimestamp() - $lastSent->getTimestamp();
                if ($secondsSinceLastSend < self::RESEND_INVITATION_COOLDOWN_SECONDS) {
                    throw new ConflictHttpException(sprintf(
                        'Invitation was already resent %d second(s) ago — please wait at least %d seconds between resends.',
                        max(0, $secondsSinceLastSend),
                        self::RESEND_INVITATION_COOLDOWN_SECONDS,
                    ));
                }
            }

            $target
                ->setInvitationToken(bin2hex(random_bytes(32)))
                ->setInvitationExpiresAt(
                    new \DateTimeImmutable(sprintf('+%d hours', self::INVITATION_TTL_HOURS))
                );

            $shouldSend = true;
            // wrapInTransaction() flushes + commits (releasing the lock) right after this
            // closure returns — no explicit flush()/commit needed here.
        });

        if ($shouldSend) {
            try {
                $this->notifications->sendUserInvitation($target);
                $this->audit->userInvitationResent($admin, $target);
                $this->em->flush();
            } catch (\Throwable $e) {
                // Mirrors createUser()'s resilience (see above): the regenerated token
                // stays valid so the admin can simply retry — never rolled back just
                // because the email transport failed. sendUserInvitation() only sets
                // invitationLastSentAt after a successful send, so the cooldown above
                // never blocks a retry caused by a transient mail failure.
                $this->logger->warning('Could not resend invitation email for user {email}: {error}', [
                    'email' => $target->getEmail(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $target;
    }

    /**
     * Ajoute une affiliation site. Vérifie l'absence de doublon.
     */
    public function addSiteMembership(User $target, int $siteId, User $admin): SiteMembership
    {
        $site = $this->em->find(Hospital::class, $siteId);
        if (!$site instanceof Hospital) {
            throw new NotFoundHttpException('Site not found');
        }

        foreach ($target->getSiteMemberships() as $existing) {
            if ($existing->getSite()?->getId() === $site->getId()) {
                throw new ConflictHttpException('Site membership already exists');
            }
        }

        $siteRole = self::ROLE_TO_SITE_ROLE[$this->extractRoleConstant($target)] ?? 'INSTRUMENTIST';

        $membership = new SiteMembership();
        $membership->setSite($site)->setUser($target)->setSiteRole($siteRole);
        $this->em->persist($membership);

        $this->audit->userSiteAdded($admin, $target, (string) $site->getName());
        $this->em->flush();

        return $membership;
    }

    /**
     * Supprime une affiliation site.
     */
    public function removeSiteMembership(User $target, int $membershipId, User $admin): void
    {
        $membership = null;
        foreach ($target->getSiteMemberships() as $m) {
            if ($m->getId() === $membershipId) {
                $membership = $m;
                break;
            }
        }

        if ($membership === null) {
            throw new NotFoundHttpException('Site membership not found');
        }

        $businessRole = $this->extractRoleConstant($target);
        if (in_array($businessRole, self::ROLES_REQUIRING_SITE, true) && count($target->getSiteMemberships()) <= 1) {
            throw new ConflictHttpException(sprintf(
                'Cannot remove the last site of a %s — at least one site is required.',
                $businessRole,
            ));
        }

        $siteName = $membership->getSite()?->getName() ?? 'Inconnu';

        $this->em->remove($membership);
        $this->audit->userSiteRemoved($admin, $target, $siteName);
        $this->em->flush();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param list<int> $siteIds
     * @return list<Hospital>
     */
    private function resolveSites(array $siteIds): array
    {
        $sites = [];
        foreach ($siteIds as $siteId) {
            $site = $this->em->find(Hospital::class, $siteId);
            if (!$site instanceof Hospital) {
                throw new NotFoundHttpException(sprintf('Site not found: %d', $siteId));
            }
            $sites[] = $site;
        }
        return $sites;
    }

    private function extractBusinessRole(array $roles): string
    {
        if (in_array('ROLE_ADMIN', $roles, true)) return 'ROLE_ADMIN';
        if (in_array('ROLE_MANAGER', $roles, true)) return 'ROLE_MANAGER';
        if (in_array('ROLE_SURGEON', $roles, true)) return 'ROLE_SURGEON';
        if (in_array('ROLE_INSTRUMENTIST', $roles, true)) return 'ROLE_INSTRUMENTIST';
        return 'ROLE_USER';
    }

    private function extractRoleConstant(User $user): string
    {
        return $this->extractBusinessRole($user->getRoles());
    }

    public static function computeInvitationStatus(User $user): string
    {
        if ($user->getPassword() !== null) {
            return 'used';
        }
        if ($user->getInvitationToken() === null) {
            return 'none';
        }
        if ($user->getInvitationLastSentAt() === null) {
            return 'email_not_sent';
        }
        $expiresAt = $user->getInvitationExpiresAt();
        if ($expiresAt !== null && $expiresAt <= new \DateTimeImmutable()) {
            return 'expired';
        }
        return 'pending';
    }
}
