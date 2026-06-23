<?php

namespace App\Tests\Unit\Service;

use App\Dto\Request\AddInstrumentistSiteMembershipRequest;
use App\Dto\Request\CreateSurgeonRequest;
use App\Entity\Hospital;
use App\Entity\SiteMembership;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\SurgeonServiceManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SurgeonServiceManagerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private UserRepository&MockObject         $users;
    private SurgeonServiceManager              $service;

    protected function setUp(): void
    {
        $this->em    = $this->createMock(EntityManagerInterface::class);
        $this->users = $this->createMock(UserRepository::class);

        $this->service = new SurgeonServiceManager($this->em, $this->users);
    }

    // ── createSurgeon ─────────────────────────────────────────────────────────

    public function testCreateSurgeonThrowsBadRequestWhenNoSite(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('At least one site is required');

        $dto = $this->buildCreateDto('surgeon@example.com', []);
        $this->users->method('findOneByEmailInsensitive')->willReturn(null);

        $this->service->createSurgeon($dto);
    }

    public function testCreateSurgeonSucceedsWithMultipleSites(): void
    {
        $site1 = $this->buildSite(1, 'Delta');
        $site2 = $this->buildSite(2, 'Saint-Jean');
        $dto   = $this->buildCreateDto('surgeon@example.com', [1, 2]);

        $this->users->method('findOneByEmailInsensitive')->willReturn(null);
        $this->em->method('find')->willReturnMap([
            [Hospital::class, 1, $site1],
            [Hospital::class, 2, $site2],
        ]);
        $this->em->expects(self::atLeastOnce())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $surgeon = $this->service->createSurgeon($dto);

        self::assertContains('ROLE_SURGEON', $surgeon->getRoles());
        self::assertCount(2, $surgeon->getSiteMemberships());
    }

    public function testCreateSurgeonThrowsConflictWhenEmailAlreadyUsed(): void
    {
        $this->expectException(ConflictHttpException::class);

        $dto = $this->buildCreateDto('existing@example.com', [1]);
        $this->users->method('findOneByEmailInsensitive')->willReturn(new User());

        $this->service->createSurgeon($dto);
    }

    // ── deleteSiteMembership ─────────────────────────────────────────────────

    public function testDeleteSiteMembershipThrowsConflictWhenLastSite(): void
    {
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('at least one site is required');

        $surgeon    = $this->buildUserWithId(1, ['ROLE_SURGEON']);
        $site       = $this->buildSite(1, 'Delta');
        $membership = $this->buildMembershipWithId(5, $site, $surgeon);
        $surgeon->addSiteMembership($membership);

        $this->em->method('find')->with(SiteMembership::class, 5)->willReturn($membership);

        $this->service->deleteSiteMembership($surgeon, 5);
    }

    public function testDeleteSiteMembershipSucceedsWhenMultipleSites(): void
    {
        $surgeon     = $this->buildUserWithId(1, ['ROLE_SURGEON']);
        $site1       = $this->buildSite(1, 'Delta');
        $site2       = $this->buildSite(2, 'Saint-Jean');
        $membership1 = $this->buildMembershipWithId(5, $site1, $surgeon);
        $membership2 = $this->buildMembershipWithId(6, $site2, $surgeon);
        $surgeon->addSiteMembership($membership1);
        $surgeon->addSiteMembership($membership2);

        $this->em->method('find')->with(SiteMembership::class, 5)->willReturn($membership1);
        $this->em->expects(self::once())->method('remove')->with($membership1);
        $this->em->expects(self::once())->method('flush');

        $this->service->deleteSiteMembership($surgeon, 5);

        self::assertCount(1, $surgeon->getSiteMemberships());
    }

    public function testDeleteSiteMembershipThrowsNotFoundWhenUnknown(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $surgeon = $this->buildUserWithId(1, ['ROLE_SURGEON']);
        $this->em->method('find')->with(SiteMembership::class, 999)->willReturn(null);

        $this->service->deleteSiteMembership($surgeon, 999);
    }

    public function testDeleteSiteMembershipThrowsNotFoundWhenMembershipBelongsToAnotherUser(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $surgeon       = $this->buildUserWithId(1, ['ROLE_SURGEON']);
        $otherSurgeon  = $this->buildUserWithId(2, ['ROLE_SURGEON']);
        $site          = $this->buildSite(1, 'Delta');
        $membership    = $this->buildMembershipWithId(5, $site, $otherSurgeon);

        $this->em->method('find')->with(SiteMembership::class, 5)->willReturn($membership);

        $this->service->deleteSiteMembership($surgeon, 5);
    }

    // ── addSiteMembership ────────────────────────────────────────────────────

    public function testAddSiteMembershipThrowsConflictWhenAlreadyExists(): void
    {
        $this->expectException(ConflictHttpException::class);

        $surgeon    = $this->buildUserWithId(1, ['ROLE_SURGEON']);
        $site       = $this->buildSite(1, 'Delta');
        $membership = $this->buildMembershipWithId(5, $site, $surgeon);
        $surgeon->addSiteMembership($membership);

        $this->em->method('find')->with(Hospital::class, 1)->willReturn($site);
        $this->em->method('getRepository')->with(SiteMembership::class)->willReturn(
            $this->buildSiteMembershipRepository($membership)
        );

        $dto = new AddInstrumentistSiteMembershipRequest();
        $dto->siteId = 1;

        $this->service->addSiteMembership($surgeon, $dto);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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

    private function buildMembershipWithId(int $id, Hospital $site, User $user): SiteMembership
    {
        $membership = $this->getMockBuilder(SiteMembership::class)
            ->onlyMethods(['getId'])
            ->getMock();
        $membership->method('getId')->willReturn($id);
        $membership->setSite($site)->setUser($user)->setSiteRole('SURGEON');
        return $membership;
    }

    private function buildSiteMembershipRepository(SiteMembership $membership): object
    {
        $repository = $this->getMockBuilder(\Doctrine\ORM\EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findOneBy'])
            ->getMock();
        $repository->method('findOneBy')->willReturn($membership);
        return $repository;
    }

    private function buildCreateDto(string $email, array $siteIds): CreateSurgeonRequest
    {
        $dto            = new CreateSurgeonRequest();
        $dto->email     = $email;
        $dto->firstname = 'Test';
        $dto->lastname  = 'Surgeon';
        $dto->siteIds   = $siteIds;
        return $dto;
    }
}
