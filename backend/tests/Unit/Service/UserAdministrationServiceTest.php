<?php

namespace App\Tests\Unit\Service;

use App\Dto\Request\AdminCreateUserRequest;
use App\Entity\Hospital;
use App\Entity\SiteMembership;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use App\Service\UserAdministrationService;
use App\Service\UserAuditService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class UserAdministrationServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private UserRepository&MockObject          $users;
    private NotificationService&MockObject     $notifications;
    private UserAuditService&MockObject        $audit;
    private LoggerInterface&MockObject         $logger;
    private UserAdministrationService          $service;

    protected function setUp(): void
    {
        $this->em            = $this->createMock(EntityManagerInterface::class);
        $this->users         = $this->createMock(UserRepository::class);
        $this->notifications = $this->createMock(NotificationService::class);
        $this->audit         = $this->createMock(UserAuditService::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->service = new UserAdministrationService(
            $this->em,
            $this->users,
            $this->notifications,
            $this->audit,
            $this->logger,
        );
    }

    // ── createUser ────────────────────────────────────────────────────────────

    public function testCreateUserSucceeds(): void
    {
        $site  = $this->buildSite(1, 'CHU Liège');
        $admin = $this->buildAdmin();
        $dto   = $this->buildCreateDto('new@example.com', 'ROLE_INSTRUMENTIST', [1]);

        $this->users->method('findOneByEmailInsensitive')->willReturn(null);
        $this->em->method('find')->with(Hospital::class, 1)->willReturn($site);
        $this->em->expects(self::atLeastOnce())->method('persist');
        $this->em->expects(self::atLeastOnce())->method('flush');
        $this->notifications->expects(self::once())->method('sendUserInvitation');
        $this->audit->expects(self::once())->method('userCreated');
        $this->audit->expects(self::once())->method('userInvitationSent');

        $user = $this->service->createUser($dto, $admin);

        self::assertSame('new@example.com', $user->getEmail());
        self::assertContains('ROLE_INSTRUMENTIST', $user->getRoles());
        self::assertTrue($user->isActive());
    }

    public function testCreateUserThrowsConflictWhenEmailAlreadyUsed(): void
    {
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Email already used');

        $admin = $this->buildAdmin();
        $dto   = $this->buildCreateDto('existing@example.com', 'ROLE_SURGEON', [1]);
        $this->users->method('findOneByEmailInsensitive')->willReturn(new User());

        $this->service->createUser($dto, $admin);
    }

    public function testCreateUserThrowsNotFoundWhenSiteDoesNotExist(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $admin = $this->buildAdmin();
        $dto   = $this->buildCreateDto('ok@example.com', 'ROLE_SURGEON', [999]);
        $this->users->method('findOneByEmailInsensitive')->willReturn(null);
        $this->em->method('find')->with(Hospital::class, 999)->willReturn(null);

        $this->service->createUser($dto, $admin);
    }

    public function testCreateUserDoesNotRollbackWhenEmailFails(): void
    {
        $site  = $this->buildSite(1, 'CHU');
        $admin = $this->buildAdmin();
        $dto   = $this->buildCreateDto('good@example.com', 'ROLE_MANAGER', [1]);

        $this->users->method('findOneByEmailInsensitive')->willReturn(null);
        $this->em->method('find')->willReturn($site);
        $this->em->method('persist');
        $this->em->method('flush');
        $this->notifications->method('sendUserInvitation')->willThrowException(new \RuntimeException('SMTP down'));
        $this->audit->expects(self::once())->method('userCreated');
        $this->audit->expects(self::never())->method('userInvitationSent');
        $this->logger->expects(self::once())->method('warning');

        $user = $this->service->createUser($dto, $admin);

        self::assertSame('good@example.com', $user->getEmail());
        self::assertNull($user->getInvitationLastSentAt());
    }

    public function testCreateUserThrowsBadRequestWhenInstrumentistHasNoSite(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('At least one site is required for role ROLE_INSTRUMENTIST.');

        $admin = $this->buildAdmin();
        $dto   = $this->buildCreateDto('new@example.com', 'ROLE_INSTRUMENTIST', []);
        $this->users->method('findOneByEmailInsensitive')->willReturn(null);

        $this->service->createUser($dto, $admin);
    }

    public function testCreateUserThrowsBadRequestWhenSurgeonHasNoSite(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('At least one site is required for role ROLE_SURGEON.');

        $admin = $this->buildAdmin();
        $dto   = $this->buildCreateDto('new@example.com', 'ROLE_SURGEON', []);
        $this->users->method('findOneByEmailInsensitive')->willReturn(null);

        $this->service->createUser($dto, $admin);
    }

    public function testCreateUserSucceedsForManagerWithoutSite(): void
    {
        $admin = $this->buildAdmin();
        $dto   = $this->buildCreateDto('manager@example.com', 'ROLE_MANAGER', []);

        $this->users->method('findOneByEmailInsensitive')->willReturn(null);
        $this->em->expects(self::atLeastOnce())->method('persist');
        $this->em->expects(self::atLeastOnce())->method('flush');

        $user = $this->service->createUser($dto, $admin);

        self::assertContains('ROLE_MANAGER', $user->getRoles());
        self::assertCount(0, $user->getSiteMemberships());
    }

    public function testCreateUserSucceedsForManagerWithMultipleSites(): void
    {
        $site1 = $this->buildSite(1, 'CHU Liège');
        $site2 = $this->buildSite(2, 'Saint-Jean');
        $admin = $this->buildAdmin();
        $dto   = $this->buildCreateDto('manager@example.com', 'ROLE_MANAGER', [1, 2]);

        $this->users->method('findOneByEmailInsensitive')->willReturn(null);
        $this->em->method('find')->willReturnMap([
            [Hospital::class, 1, $site1],
            [Hospital::class, 2, $site2],
        ]);

        $user = $this->service->createUser($dto, $admin);

        self::assertContains('ROLE_MANAGER', $user->getRoles());
    }

    // ── suspendUser / activateUser ─────────────────────────────────────────────

    public function testSuspendUserSetsActiveToFalse(): void
    {
        $admin  = $this->buildAdmin();
        $target = $this->buildUser(['ROLE_INSTRUMENTIST']);
        $target->setActive(true);

        $this->em->expects(self::once())->method('flush');
        $this->audit->expects(self::once())->method('userSuspended');

        $result = $this->service->suspendUser($target, $admin);

        self::assertFalse($result->isActive());
    }

    public function testSuspendUserIsIdempotentWhenAlreadySuspended(): void
    {
        $admin  = $this->buildAdmin();
        $target = $this->buildUser(['ROLE_INSTRUMENTIST']);
        $target->setActive(false);

        $this->em->expects(self::never())->method('flush');
        $this->audit->expects(self::never())->method('userSuspended');

        $result = $this->service->suspendUser($target, $admin);

        self::assertFalse($result->isActive());
    }

    public function testActivateUserSetsActiveToTrue(): void
    {
        $admin  = $this->buildAdmin();
        $target = $this->buildUser(['ROLE_INSTRUMENTIST']);
        $target->setActive(false);

        $this->em->expects(self::once())->method('flush');
        $this->audit->expects(self::once())->method('userReactivated');

        $result = $this->service->activateUser($target, $admin);

        self::assertTrue($result->isActive());
    }

    // ── changeRole ────────────────────────────────────────────────────────────

    public function testChangeRoleUpdatesUserAndMemberships(): void
    {
        $admin  = $this->buildAdmin();
        $target = $this->buildUserWithId(42, ['ROLE_INSTRUMENTIST']);

        $site       = $this->buildSite(1, 'CHU');
        $membership = new SiteMembership();
        $membership->setSite($site)->setUser($target)->setSiteRole('INSTRUMENTIST');

        // Simulate having a membership via the collection
        $target->addSiteMembership($membership);

        $this->em->expects(self::once())->method('flush');
        $this->audit->expects(self::once())->method('userRoleChanged');

        $result = $this->service->changeRole($target, 'ROLE_SURGEON', $admin);

        self::assertContains('ROLE_SURGEON', $result->getRoles());
        self::assertSame('SURGEON', $membership->getSiteRole());
    }

    public function testChangeRoleThrowsWhenAdminChangesOwnRole(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('cannot change their own role');

        $admin = $this->buildUserWithId(1, ['ROLE_ADMIN']);
        $this->service->changeRole($admin, 'ROLE_MANAGER', $admin);
    }

    public function testChangeRoleThrowsOnInvalidRole(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $admin  = $this->buildAdmin();
        $target = $this->buildUserWithId(2, ['ROLE_INSTRUMENTIST']);
        $this->service->changeRole($target, 'ROLE_ADMIN', $admin);
    }

    public function testChangeRoleThrowsWhenTargetHasNoSiteAndNewRoleRequiresSite(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('the user has no site');

        $admin  = $this->buildAdmin();
        $target = $this->buildUserWithId(2, ['ROLE_MANAGER']);

        $this->service->changeRole($target, 'ROLE_SURGEON', $admin);
    }

    public function testChangeRoleSucceedsToManagerEvenWithoutSite(): void
    {
        $admin  = $this->buildAdmin();
        $target = $this->buildUserWithId(2, ['ROLE_INSTRUMENTIST']);

        $this->em->expects(self::once())->method('flush');
        $this->audit->expects(self::once())->method('userRoleChanged');

        $result = $this->service->changeRole($target, 'ROLE_MANAGER', $admin);

        self::assertContains('ROLE_MANAGER', $result->getRoles());
    }

    // ── resendInvitation ──────────────────────────────────────────────────────

    public function testResendInvitationThrowsConflictWhenAccountActivated(): void
    {
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('already activated');

        $admin  = $this->buildAdmin();
        $target = $this->buildUser(['ROLE_INSTRUMENTIST']);
        $target->setPassword('$hashed');

        $this->service->resendInvitation($target, $admin);
    }

    public function testResendInvitationRegeneratesToken(): void
    {
        $admin  = $this->buildAdmin();
        $target = $this->buildUser(['ROLE_INSTRUMENTIST']);
        $target->setInvitationToken('old-token');
        $target->setInvitationExpiresAt(new \DateTimeImmutable('-1 hour'));

        $this->em->expects(self::atLeastOnce())->method('flush');
        $this->notifications->expects(self::once())->method('sendUserInvitation');
        $this->audit->expects(self::once())->method('userInvitationResent');

        $result = $this->service->resendInvitation($target, $admin);

        self::assertNotSame('old-token', $result->getInvitationToken());
        self::assertNotNull($result->getInvitationExpiresAt());
        self::assertGreaterThan(new \DateTimeImmutable(), $result->getInvitationExpiresAt());
    }

    // ── computeInvitationStatus ───────────────────────────────────────────────

    public function testComputeInvitationStatusUsed(): void
    {
        $user = new User();
        $user->setPassword('$2y$...');
        self::assertSame('used', UserAdministrationService::computeInvitationStatus($user));
    }

    public function testComputeInvitationStatusNone(): void
    {
        $user = new User();
        self::assertSame('none', UserAdministrationService::computeInvitationStatus($user));
    }

    public function testComputeInvitationStatusEmailNotSent(): void
    {
        $user = new User();
        $user->setInvitationToken('abc123');
        self::assertSame('email_not_sent', UserAdministrationService::computeInvitationStatus($user));
    }

    public function testComputeInvitationStatusExpired(): void
    {
        $user = new User();
        $user->setInvitationToken('abc123');
        $user->setInvitationLastSentAt(new \DateTimeImmutable('-49 hours'));
        $user->setInvitationExpiresAt(new \DateTimeImmutable('-1 hour'));
        self::assertSame('expired', UserAdministrationService::computeInvitationStatus($user));
    }

    public function testComputeInvitationStatusPending(): void
    {
        $user = new User();
        $user->setInvitationToken('abc123');
        $user->setInvitationLastSentAt(new \DateTimeImmutable());
        $user->setInvitationExpiresAt(new \DateTimeImmutable('+47 hours'));
        self::assertSame('pending', UserAdministrationService::computeInvitationStatus($user));
    }

    // ── addSiteMembership / removeSiteMembership ──────────────────────────────

    public function testAddSiteMembershipThrowsConflictWhenAlreadyExists(): void
    {
        $this->expectException(ConflictHttpException::class);

        $admin  = $this->buildAdmin();
        $site   = $this->buildSite(1, 'CHU');
        $target = $this->buildUser(['ROLE_INSTRUMENTIST']);

        $membership = new SiteMembership();
        $membership->setSite($site)->setUser($target)->setSiteRole('INSTRUMENTIST');
        $target->addSiteMembership($membership);

        $this->em->method('find')->with(Hospital::class, 1)->willReturn($site);

        $this->service->addSiteMembership($target, 1, $admin);
    }

    public function testRemoveSiteMembershipThrowsNotFoundWhenMembershipUnknown(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $admin  = $this->buildAdmin();
        $target = $this->buildUser(['ROLE_INSTRUMENTIST']);

        $this->service->removeSiteMembership($target, 999, $admin);
    }

    public function testRemoveSiteMembershipThrowsConflictWhenLastSiteForInstrumentist(): void
    {
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('at least one site is required');

        $admin  = $this->buildAdmin();
        $site   = $this->buildSite(1, 'CHU');
        $target = $this->buildUser(['ROLE_INSTRUMENTIST']);

        $membership = $this->buildMembershipWithId(5, $site, $target, 'INSTRUMENTIST');
        $target->addSiteMembership($membership);

        $this->service->removeSiteMembership($target, 5, $admin);
    }

    public function testRemoveSiteMembershipSucceedsForManagerEvenWhenLastSite(): void
    {
        $admin  = $this->buildAdmin();
        $site   = $this->buildSite(1, 'CHU');
        $target = $this->buildUser(['ROLE_MANAGER']);

        $membership = $this->buildMembershipWithId(5, $site, $target, 'MANAGER');
        $target->addSiteMembership($membership);

        $this->em->expects(self::once())->method('flush');
        $this->audit->expects(self::once())->method('userSiteRemoved');

        $this->service->removeSiteMembership($target, 5, $admin);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildAdmin(): User
    {
        return $this->buildUserWithId(1, ['ROLE_ADMIN']);
    }

    private function buildUser(array $roles): User
    {
        $user = new User();
        $user->setRoles($roles)->setEmail('user@test.com')->setActive(true);
        return $user;
    }

    private function buildUserWithId(int $id, array $roles): User
    {
        $user = $this->getMockBuilder(User::class)
            ->onlyMethods(['getId'])
            ->getMock();
        $user->method('getId')->willReturn($id);
        $user->setRoles($roles)->setEmail('user'.$id.'@test.com')->setActive(true);
        return $user;
    }

    private function buildSite(int $id, string $name): Hospital
    {
        $site = $this->getMockBuilder(Hospital::class)
            ->onlyMethods(['getId'])
            ->getMock();
        $site->method('getId')->willReturn($id);
        $site->setName($name);
        return $site;
    }

    private function buildMembershipWithId(int $id, Hospital $site, User $user, string $siteRole): SiteMembership
    {
        $membership = $this->getMockBuilder(SiteMembership::class)
            ->onlyMethods(['getId'])
            ->getMock();
        $membership->method('getId')->willReturn($id);
        $membership->setSite($site)->setUser($user)->setSiteRole($siteRole);
        return $membership;
    }

    private function buildCreateDto(string $email, string $role, array $siteIds): AdminCreateUserRequest
    {
        $dto           = new AdminCreateUserRequest();
        $dto->email    = $email;
        $dto->firstname = 'Test';
        $dto->lastname  = 'User';
        $dto->role     = $role;
        $dto->siteIds  = $siteIds;
        return $dto;
    }
}
