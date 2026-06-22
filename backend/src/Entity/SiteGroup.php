<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
class SiteGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['planning:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Groups(['planning:read'])]
    private string $name;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['planning:read'])]
    private ?User $createdBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['planning:read'])]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, SiteGroupMembership> */
    #[ORM\OneToMany(mappedBy: 'group', targetEntity: SiteGroupMembership::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['planning:read'])]
    private Collection $memberships;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->memberships = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(User $createdBy): static { $this->createdBy = $createdBy; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, SiteGroupMembership> */
    public function getMemberships(): Collection { return $this->memberships; }

    public function addMembership(SiteGroupMembership $membership): static
    {
        if (!$this->memberships->contains($membership)) {
            $this->memberships->add($membership);
            $membership->setGroup($this);
        }
        return $this;
    }

    public function removeMembership(SiteGroupMembership $membership): static
    {
        $this->memberships->removeElement($membership);
        return $this;
    }
}
