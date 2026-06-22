<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\SiteGroup;
use App\Entity\User;
use App\Service\SiteGroupService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class SiteGroupServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private array $persisted = [];
    private array $removed   = [];

    private static int $idSeq = 0;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->persisted = [];
        $this->removed   = [];
        $this->em->method('persist')->willReturnCallback(function (object $e): void { $this->persisted[] = $e; });
        $this->em->method('remove')->willReturnCallback(function (object $e): void { $this->removed[] = $e; });
    }

    private function makeService(): SiteGroupService
    {
        return new SiteGroupService($this->em);
    }

    private function makeUser(): User
    {
        $u = new User();
        $u->setEmail('manager@test.com');
        $rp = new \ReflectionProperty(User::class, 'id');
        $rp->setValue($u, ++self::$idSeq);
        return $u;
    }

    private function makeSite(string $name = 'Alpha'): Hospital
    {
        $h = new Hospital();
        $h->setName($name);
        $rp = new \ReflectionProperty(Hospital::class, 'id');
        $rp->setValue($h, ++self::$idSeq);
        return $h;
    }

    public function test_create_persists_group_with_name_and_creator(): void
    {
        $manager = $this->makeUser();
        $group = $this->makeService()->create('North Region', $manager);

        $this->assertSame('North Region', $group->getName());
        $this->assertSame($manager, $group->getCreatedBy());
        $this->assertContains($group, $this->persisted);
    }

    public function test_create_rejects_empty_name(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->makeService()->create('   ', $this->makeUser());
    }

    public function test_rename_updates_name(): void
    {
        $group = $this->makeService()->create('Old Name', $this->makeUser());
        $this->makeService()->rename($group, 'New Name');

        $this->assertSame('New Name', $group->getName());
    }

    public function test_rename_rejects_empty_name(): void
    {
        $group = $this->makeService()->create('Name', $this->makeUser());

        $this->expectException(BadRequestHttpException::class);
        $this->makeService()->rename($group, '');
    }

    public function test_delete_removes_group(): void
    {
        $group = $this->makeService()->create('To Delete', $this->makeUser());
        $this->makeService()->delete($group);

        $this->assertContains($group, $this->removed);
    }

    public function test_add_site_creates_membership(): void
    {
        $group = $this->makeService()->create('Group', $this->makeUser());
        $site  = $this->makeSite();

        $membership = $this->makeService()->addSite($group, $site);

        $this->assertSame($site, $membership->getSite());
        $this->assertCount(1, $group->getMemberships());
    }

    public function test_add_site_is_idempotent(): void
    {
        $group = $this->makeService()->create('Group', $this->makeUser());
        $site  = $this->makeSite();
        $service = $this->makeService();

        $first  = $service->addSite($group, $site);
        $second = $service->addSite($group, $site);

        $this->assertSame($first, $second);
        $this->assertCount(1, $group->getMemberships(), 'Adding the same site twice must not duplicate the membership');
    }

    public function test_remove_site_removes_membership(): void
    {
        $group = $this->makeService()->create('Group', $this->makeUser());
        $site  = $this->makeSite();
        $service = $this->makeService();
        $service->addSite($group, $site);

        $service->removeSite($group, $site);

        $this->assertCount(0, $group->getMemberships());
    }

    public function test_remove_site_not_a_member_is_a_noop(): void
    {
        $group = $this->makeService()->create('Group', $this->makeUser());
        $site  = $this->makeSite();

        $this->makeService()->removeSite($group, $site);

        $this->assertCount(0, $group->getMemberships());
        $this->assertSame([], $this->removed);
    }
}
