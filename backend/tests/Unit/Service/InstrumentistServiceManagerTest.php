<?php

namespace App\Tests\Unit\Service;

use App\Dto\Request\AddInstrumentistSiteMembershipRequest;
use App\Dto\Request\CreateInstrumentistRequest;
use App\Entity\Hospital;
use App\Entity\SiteMembership;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\InstrumentistServiceManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class InstrumentistServiceManagerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private UserRepository&MockObject         $users;
    private InstrumentistServiceManager        $service;

    protected function setUp(): void
    {
        $this->em    = $this->createMock(EntityManagerInterface::class);
        $this->users = $this->createMock(UserRepository::class);

        $this->service = new InstrumentistServiceManager($this->em, $this->users);
    }

    // ── createInstrumentist ──────────────────────────────────────────────────

    public function testCreateInstrumentistThrowsBadRequestWhenNoSite(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('At least one site is required');

        $dto = $this->buildCreateDto('inst@example.com', []);
        $this->users->method('findOneByEmailInsensitive')->willReturn(null);

        $this->service->createInstrumentist($dto);
    }

    public function testCreateInstrumentistSucceedsWithMultipleSites(): void
    {
        $site1 = $this->buildSite(1, 'Delta');
        $site2 = $this->buildSite(2, 'Saint-Jean');
        $dto   = $this->buildCreateDto('inst@example.com', [1, 2]);

        $this->users->method('findOneByEmailInsensitive')->willReturn(null);
        $this->em->method('find')->willReturnMap([
            [Hospital::class, 1, $site1],
            [Hospital::class, 2, $site2],
        ]);
        $this->em->expects(self::atLeastOnce())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $instrumentist = $this->service->createInstrumentist($dto);

        self::assertContains('ROLE_INSTRUMENTIST', $instrumentist->getRoles());
        self::assertCount(2, $instrumentist->getSiteMemberships());
    }

    public function testCreateInstrumentistThrowsConflictWhenEmailAlreadyUsed(): void
    {
        $this->expectException(ConflictHttpException::class);

        $dto = $this->buildCreateDto('existing@example.com', [1]);
        $this->users->method('findOneByEmailInsensitive')->willReturn(new User());

        $this->service->createInstrumentist($dto);
    }

    // ── deleteSiteMembership ─────────────────────────────────────────────────

    public function testDeleteSiteMembershipThrowsConflictWhenLastSite(): void
    {
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('at least one site is required');

        $instrumentist = $this->buildUserWithId(1, ['ROLE_INSTRUMENTIST']);
        $site          = $this->buildSite(1, 'Delta');
        $membership    = $this->buildMembershipWithId(5, $site, $instrumentist);
        $instrumentist->addSiteMembership($membership);

        $this->em->method('find')->with(SiteMembership::class, 5)->willReturn($membership);

        $this->service->deleteSiteMembership($instrumentist, 5);
    }

    public function testDeleteSiteMembershipSucceedsWhenMultipleSites(): void
    {
        $instrumentist = $this->buildUserWithId(1, ['ROLE_INSTRUMENTIST']);
        $site1         = $this->buildSite(1, 'Delta');
        $site2         = $this->buildSite(2, 'Saint-Jean');
        $membership1   = $this->buildMembershipWithId(5, $site1, $instrumentist);
        $membership2   = $this->buildMembershipWithId(6, $site2, $instrumentist);
        $instrumentist->addSiteMembership($membership1);
        $instrumentist->addSiteMembership($membership2);

        $this->em->method('find')->with(SiteMembership::class, 5)->willReturn($membership1);
        $this->em->expects(self::once())->method('remove')->with($membership1);
        $this->em->expects(self::once())->method('flush');

        $this->service->deleteSiteMembership($instrumentist, 5);

        self::assertCount(1, $instrumentist->getSiteMemberships());
    }

    public function testDeleteSiteMembershipThrowsNotFoundWhenUnknown(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $instrumentist = $this->buildUserWithId(1, ['ROLE_INSTRUMENTIST']);
        $this->em->method('find')->with(SiteMembership::class, 999)->willReturn(null);

        $this->service->deleteSiteMembership($instrumentist, 999);
    }

    public function testDeleteSiteMembershipThrowsNotFoundWhenMembershipBelongsToAnotherUser(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $instrumentist      = $this->buildUserWithId(1, ['ROLE_INSTRUMENTIST']);
        $otherInstrumentist = $this->buildUserWithId(2, ['ROLE_INSTRUMENTIST']);
        $site               = $this->buildSite(1, 'Delta');
        $membership         = $this->buildMembershipWithId(5, $site, $otherInstrumentist);

        $this->em->method('find')->with(SiteMembership::class, 5)->willReturn($membership);

        $this->service->deleteSiteMembership($instrumentist, 5);
    }

    // ── addSiteMembership ────────────────────────────────────────────────────

    public function testAddSiteMembershipThrowsConflictWhenAlreadyExists(): void
    {
        $this->expectException(ConflictHttpException::class);

        $instrumentist = $this->buildUserWithId(1, ['ROLE_INSTRUMENTIST']);
        $site          = $this->buildSite(1, 'Delta');
        $membership    = $this->buildMembershipWithId(5, $site, $instrumentist);
        $instrumentist->addSiteMembership($membership);

        $this->em->method('find')->with(Hospital::class, 1)->willReturn($site);
        $this->em->method('getRepository')->with(SiteMembership::class)->willReturn(
            $this->buildSiteMembershipRepository($membership)
        );

        $dto = new AddInstrumentistSiteMembershipRequest();
        $dto->siteId = 1;

        $this->service->addSiteMembership($instrumentist, $dto);
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
        $membership->setSite($site)->setUser($user)->setSiteRole('INSTRUMENTIST');
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

    private function buildCreateDto(string $email, array $siteIds): CreateInstrumentistRequest
    {
        $dto            = new CreateInstrumentistRequest();
        $dto->email     = $email;
        $dto->firstname = 'Test';
        $dto->lastname  = 'Instrumentist';
        $dto->siteIds   = $siteIds;
        return $dto;
    }
}
