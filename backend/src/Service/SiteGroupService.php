<?php

namespace App\Service;

use App\Entity\Hospital;
use App\Entity\SiteGroup;
use App\Entity\SiteGroupMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * CRUD for SiteGroup + its memberships. SiteGroup itself is pure grouping metadata (no
 * Mission/PlanningAlert ever references it directly — only the V2 generator resolves a
 * group to its member site IDs at generation time), so unlike Mission/PlanningAlert this
 * is hard-deletable: there is no historical record that would be orphaned.
 */
class SiteGroupService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /** @return SiteGroup[] */
    public function list(): array
    {
        return $this->em->createQueryBuilder()
            ->select('g')
            ->from(SiteGroup::class, 'g')
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function create(string $name, User $createdBy): SiteGroup
    {
        $name = trim($name);
        if ($name === '') {
            throw new BadRequestHttpException('Le nom du groupe est requis.');
        }

        $group = new SiteGroup();
        $group->setName($name);
        $group->setCreatedBy($createdBy);
        $this->em->persist($group);
        $this->em->flush();

        return $group;
    }

    public function rename(SiteGroup $group, string $name): SiteGroup
    {
        $name = trim($name);
        if ($name === '') {
            throw new BadRequestHttpException('Le nom du groupe est requis.');
        }

        $group->setName($name);
        $this->em->flush();

        return $group;
    }

    public function delete(SiteGroup $group): void
    {
        $this->em->remove($group);
        $this->em->flush();
    }

    /** Idempotent — adding a site already in the group is a no-op, not an error. */
    public function addSite(SiteGroup $group, Hospital $site): SiteGroupMembership
    {
        foreach ($group->getMemberships() as $membership) {
            if ($membership->getSite()->getId() === $site->getId()) {
                return $membership;
            }
        }

        $membership = new SiteGroupMembership();
        $membership->setSite($site);
        $group->addMembership($membership);
        $this->em->persist($membership);
        $this->em->flush();

        return $membership;
    }

    /** Idempotent — removing a site that isn't a member is a no-op, not an error. */
    public function removeSite(SiteGroup $group, Hospital $site): void
    {
        foreach ($group->getMemberships() as $membership) {
            if ($membership->getSite()->getId() === $site->getId()) {
                $group->removeMembership($membership);
                $this->em->remove($membership);
                $this->em->flush();
                return;
            }
        }
    }
}
