<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'site_group_membership', uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_site_group_site', columns: ['site_group_id', 'site_id'])])]
#[ORM\Index(columns: ['site_id'], name: 'idx_site_group_membership_site')]
class SiteGroupMembership
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'memberships')]
    #[ORM\JoinColumn(name: 'site_group_id', nullable: false)]
    private ?SiteGroup $group = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Hospital $site = null;

    public function getId(): ?int { return $this->id; }

    public function getGroup(): ?SiteGroup { return $this->group; }
    public function setGroup(?SiteGroup $group): static { $this->group = $group; return $this; }

    public function getSite(): ?Hospital { return $this->site; }
    public function setSite(?Hospital $site): static { $this->site = $site; return $this; }
}
