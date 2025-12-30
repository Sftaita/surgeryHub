<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'site_membership', uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_site_user', columns: ['site_id', 'user_id'])])]
#[ORM\HasLifecycleCallbacks]
class SiteMembership
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'memberships')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Hospital $site = null;

    #[ORM\ManyToOne(inversedBy: 'siteMemberships')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 50)]
    private ?string $siteRole = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSite(): ?Hospital
    {
        return $this->site;
    }

    public function setSite(?Hospital $site): static
    {
        $this->site = $site;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getSiteRole(): ?string
    {
        return $this->siteRole;
    }

    public function setSiteRole(string $siteRole): static
    {
        $this->siteRole = $siteRole;

        return $this;
    }
}
